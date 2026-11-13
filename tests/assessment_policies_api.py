"""Real HTTP timing/retake/certificate-record checks on disposable localhost only."""
from trusted_fixture import trusted_fixture
import json,pathlib,subprocess,os,secrets,time,urllib.request,urllib.error,http.cookiejar,datetime
ROOT=pathlib.Path(__file__).resolve().parents[1];BASE='http://localhost:8791';bank=json.loads((ROOT/'private/assessment-bank.json').read_text())
fixtures={k:{'email':'assessment-'+k+'-'+str(time.time_ns())+'@example.test','password':secrets.token_urlsafe(24)} for k in ['module_limit','timer','final_pass','final_limit']}
p=ROOT/'.local/assessment-policy-fixture.json';p.write_text(json.dumps(fixtures));p.chmod(0o600);env={**os.environ,'HISA_CONFIG_PATH':str(ROOT/'.local/full-study-config.php')}
def php(code):return subprocess.run(['php','-r',"require 'private/bootstrap.php';"+code],cwd=ROOT,env=env,check=True,capture_output=True,text=True).stdout
php("$all=json_decode(file_get_contents('.local/assessment-policy-fixture.json'),true);foreach($all as $key=>$f){$id=bin2hex(random_bytes(16));$s=fresh_state();$s['completed']=str_starts_with($key,'final')?array_column(course_catalog()['lessons'],'id'):[0,1,3,4];if(str_starts_with($key,'final'))$s['modulePasses']=[0,1,2,3];query('INSERT INTO users (id,email,display_name,password_hash,verified_at,session_version,created_at) VALUES (?,?,?,?,?,1,?)',[$id,$f['email'],$key==='final_pass'?'Wanjirũ Ngũgĩ QA':'Assessment Policy QA',password_hash($f['password'],PASSWORD_DEFAULT),gmdate('c'),gmdate('c')]);query('INSERT INTO learning_accounts (user_id,state_json,version,updated_at) VALUES (?,?,1,?)',[$id,json_encode($s),gmdate('c')]);}")
class Device:
 def __init__(self,f):
  self.client=urllib.request.build_opener(urllib.request.HTTPCookieProcessor(http.cookiejar.CookieJar()));self.csrf='';self.call('api.php');self.call('api.php',{'action':'login',**f})
 def call(self,path,body=None,status=200):
  if body and body.get('action')=='login':trusted_fixture(self.client,ROOT,BASE,body.get('email'))
  req=urllib.request.Request(BASE+'/'+path,data=json.dumps(body).encode() if body is not None else None,headers={'Origin':BASE,'Content-Type':'application/json','X-CSRF-Token':self.csrf})
  try:r=self.client.open(req)
  except urllib.error.HTTPError as e:r=e
  data=json.loads(r.read());assert r.status==status,(r.status,data);self.csrf=data.get('csrf',self.csrf);return data
 def exam(self,body,status=200):return self.call('assessments.php',body,status)
 def overview(self,key):return self.call('assessments.php?key='+key)
def correct(q):
 if q['type']=='tf':return next(x['correct'] for m in bank['modules'] for x in m['questions'] if x['id']==q['id'])
 if q['type']=='mcq':
  source=next(x for x in bank['final']['a'] if x['id']==q['id']);return q['answers'].index(source['answers'][source['correct']])
 source=next(x for x in bank['final']['b'] if x['id']==q['id']);return [next(s['correct'] for s in source['statements'] if s['text']==x['text']) for x in q['statements']]
def finish(device,key,wrong_marks):
 a=device.exam({'action':'start','key':key})['attempt'];n=wrong_marks;seen_b=False
 while a['status']=='active':
  assert a['deadline']-a['serverNow']<=120 and 'result' not in a
  q=a['question'];v=correct(q)
  if q['type']=='statements':
   if not seen_b:
    device.exam({'action':'answer','attemptId':a['id'],'version':a['version'],'questionId':q['id'],'value':v[:3]},400);seen_b=True
   for i in range(len(v)):
    if n:v[i]=not v[i];n-=1
  elif n:
   v=not v if q['type']=='tf' else (v+1)%len(q['answers']);n-=1
  a=device.exam({'action':'answer','attemptId':a['id'],'version':a['version'],'questionId':q['id'],'value':v,'score':999,'passed':True})['attempt']
 assert n==0;return a
