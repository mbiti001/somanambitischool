"""Password-recovery edge cases on the dedicated disposable localhost copy only."""
from trusted_fixture import trusted_fixture
from pathlib import Path
import subprocess,os,json,time,secrets,urllib.request,urllib.error,http.cookiejar,re,sqlite3
ROOT=Path(__file__).resolve().parents[1];BASE='http://localhost:8792';DB=ROOT/'.local/signin-security.sqlite';env={**os.environ,'HISA_CONFIG_PATH':str(ROOT/'.local/signin-security-config.php')}
def php(code,environment=env):
 p=subprocess.run(['php','-r',"require 'private/bootstrap.php';require 'private/signin-security.php';if(!str_ends_with(settings()['dsn'],'/signin-security.sqlite'))throw new Exception('Disposable database required');"+code],cwd=ROOT,env=environment,capture_output=True,text=True,check=True);return p.stdout.strip()
f={k:{'email':'recovery-'+k+'-'+str(time.time_ns())+'@example.test','password':secrets.token_urlsafe(24)} for k in ['owner','other']};fp=ROOT/'.local/recovery-retest-fixture.json';fp.write_text(json.dumps(f));fp.chmod(0o600)
php("query('DELETE FROM rate_limits');$f=json_decode(file_get_contents('.local/recovery-retest-fixture.json'),true);foreach($f as $v){$id=bin2hex(random_bytes(16));query('INSERT INTO users (id,email,display_name,password_hash,verified_at,session_version,created_at) VALUES (?,?,?,?,?,1,?)',[$id,$v['email'],'Recovery QA',password_hash($v['password'],PASSWORD_DEFAULT),gmdate('c'),gmdate('c')]);query('INSERT INTO learning_accounts (user_id,state_json,version,updated_at) VALUES (?,?,1,?)',[$id,json_encode(fresh_state()),gmdate('c')]);query('INSERT INTO speaking_accounts (user_id,state_json,version,updated_at) VALUES (?,?,2,?)',[$id,json_encode(['version'=>1,'modules'=>(object)['0'=>['notes'=>'Saved rehearsal notes','reflection'=>'A saved reflection with enough detail.','practised'=>true,'passed'=>false,'rubric'=>(object)[],'attempts'=>0]]]),gmdate('c')]);}")
class Device:
 def __init__(self):self.op=urllib.request.build_opener(urllib.request.HTTPCookieProcessor(http.cookiejar.CookieJar()));self.csrf='';self.call()
 def call(self,body=None,status=200,path='api.php'):
  if body and body.get('action')=='login':trusted_fixture(self.op,ROOT,BASE,body.get('email'))
  req=urllib.request.Request(BASE+'/'+path,data=None if body is None else json.dumps(body).encode(),headers={'Origin':BASE,'Content-Type':'application/json','X-CSRF-Token':self.csrf})
  try:r=self.op.open(req)
  except urllib.error.HTTPError as e:r=e
  d=json.load(r);assert r.status==status,(r.status,d);self.csrf=d.get('csrf',self.csrf);return d
 def login(self,v):return self.call({'action':'login',**v})
def messages():
 p=ROOT/'.local/signin-security-mail.jsonl';return [json.loads(l) for l in p.read_text().splitlines()] if p.exists() else []
