"""Full curriculum persistence check on a disposable local installation."""
from trusted_fixture import trusted_fixture
import json,urllib.request,urllib.error,http.cookiejar,pathlib,subprocess,os,time,secrets
ROOT=pathlib.Path(__file__).resolve().parents[1];BASE='http://localhost:8791'
fixture={'email':'full-study-'+str(time.time_ns())+'@example.test','password':secrets.token_urlsafe(24)}
(ROOT/'.local/full-study-fixture.json').write_text(json.dumps(fixture))
env={**os.environ,'HISA_CONFIG_PATH':str(ROOT/'.local/full-study-config.php')}
php="""require 'private/bootstrap.php';$f=json_decode(file_get_contents('.local/full-study-fixture.json'),true);$id=bin2hex(random_bytes(16));query('INSERT INTO users (id,email,display_name,password_hash,verified_at,session_version,created_at) VALUES (?,?,?,?,?,1,?)',[$id,$f['email'],'Full Study QA',password_hash($f['password'],PASSWORD_DEFAULT),gmdate('c'),gmdate('c')]);query('INSERT INTO learning_accounts (user_id,state_json,version,updated_at) VALUES (?,?,1,?)',[$id,json_encode(fresh_state()),gmdate('c')]);"""
subprocess.run(['php','-r',php],cwd=ROOT,env=env,check=True)
class Device:
 def __init__(self):
  self.opener=urllib.request.build_opener(urllib.request.HTTPCookieProcessor(http.cookiejar.CookieJar()));self.csrf='';self.call();self.call({'action':'login',**fixture})
 def exam(self,body,status=200):
  req=urllib.request.Request(BASE+'/assessments.php',data=json.dumps(body).encode(),headers={'Origin':BASE,'Content-Type':'application/json','X-CSRF-Token':self.csrf})
  try:r=self.opener.open(req)
  except urllib.error.HTTPError as e:r=e
  data=json.loads(r.read());assert r.status==status,(r.status,data);return data
 def call(self,body=None,status=200):
  if body and body.get('action')=='login':trusted_fixture(self.opener,ROOT,BASE,body.get('email'))
  req=urllib.request.Request(BASE+'/api.php',data=json.dumps(body).encode() if body else None,headers={'Origin':BASE,'Content-Type':'application/json','X-CSRF-Token':self.csrf})
  try:r=self.opener.open(req)
  except urllib.error.HTTPError as e:r=e
  data=json.loads(r.read());assert r.status==status,(r.status,data);self.csrf=data.get('csrf',self.csrf);return data
bank=json.loads((ROOT/'private/assessment-bank.json').read_text());
c=json.loads((ROOT/'public/course.json').read_text());a=Device();b=Device();rejections=0
path=[l for module in dict.fromkeys(l['module'] for l in c['lessons']) for l in c['lessons'] if l['module']==module]
a.call({'action':'section','version':a.call()['account']['version'],'lesson':1,'unit':'understand','answers':[1,0]},400)
for pi,l in enumerate(path):
 for index,unit in enumerate(l['units']):
  before=a.call()['account'];answers=[q['correct'] for q in unit['questions']]
  if index<2:
   later=l['units'][index+1];a.call({'action':'section','version':before['version'],'lesson':l['id'],'unit':later['id'],'answers':[q['correct'] for q in later['questions']]},400)
  for qi,q in enumerate(unit['questions']):
   for choice in range(len(q['answers'])):
    if choice==q['correct']:continue
    wrong=answers.copy();wrong[qi]=choice
    a.call({'action':'section','version':before['version'],'lesson':l['id'],'unit':unit['id'],'answers':wrong},400);rejections+=1
  assert b.call()['account']==before
  a.call({'action':'section','version':before['version'],'lesson':l['id'],'unit':unit['id'],'answers':answers})
  saved=b.call()['account'];assert len(saved['state']['unitPasses'][str(l['id'])])==index+1
  assert (l['id'] in saved['state']['completed'])==(index==2)
  b.call({'action':'section','version':before['version'],'lesson':l['id'],'unit':unit['id'],'answers':answers},409)
 if pi==len(path)-1 or path[pi+1]['module']!=l['module']:
  mi=list(dict.fromkeys(x['module'] for x in path)).index(l['module']);attempt=a.exam({'action':'start','key':'module-'+str(mi)})['attempt'];keys={q['id']:q['correct'] for q in bank['modules'][mi]['questions']}
  while attempt['status']=='active':
   q=attempt['question'];attempt=a.exam({'action':'answer','attemptId':attempt['id'],'version':attempt['version'],'questionId':q['id'],'value':keys[q['id']]})['attempt']
  assert attempt['result']['score']==10 and attempt['result']['passed'];assert b.call()['account']['state']['modulePasses']==list(range(mi+1))
 print('PASS chapter',l['id']+1,': all sections gated, every wrong choice rejected, progress shared between sessions')
fresh=Device().call()['account']['state'];assert sorted(fresh['completed'])==list(range(18));assert len(fresh['unitPasses'])==18;assert fresh['cash']==100000 and not fresh['holdings'] and not fresh['history'] and not fresh['journal']
(ROOT/'work/full-study-api-results.json').write_text(json.dumps({'classes':18,'sections':54,'questions':162,'wrongOptionsRejected':rejections,'freshLoginCompleted':18,'portfolioUnchanged':True},indent=2))
print('PASS:',rejections,'incorrect choices rejected; all 54 sections and 18 completions retained after fresh sign-in.')
