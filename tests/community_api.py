"""Isolated campus integration tests. Never connects to production."""
from pathlib import Path
import urllib.request,urllib.error,http.cookiejar,json,subprocess,os,re,secrets,uuid
ROOT=Path(__file__).resolve().parents[1];BASE='http://localhost:8796';ENV={**os.environ,'HISA_CONFIG_PATH':str(ROOT/'.local/community-config.php'),'HISA_STUDENT_CONFIG_PATH':str(ROOT/'.local/community-student-config.php')}
def php(code):
 return subprocess.run(['php','-r',"require 'private/bootstrap.php';require 'private/community.php';if((settings()['environment']??'')!=='development'||!str_ends_with(settings()['dsn'],'/community.sqlite'))throw new Exception('Isolated QA only');"+code],cwd=ROOT,env=ENV,capture_output=True,text=True,check=True).stdout.strip()
fixture=json.loads((ROOT/'.local/community-fixture.json').read_text());php("query('DELETE FROM rate_limits');query('DELETE FROM campus_controls');query('DELETE FROM campus_messages');query('DELETE FROM campus_reports');query('DELETE FROM campus_enrollments');query('DELETE FROM campus_audit');query('DELETE FROM campus_avatars');campus_install();")
class Device:
 def __init__(self,key=None):
  self.jar=http.cookiejar.CookieJar();self.op=urllib.request.build_opener(urllib.request.HTTPCookieProcessor(self.jar));self.csrf='';self.key=key;self.call('api.php')
  if key:self.login()
 def call(self,path='community.php',body=None,status=200,headers=None,raw=False):
  data=None if body is None else body if isinstance(body,bytes) else json.dumps(body).encode();h={'Origin':BASE,**({'Content-Type':'application/json','X-CSRF-Token':self.csrf} if data is not None else {}),**(headers or {})};req=urllib.request.Request(BASE+'/'+path,data=data,headers=h)
  try:r=self.op.open(req)
  except urllib.error.HTTPError as err:r=err
  content=r.read();assert r.status==status,(r.status,path,content[:250]);
  if raw:return content,r.headers
  d=json.loads(content);self.csrf=d.get('csrf',self.csrf);return d
 def login(self,status=200):
  v=fixture[self.key];d=self.call('api.php',{'action':'login','email':v['email'],'password':v['password']},status)
  if status!=200:return d
  if 'twoStep' in d:
   mails=[json.loads(l) for l in (ROOT/'.local/community-mail.jsonl').read_text().splitlines()];m=next(m for m in reversed(mails) if m['to']==v['email'] and 'sign-in code' in m['subject']);c=re.search(r'\n\n(\d{6})\n\n',m['body']).group(1);d=self.call('api.php',{'action':'verify_login_code','challengeId':d['twoStep']['challengeId'],'code':c})
  assert d['user']['email']==v['email'];return d
 def join(self,c):return self.call(body={'action':'join','course':c,'consent':True})
 def post(self,c,t,**kw):return self.call(body={'action':'post','course':c,'text':t,'requestId':secrets.token_hex(16)},**kw)
 def controls(self,target,**flags):
  r=self.call('community.php?view=student&student='+fixture[target]['id'])['record'];old={k:bool(int(r['controls'][k])) for k in ['muted','read_only','suspended']};return self.call(body={'action':'controls','student':fixture[target]['id'],'version':int(r['controls']['version']),'reason':'Isolated QA access policy check',**old,**flags})
 def upload(self,data,filename='test.png',status=200,csrf=True):
  boundary='QA'+uuid.uuid4().hex;body=('--'+boundary+'\r\nContent-Disposition: form-data; name="avatar"; filename="'+filename+'"\r\nContent-Type: image/png\r\n\r\n').encode()+data+('\r\n--'+boundary+'--\r\n').encode();return self.call('avatar.php',body,status,{'Content-Type':'multipart/form-data; boundary='+boundary,'X-CSRF-Token':self.csrf if csrf else 'bad'})