def token():return re.search(r'reset=([a-f0-9]{64})',next(m['body'] for m in reversed(messages()) if m['to']==f['owner']['email'])).group(1)
def clear_requests():php("query('DELETE FROM rate_limits');")
a=Device();peer=Device();other=Device();a.login(f['owner']);peer.login(f['owner']);other.login(f['other']);before=a.call();speaking=a.call(path='speaking/account.php')['record'];owner=before['user']['email'];e=json.dumps(owner)
# Add representative saved account work before recovering it.
v=before['account']['version'];a.call({'action':'trade','version':v,'symbol':'SCOM','q':2,'side':'buy'});before=a.call()
x=Device();count=len(messages());x.call({'action':'forgot','email':'invalid'},400);assert len(messages())==count
notice=x.call({'action':'forgot','email':'unknown-'+str(time.time_ns())+'@example.test'})['notice'];assert len(messages())==count
assert x.call({'action':'forgot','email':owner})['notice']==notice;first=token();assert first not in str(before)
with sqlite3.connect(DB) as db:
 row=db.execute('SELECT t.token_hash,t.expires_at,u.id FROM auth_tokens t JOIN users u ON u.id=t.user_id WHERE u.email=? AND purpose=?',(owner,'reset')).fetchone();assert row and row[0]!=first and len(row[0])==64 and 1795<=row[1]-time.time()<=1800
x.call({'action':'forgot','email':owner});second=token();assert first!=second
for bad in [first,'0'*64,'bad','a'*63,'a'*65]:x.call({'action':'reset_password','token':bad,'password':'Synthetic new passphrase 749'},400)
with sqlite3.connect(DB) as db:assert db.execute('SELECT count(*) FROM auth_tokens t JOIN users u ON u.id=t.user_id WHERE u.email=? AND purpose=?',(owner,'reset')).fetchone()[0]==1
assert a.call()['user']['email']==owner
# Expired links fail and leave the password/session untouched.
php("query('UPDATE auth_tokens SET expires_at=? WHERE user_id=(SELECT id FROM users WHERE email=?) AND purpose=?',[time()-1,"+e+",'reset']);")
x.call({'action':'reset_password','token':second,'password':'Synthetic new passphrase 749'},400);assert peer.call()['user']['email']==owner
clear_requests();x.call({'action':'forgot','email':owner});valid=token()
for bad in ['12345678901234','é'*37,None,'x\x00'*10]:x.call({'action':'reset_password','token':valid,'password':bad},400)
new=secrets.token_urlsafe(24);x.call({'action':'reset_password','token':valid,'password':new,'email':f['other']['email'],'user_id':'ignored'})
assert a.call()['user'] is None and peer.call()['user'] is None and other.call()['user']['email']==f['other']['email'];a.call({'action':'login',**f['owner']},401);restored=a.login({**f['owner'],'password':new});assert restored['account']==before['account'];assert restored['user']['verified'];assert a.call(path='speaking/account.php')['record']==speaking
x.call({'action':'reset_password','token':valid,'password':'Replay sample passphrase'},400)
# Mail-delivery failure removes the unusable token; no fake success token is retained.
failureConfig=ROOT/'.local/recovery-failure-config.php';php("$c=settings();$c['mail_log']=getcwd().'/.local/missing-mail-directory/outbox.jsonl';file_put_contents('.local/recovery-failure-config.php','<?php return '.var_export($c,true).';');chmod('.local/recovery-failure-config.php',0600);")
failed=php("$u=query('SELECT * FROM users WHERE email=?',["+e+"])->fetch();if(issue_token($u,'reset')!==false)throw new Exception('Expected mail failure');if(query('SELECT count(*) FROM auth_tokens WHERE user_id=? AND purpose=?',[$u['id'],'reset'])->fetchColumn()!=0)throw new Exception('Undelivered reset token remains');echo 'PASS';",{**env,'HISA_CONFIG_PATH':str(failureConfig)});assert failed=='PASS'
# Prepare one unredeemed LOCAL link for the browser's empty reset-form display test.
clear_requests();x.call({'action':'forgot','email':owner});preview=ROOT/'.local/recovery-form-preview.json';preview.write_text(json.dumps({'url':BASE+'/?reset='+token(),'email':owner}));preview.chmod(0o600)
print('PASS: malformed/unknown address handling, generic recovery response, one active hashed 30-minute link, replaced/tampered/expired/used-token rejection, invalid passwords do not consume a valid link, successful reset revokes only the owner sessions, client-supplied target ignored, saved investing and speaking work retained, undelivered token removed, local reset-form preview prepared.')
