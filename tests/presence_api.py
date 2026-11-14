"""Exercise the real presence endpoint using an isolated temporary PHP server/DB."""
import json, os, socket, sqlite3, subprocess, tempfile, time, unittest, urllib.error, urllib.request
from pathlib import Path
ROOT=Path(__file__).resolve().parents[1]
class PresenceAPI(unittest.TestCase):
 @classmethod
 def setUpClass(cls):
  cls.tmp=tempfile.TemporaryDirectory(prefix='soma-presence-api-');cls.base=Path(cls.tmp.name)
  cls.database=cls.base/'test.sqlite'
  with sqlite3.connect(cls.database) as db:
   db.executescript('CREATE TABLE users(id TEXT PRIMARY KEY,email TEXT,display_name TEXT,verified_at TEXT,session_version INTEGER); CREATE TABLE rate_limits(bucket TEXT PRIMARY KEY,window_start INTEGER,hits INTEGER);')
   for name,mark in [('owner','1'),('learner','2'),('unverified','3')]: db.execute('INSERT INTO users VALUES (?,?,?,?,1)',(mark*32,name+'@example.invalid',name,None if name=='unverified' else '2026-11-14'))
  with socket.socket() as sock:sock.bind(('127.0.0.1',0));port=sock.getsockname()[1]
  cls.url=f'http://127.0.0.1:{port}'
  config={'environment':'development','site_url':cls.url,'app_key':'isolated-presence-api-test-key-00000000','dsn':'sqlite:'+str(cls.database)}
  # PHP config serialization without interpolating shell text.
  (cls.base/'config.php').write_text('<?php return json_decode('+json.dumps(json.dumps(config))+',true);')
  (cls.base/'roles.php').write_text("<?php return ['super_admin_emails'=>['owner@example.invalid']];")
  cls.env=dict(os.environ,HISA_CONFIG_PATH=str(cls.base/'config.php'),HISA_CAMPUS_CONFIG_PATH=str(cls.base/'roles.php'))
  cls.sessions={}
  for name,mark in [('owner','1'),('learner','2'),('unverified','3')]:
   code="require "+json.dumps(str(ROOT/'private/bootstrap.php'))+"; session_begin(); $u=query('SELECT * FROM users WHERE id=?',[str_repeat('"+mark+"',32)])->fetch();auth_session($u);echo json_encode(['cookie'=>session_name().'='.session_id(),'csrf'=>$_SESSION['csrf'],'runtime'=>$_SESSION['runtime_id']]);"
   out=subprocess.check_output(['php','-d','session.save_path='+str(cls.base),'-r',code],env=cls.env,text=True)
   cls.sessions[name]=json.loads(out)
  cls.log=open(cls.base/'server.log','w')
  cls.server=subprocess.Popen(['php','-d','session.save_path='+str(cls.base),'-S',f'127.0.0.1:{port}','-t',str(ROOT/'public')],env=cls.env,stdout=cls.log,stderr=cls.log)
  for _ in range(100):
   try:
    with urllib.request.urlopen(cls.url+'/presence.php',timeout=.2):pass
   except urllib.error.HTTPError:break
   except OSError:time.sleep(.03)
  else:raise RuntimeError('Temporary presence server did not start.')
 @classmethod
 def tearDownClass(cls):cls.server.terminate();cls.server.wait(timeout=5);cls.log.close();cls.tmp.cleanup()
 def request(self,name=None,body=None,csrf=True,origin=None,path='/presence.php'):
  headers={}
  if name:headers['Cookie']=self.sessions[name]['cookie']
  if body is not None:
   headers.update({'Content-Type':'application/json','Origin':self.url if origin is None else origin})
   if csrf:headers['X-CSRF-Token']=self.sessions[name]['csrf']
  req=urllib.request.Request(self.url+path,data=json.dumps(body).encode() if body is not None else None,headers=headers)
  try:res=urllib.request.urlopen(req,timeout=5)
  except urllib.error.HTTPError as e:res=e
  return res.status,json.loads(res.read())
 def test_real_endpoint_guards_setup_pulse_status_leave(self):
  for name in (None,'unverified'):
   code,data=self.request(name);self.assertEqual(code,401);self.assertNotIn('csrf',data)
  code,data=self.request('learner',path='/presence.php?ids='+('2'*32)+','+('f'*32))
  self.assertEqual(code,200);self.assertFalse(data['installed']);self.assertEqual(data['online'],{'2'*32:False,'f'*32:False})
  with sqlite3.connect(self.database) as db:self.assertEqual(db.execute("SELECT COUNT(*) FROM sqlite_master WHERE name='school_presence_leases'").fetchone()[0],0)
  self.assertEqual(self.request('learner',{'action':'setup'})[0],403)
  self.assertEqual(self.request('owner',{'action':'setup'},csrf=False)[0],403)
  self.assertEqual(self.request('owner',{'action':'setup'},origin='https://example.invalid')[0],403)
  self.assertEqual(self.request('owner',{'action':'setup'})[0],200)
  lease={'runtime':self.sessions['learner']['runtime'],'tab':'a'*64,'sequence':1,'action':'pulse'}
  before=self.request('learner',path='/session.php')[1]['session']
  self.assertEqual(self.request('learner',dict(lease,runtime='0'*32))[0],400)
  self.assertEqual(self.request('learner',lease,csrf=False)[0],403)
  self.assertEqual(self.request('learner',lease)[1]['online'],True)
  code,data=self.request('learner',path='/presence.php?ids='+('2'*32)+','+('f'*32))
  self.assertEqual(data['online'],{'2'*32:True,'f'*32:False})
  self.assertEqual(set(data),{'verified','csrf','runtime','installed','online'})
  self.assertNotIn('lastSeen',json.dumps(data));self.assertNotIn('expires',json.dumps(data))
  after=self.request('learner',path='/session.php')[1]['session']
  self.assertEqual(before['idleDeadline'],after['idleDeadline']);self.assertEqual(before['absoluteDeadline'],after['absoluteDeadline'])
  self.assertEqual(self.request('learner',dict(lease,action='leave',sequence=3))[0],200)
  self.assertFalse(self.request('learner',dict(lease,sequence=2))[1]['online'])
  self.assertFalse(self.request('learner',path='/presence.php?ids='+('2'*32))[1]['online']['2'*32])
  self.assertEqual(self.request('learner',path='/presence.php?ids='+','.join(['f'*32]*51))[0],400)
if __name__=='__main__':unittest.main()