guest=Device();guest.call('community.php?view=gradebook',status=401)
a=Device('alice');b=Device('bob');admin=Device('staff')
a.call('community.php?view=admin',status=403);a.call('community.php?view=student&student='+fixture['bob']['id'],status=403)
a.call(body={'action':'controls','student':fixture['bob']['id']},status=403);a.post('investing','Cannot post before joining',status=400)
a.call(body={'action':'join','course':'investing','consent':False},status=400)
a.join('investing');b.join('investing');b.join('speaking')
# Pending student posts are visible only to their author and staff; cross-course content stays isolated.
text='<script>not executable</script> A thoughtful practice question.'
r=a.post('investing',text)['room'];m=r['messages'][-1];assert m['status']=='pending' and m['body']==text
assert not b.call('community.php?view=chat&course=investing')['room']['messages']
assert not a.call('community.php?view=chat&course=speaking')['room']['messages']
queue=admin.call('community.php?view=admin')['queue'];assert any(str(x['id'])==m['id'] for x in queue)
a.call(body={'action':'moderate','message':m['id'],'status':'approved','reason':'No authority'},status=403)
admin.call(body={'action':'moderate','message':m['id'],'status':'approved','reason':'Appropriate learning question'})
assert b.call('community.php?view=chat&course=investing')['room']['messages'][-1]['status']=='approved'
b.call(body={'action':'report','message':m['id']});assert admin.call('community.php?view=admin')['queue'][0]['reports']>=1
admin.call(body={'action':'moderate','message':m['id'],'status':'hidden','reason':'QA removal check'});assert not b.call('community.php?view=chat&course=investing')['room']['messages']
a.call(body={'action':'post','course':'investing','text':'No file uploads','requestId':secrets.token_hex(16),'attachment':'data:fake'},status=400)
# Retries are idempotent; invalid CSRF and cross-site requests cannot post.
body={'action':'post','course':'investing','text':'Idempotent QA message','requestId':secrets.token_hex(16)};one=a.call(body=body)['room']['messages'];two=a.call(body=body)['room']['messages'];assert [x['id'] for x in one]==[x['id'] for x in two]
a.call(body={'action':'post','course':'investing','text':'Cross site','requestId':secrets.token_hex(16)},status=403,headers={'Origin':'https://other.test'})
a.call(body={'action':'join','course':'speaking','consent':True},status=403,headers={'X-CSRF-Token':'bad'})
# Account-level mute/read-only/suspension are enforced by every authenticated write endpoint.
admin.controls('alice',muted=True);a.post('investing','Muted message',status=403);a.call('community.php?view=gradebook')
admin.controls('alice',muted=False,read_only=True)
a.call('api.php',{'action':'trade','version':1,'symbol':'SCOM','q':1,'side':'buy'},403)
a.call('students.php',{'action':'save_profile'},403)
a.call('speaking/account.php',{'action':'field'},403)
a.call('assessments.php',{'action':'start','key':'module-0'},403)
a.post('investing','Read-only message',status=403);a.call('community.php?view=gradebook')
admin.controls('alice',read_only=False)
# Room locks do not require closing learning access.
room=admin.call('community.php?view=chat&course=investing')['room'];admin.call(body={'action':'room_lock','course':'investing','version':room['roomVersion'],'locked':True,'reason':'QA room freeze'})
a.post('investing','Locked room',status=403);a.call('api.php')
admin.call(body={'action':'room_lock','course':'investing','version':room['roomVersion'],'locked':False,'reason':'Stale version'},status=409)
admin.call(body={'action':'room_lock','course':'investing','version':room['roomVersion']+1,'locked':False,'reason':'QA reopen'})
# Compact re-encoded avatars, hard size/type checks, authenticated course-peer visibility.
php("$im=imagecreatetruecolor(400,300);imagefill($im,0,0,imagecolorallocate($im,34,80,60));imagepng($im,'.local/community-avatar.png');imagedestroy($im);")
picture=(ROOT/'.local/community-avatar.png').read_bytes();uploaded=a.upload(picture);assert uploaded['bytes']<80000
binary,headers=b.call(uploaded['avatar'],raw=True);assert headers['Content-Type']=='image/jpeg' and binary[:2]==b'\xff\xd8'
assert php("$r=query('SELECT image_data FROM campus_avatars WHERE user_id=?',['"+fixture['alice']['id']+"'])->fetch();$i=getimagesizefromstring($r['image_data']);echo $i[0].'x'.$i[1];")=='256x256'
a.upload(b'x'*1048577,status=400);a.upload(b'<svg xmlns="http://www.w3.org/2000/svg"/>',status=400);a.upload(picture,status=403,csrf=False)
a.upload(picture);assert int(php("echo query('SELECT COUNT(*) FROM campus_avatars WHERE user_id=?',['"+fixture['alice']['id']+"'])->fetchColumn();"))==1
guest.call(uploaded['avatar'],status=401)
# Gradebook contains actual saved scores, no answer keys or another student's grades.
php("$f=json_decode(file_get_contents('.local/community-fixture.json'),true);query('INSERT INTO speaking_quiz_results (id,user_id,module_index,answers_json,score,submitted_at) VALUES (?,?,?,?,?,?)',[bin2hex(random_bytes(16)),$f['alice']['id'],0,'[]',7,gmdate('c')]);")
grade=a.call('community.php?view=gradebook')['grades'];assert int(grade['speaking']['summary'][0]['best_score'])==7 and 'answers' not in json.dumps(grade)
assert not b.call('community.php?view=gradebook&student='+fixture['alice']['id'])['grades']['speaking']['attempts']
assert admin.call('community.php?view=student&student='+fixture['alice']['id'])['record']['grades']==grade
admin.controls('alice',suspended=True);assert a.call('api.php')['user'] is None;a.login(status=403)
admin.controls('alice',suspended=False);php("query('DELETE FROM rate_limits');");a.login();a.call('community.php?view=gradebook')
admin.call(body={'action':'controls','student':fixture['staff']['id'],'version':0,'reason':'Self restriction','muted':True,'read_only':True,'suspended':True},status=400)
assert len(admin.call('community.php?view=admin')['audit'])>=8
print('PASS: role boundaries, student privacy, course membership/consent, approval/report/hide moderation, plain text/XSS containment, idempotency, CSRF/origin guards, mute/read-only/suspension enforcement, room locks/version conflicts, compact avatar encoding/size/type/ACL, real grade records and administrator audit trail.')