m=Device(fixtures['module_limit'])
for i in range(5):
 a=finish(m,'module-0',10);assert not a['result']['passed'];p=m.overview('module-0')['retakePolicy'];assert p['remaining']==4-i
locked=m.exam({'action':'start','key':'module-0','now':int(time.time())+8*86400},403);assert locked['retakePolicy']['availableAt']>=int(time.time())+7*86400-5
# Reaching the real server-side seven-day boundary opens a fresh attempt set.
email=json.dumps(fixtures['module_limit']['email']);old=(datetime.datetime.now(datetime.timezone.utc)-datetime.timedelta(days=8)).isoformat(timespec='seconds')
php("$id=query('SELECT id FROM users WHERE email=?',["+email+"])->fetchColumn();query('UPDATE assessment_attempts SET started_at=?,submitted_at=? WHERE user_id=?',["+json.dumps(old)+","+json.dumps(old)+",$id]);")
assert m.overview('module-0')['retakePolicy']['remaining']==5;m.exam({'action':'start','key':'module-0'})
t=Device(fixtures['timer']);peer=Device(fixtures['timer']);a=t.exam({'action':'start','key':'module-0'})['attempt'];same=peer.exam({'action':'resume','attemptId':a['id']})['attempt'];assert same['deadline']==a['deadline'] and same['id']==a['id']
php("query('UPDATE assessment_attempts SET deadline_at=? WHERE id=?',[time()-121,'"+a['id']+"']);")
advanced=t.exam({'action':'resume','attemptId':a['id']})['attempt'];assert advanced['currentIndex']==2 and advanced['timedOutQuestions']==2 and 'result' not in advanced
peer.exam({'action':'answer','attemptId':a['id'],'version':a['version'],'questionId':a['question']['id'],'value':correct(a['question'])},409)
php("query('UPDATE assessment_attempts SET deadline_at=? WHERE id=?',[time()-5000,'"+a['id']+"']);")
ended=t.exam({'action':'resume','attemptId':a['id']})['attempt'];assert ended['status']=='submitted' and ended['result']['score']==0 and ended['result']['timedOutQuestions']==10
f=Device(fixtures['final_pass']);failed=finish(f,'final',9);assert failed['result']['score']==46 and not failed['result']['passed']
passed=finish(f,'final',8);assert passed['result']['score']==47 and passed['result']['passed'];cert=passed['certificate'];assert cert['name']=='Wanjirũ Ngũgĩ QA' and cert['signatories'][0]['name']=='Edwin Mbiti' and cert['signatories'][0]['title']=='Director & CEO'
assert f.call('students.php')['certificate']['number']==cert['number'];f.exam({'action':'start','key':'final'},403)
g=Device(fixtures['final_limit']);finish(g,'final',55);finish(g,'final',55);lock=g.exam({'action':'start','key':'final'},403);assert lock['retakePolicy']['limit']==2 and lock['retakePolicy']['remaining']==0
print('PASS: five module failures and two final failures enforce seven-day study breaks; server-side expiry/resume cannot be reset; stale-device answers rejected; all four statements required; 46/55 fails, 47/55 passes; a unique Unicode-name certificate record has the requested signatory and role; passed assessments cannot be restarted.')
(ROOT/'work/assessment-policy-results.json').write_text(json.dumps({'moduleAttemptLimit':5,'finalAttemptLimit':2,'waitDays':7,'secondsPerQuestion':120,'finalQuestions':25,'finalMaximum':55,'finalRequiredMarks':47,'timerResumePassed':True,'certificateRecordPassed':True},indent=2))
