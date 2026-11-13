"""Disposable localhost accounts only. No real mail or production data."""
from trusted_fixture import trusted_fixture
import pathlib,json,secrets,time,subprocess,os,urllib.request,urllib.error,http.cookiejar
ROOT=pathlib.Path(__file__).resolve().parents[1];BASE='http://localhost:8791';bank=json.loads((ROOT/'private/legacy-speaking-course.json').read_text())['modules']
f={k:{'email':'speaking-'+k+'-'+str(time.time_ns())+'@example.test','password':secrets.token_urlsafe(24)} for k in ['a','b','unverified']};p=ROOT/'.local/speaking-account-fixture.json';p.write_text(json.dumps(f));p.chmod(0o600)
env={**os.environ,'HISA_CONFIG_PATH':str(ROOT/'.local/full-study-config.php')}
code="require 'private/bootstrap.php';$f=json_decode(file_get_contents('.local/speaking-account-fixture.json'),true);foreach($f as $k=>$v){$id=bin2hex(random_bytes(16));query('INSERT INTO users (id,email,display_name,password_hash,verified_at,session_version,created_at) VALUES (?,?,?,?,?,1,?)',[$id,$v['email'],'Speaking '.$k.' QA',password_hash($v['password'],PASSWORD_DEFAULT),$k==='unverified'?null:gmdate('c'),gmdate('c')]);query('INSERT INTO learning_accounts (user_id,state_json,version,updated_at) VALUES (?,?,1,?)',[$id,json_encode(fresh_state()),gmdate('c')]);}"
subprocess.run(['php','-r',code],cwd=ROOT,env=env,check=True)
class Device:
 def __init__(self,fixture=None):
  self.op=urllib.request.build_opener(urllib.request.HTTPCookieProcessor(http.cookiejar.CookieJar()));self.csrf='';self.email=fixture['email'] if fixture else '';self.call('api.php')
  if fixture:self.call('api.php',{'action':'login',**fixture})
 def call(self,path,body=None,status=200,origin=BASE,token=None):
  if body and body.get('action')=='login':trusted_fixture(self.op,ROOT,BASE,body.get('email'))
  req=urllib.request.Request(BASE+'/'+path,data=json.dumps(body).encode() if body is not None else None,headers={'Content-Type':'application/json','Origin':origin,'X-CSRF-Token':self.csrf if token is None else token})
  try:r=self.op.open(req)
  except urllib.error.HTTPError as e:r=e
  d=json.load(r);assert r.status==status,(r.status,d);self.csrf=d.get('csrf',self.csrf);return d
 def get(self):return self.call('speaking/account.php')['record']
 def post(self,body,status=200,**opts):return self.call('speaking/account.php',{'email':self.email,**body},status,**opts)
a=Device(f['a']);peer=Device(f['a']);b=Device(f['b']);guest=Device();unverified=Device(f['unverified']);assert a.get()['version']==1 and peer.get()==a.get();assert guest.get() is None and unverified.get() is None
baseline=a.call('api.php')['account'];r=a.get()
base={'action':'field','module':0,'field':'notes','value':'Audience: a student group. One useful message.','version':r['version']}
a.post(base,403,origin='https://wrong.example');a.post(base,403,token='wrong');guest.post(base,401);unverified.post(base,403);b.post({**base,'email':a.email},409)
r=a.post(base)['record'];assert peer.get()==r and not b.get()['progress']['modules'];assert r['progress']['modules']['0']['notes']==base['value']
conflict=peer.post({**base,'value':'Stale phone edit'},409);assert conflict['conflict'] and conflict['record']==r and a.get()==r
for field,value in [('passed',True),('notes','x'*5001),('practised','true'),('rubric',{'0':5})]:a.post({'action':'field','module':0,'field':field,'value':value,'version':r['version']},400)
for field,value in [('reflection','I slowed down and paused after my central point.'),('practised',True),('rubric',{'0':3,'1':4,'2':2,'3':3})]:r=a.post({'action':'field','module':0,'field':field,'value':value,'version':r['version']})['record']
answers=[q['correct'] for q in bank[0]['questions']]
a.post({'action':'quiz','module':1,'answers':answers,'requestId':secrets.token_hex(16),'version':r['version']},400)
a.post({'action':'quiz','module':0,'answers':answers[:9],'requestId':secrets.token_hex(16),'version':r['version']},400)
a.post({'action':'quiz','module':0,'answers':['true']*10,'requestId':secrets.token_hex(16),'version':r['version']},400)
wrong=answers.copy();wrong[0]=not wrong[0];q={'action':'quiz','module':0,'answers':wrong,'requestId':secrets.token_hex(16),'version':r['version'],'score':10,'passed':True}
d=a.post(q);r=d['record'];assert d['result']['score']==9 and not r['progress']['modules']['0']['passed'];duplicate=peer.post(q);assert duplicate['record']==r and duplicate['result']==d['result']
b.post({**q,'email':b.email,'version':b.get()['version']},400)
q={**q,'answers':answers,'requestId':secrets.token_hex(16),'version':r['version']};d=a.post(q);r=d['record'];assert d['result']['score']==10 and r['progress']['modules']['0']['passed'];assert peer.get()==r
history=peer.call('speaking/account.php?module=0');assert history['total']==2 and sorted(x['score'] for x in history['results'])==[9,10]
assert b.call('speaking/account.php?module=0')['total']==0
# Import preserves earlier browser completion without inventing grades or accepting client quiz results.
progress={'version':1,'modules':{'0':{'notes':'Earlier browser notes','reflection':'Earlier reflection with enough detail.','practised':True,'passed':True,'rubric':{'0':4},'score':10,'lastResult':{'score':10}}}}
br=b.post({'action':'import','version':1,'progress':progress})['record'];bm=br['progress']['modules']['0'];assert bm['passed'] and bm['importedCompletion'] and 'lastResult' not in bm and bm['attempts']==0 and b.call('speaking/account.php?module=0')['total']==0
b.post({'action':'import','version':br['version'],'progress':progress},400)
# Every remaining module saves all ten answers, its result and its unlock.
for module in range(1,6):
 for field,value in [('notes',f'Module {module+1}: audience and message.'),('reflection','I rehearsed twice and made my example more specific.'),('practised',True)]:r=a.post({'action':'field','module':module,'field':field,'value':value,'version':r['version']})['record']
 d=a.post({'action':'quiz','module':module,'answers':[q['correct'] for q in bank[module]['questions']],'requestId':secrets.token_hex(16),'version':r['version']});r=d['record'];assert d['result']['score']==10 and r['progress']['modules'][str(module)]['passed'];assert peer.get()==r and peer.call(f'speaking/account.php?module={module}')['total']==1
assert all(m['passed'] for m in r['progress']['modules'].values())
assert a.call('api.php')['account']==baseline
print('PASS: verified same-account persistence, two-session reads, independent user records, CSRF/origin/auth, strict fields and lengths, stale-version conflicts, all six modules / 60 answers graded on the server, incomplete-result rejection, idempotent retry, saved attempt history, explicit browser import with no fabricated grade, investing progress unchanged.')
