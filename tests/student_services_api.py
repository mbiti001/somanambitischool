"""Run against disposable localhost:8791 only. No production or external mail."""
from trusted_fixture import trusted_fixture
import json,pathlib,subprocess,os,secrets,time,urllib.request,urllib.error,http.cookiejar
ROOT=pathlib.Path(__file__).resolve().parents[1];BASE='http://localhost:8791'
fixtures={key:{'email':'student-'+key+'-'+str(time.time_ns())+'@example.test','password':secrets.token_urlsafe(24)} for key in ['a','b']}
p=ROOT/'.local/student-services-fixture.json';p.write_text(json.dumps(fixtures));p.chmod(0o600)
env={**os.environ,'HISA_CONFIG_PATH':str(ROOT/'.local/full-study-config.php')}
subprocess.run(['php','-r',"require 'private/bootstrap.php';$all=json_decode(file_get_contents('.local/student-services-fixture.json'),true);foreach($all as $key=>$f){$id=bin2hex(random_bytes(16));$s=fresh_state();if($key==='a')$s['completed']=[0,1,3,4];query('INSERT INTO users (id,email,display_name,password_hash,verified_at,session_version,created_at) VALUES (?,?,?,?,?,1,?)',[$id,$f['email'],'Student '.$key.' QA',password_hash($f['password'],PASSWORD_DEFAULT),gmdate('c'),gmdate('c')]);query('INSERT INTO learning_accounts (user_id,state_json,version,updated_at) VALUES (?,?,1,?)',[$id,json_encode($s),gmdate('c')]);}"],cwd=ROOT,env=env,check=True)
class Device:
 def __init__(self,fixture=None):
  self.client=urllib.request.build_opener(urllib.request.HTTPCookieProcessor(http.cookiejar.CookieJar()));self.csrf='';self.call('api.php')
  if fixture:self.call('api.php',{'action':'login',**fixture})
 def call(self,path,body=None,status=200,origin=BASE,token=None):
  if body and body.get('action')=='login':trusted_fixture(self.client,ROOT,BASE,body.get('email'))
  req=urllib.request.Request(BASE+'/'+path,data=json.dumps(body).encode() if body is not None else None,headers={'Origin':origin,'Content-Type':'application/json','X-CSRF-Token':self.csrf if token is None else token})
  try:r=self.client.open(req)
  except urllib.error.HTTPError as e:r=e
  d=json.loads(r.read());assert r.status==status,(r.status,d);self.csrf=d.get('csrf',self.csrf);return d
 def student(self,body=None,status=200,**opts):return self.call('students.php',body,status,**opts)
 def exam(self,body,status=200):return self.call('assessments.php',body,status)
a=Device(fixtures['a']);a2=Device(fixtures['a']);b=Device(fixtures['b']);guest=Device();sa=a.student();same=a2.student();sb=b.student()
assert sa['student']['number']==same['student']['number']!=sb['student']['number'];assert not sa['admin'] and not sb['admin'];assert guest.student()['student'] is None
profile={'full_name':'Student Alpha QA','country':'Kenya','region':'Test Region','experience':'beginner','learning_goal':'basics','preferred_language':'English','occupation':'prefer_not_to_say','consent':True}
version=sa['student']['profileVersion'];body={'action':'save_profile','version':version,'profile':profile}
a.student(body,403,origin='https://untrusted.example');a.student(body,403,token='wrong');a.student({**body,'profile':{**profile,'consent':False}},400)
a.student({**body,'profile':{**profile,'experience':'forged'}},400);saved=a.student(body)['student'];assert saved['profileComplete'] and saved['number']==sa['student']['number'];assert a2.student()['student']==saved
conflict=a2.student(body,409);assert conflict['conflict'] and conflict['student']==saved
b.student({'action':'admin_overview'},403);assert b.student()['student']['profile']['full_name'] is None
admin=Device(json.loads((ROOT/'.local/campus-fixture.json').read_text()));assert admin.student()['admin'];overview=admin.student({'action':'admin_overview','search':sa['student']['number'],'page':1})['overview'];assert len(overview['students'])==1 and overview['students'][0]['email']==fixtures['a']['email']
a.student({'action':'clear_profile','version':saved['profileVersion']});assert not a.student()['student']['profileComplete'];assert a.call('api.php')['account']['state']['completed']==[0,1,3,4]
# Server-only assessment keys and strict owner/version checks.
b.exam({'action':'start','key':'module-0'},400);a.exam({'action':'start','key':'final'},400);guest.exam({'action':'start','key':'module-0'},401)
bank=json.loads((ROOT/'private/assessment-bank.json').read_text());keys={q['id']:q['correct'] for q in bank['modules'][0]['questions']}
before=a.call('api.php')['account'];attempt=a.exam({'action':'start','key':'module-0'})['attempt'];assert attempt['deadline']-attempt['serverNow']==120
b.exam({'action':'resume','attemptId':attempt['id']},404);q=attempt['question'];a.exam({'action':'answer','attemptId':attempt['id'],'version':attempt['version'],'questionId':q['id'],'value':'true'},400)
first=True
while attempt['status']=='active':
 assert not any('"'+key+'":' in json.dumps(attempt) for key in ['correct','explanation','result','paper_json'])
 q=attempt['question'];value=not keys[q['id']] if first else keys[q['id']];first=False
 attempt=a.exam({'action':'answer','attemptId':attempt['id'],'version':attempt['version'],'questionId':q['id'],'value':value})['attempt']
assert attempt['result']['score']==9 and not attempt['result']['passed'];assert a.call('api.php')['account']==before
attempt=a.exam({'action':'start','key':'module-0'})['attempt']
while attempt['status']=='active':
 q=attempt['question'];attempt=a.exam({'action':'answer','attemptId':attempt['id'],'version':attempt['version'],'questionId':q['id'],'value':keys[q['id']]})['attempt']
assert attempt['result']['score']==10 and attempt['result']['passed'];state=a2.call('api.php')['account'];assert state['state']['modulePasses']==[0] and state['version']==before['version']+1
assert state['state']['cash']==100000 and not state['state']['history'];a.exam({'action':'start','key':'module-0'},403)
print('PASS: stable unique numbers, consent/validation, own-record isolation, profile version conflicts, admin-only register, profile clearing, private timed questions, strict answer types, failed module stays locked, 100% pass saves across devices and cannot be retaken after passing.')
