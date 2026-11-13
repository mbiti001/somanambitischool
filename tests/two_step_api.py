"""Two-step integration checks against disposable localhost:8793 only."""
from pathlib import Path
import subprocess,os,json,secrets,time,re,urllib.request,urllib.error,http.cookiejar,copy
ROOT=Path(__file__).resolve().parents[1];BASE='http://localhost:8793';CONFIG=ROOT/'.local/two-step-config.php';ENV={**os.environ,'HISA_CONFIG_PATH':str(CONFIG)}
def php(code,extra=None):
 r=subprocess.run(['php','-r',"require 'private/bootstrap.php';require 'private/signin-security.php';require 'private/two-step.php';if(!str_ends_with(settings()['dsn'],'/two-step.sqlite'))throw new Exception('Disposable database required');"+code],cwd=ROOT,env={**ENV,**(extra or {})},capture_output=True,text=True,check=True);return r.stdout.strip()
def clear():php("query('DELETE FROM rate_limits');")
def age_cooldown(v):php("query('UPDATE rate_limits SET window_start=? WHERE bucket=?',[time()-61,hash_hmac('sha256','step-cooldown|'.query('SELECT id FROM users WHERE email=?', [getenv('QA_EMAIL')])->fetchColumn(),settings()['app_key'])]);",{'QA_EMAIL':v['email']})
f={k:{'email':'step-'+k+'-'+str(time.time_ns())+'@example.test','password':secrets.token_urlsafe(24)} for k in ['a','b','unverified']};p=ROOT/'.local/two-step-fixture.json';p.write_text(json.dumps(f));p.chmod(0o600)
php("query('DELETE FROM rate_limits');$f=json_decode(file_get_contents('.local/two-step-fixture.json'),true);foreach($f as $k=>$v){$id=bin2hex(random_bytes(16));query('INSERT INTO users (id,email,display_name,password_hash,verified_at,session_version,created_at) VALUES (?,?,?,?,?,1,?)',[$id,$v['email'],'Two-step QA',password_hash($v['password'],PASSWORD_DEFAULT),$k==='unverified'?null:gmdate('c'),gmdate('c')]);query('INSERT INTO learning_accounts (user_id,state_json,version,updated_at) VALUES (?,?,1,?)',[$id,json_encode(fresh_state()),gmdate('c')]);}")
class Device:
 def __init__(self):self.jar=http.cookiejar.CookieJar();self.op=urllib.request.build_opener(urllib.request.HTTPCookieProcessor(self.jar));self.csrf='';self.call()
 def call(self,body=None,status=200,path='api.php',headers=None):
  h={'Content-Type':'application/json','Origin':BASE,'X-CSRF-Token':self.csrf,**(headers or {})};req=urllib.request.Request(BASE+'/'+path,data=None if body is None else json.dumps(body).encode(),headers=h)
  try:r=self.op.open(req)
  except urllib.error.HTTPError as e:r=e
  d=json.load(r);assert r.status==status,(r.status,d);self.csrf=d.get('csrf',self.csrf);return d
 def login(self,v,status=200):return self.call({'action':'login',**v},status)
 def verify(self,step,code,remember=False,status=200):return self.call({'action':'verify_login_code','challengeId':step['challengeId'],'code':code,'rememberBrowser':remember},status)
 def mutate_pending(self,code):
  sid=next(c.value for c in self.jar if c.name=='hisa_session');php("session_name('hisa_session');session_id(getenv('QA_SID'));session_begin();"+code+"session_write_close();",{'QA_SID':sid})
 def cookie(self):return next((c for c in self.jar if c.name=='hisa_device'),None)
def mails(v):
 p=ROOT/'.local/two-step-mail.jsonl';return [m for l in p.read_text().splitlines() if (m:=json.loads(l))['to']==v['email'] and m['subject']=='Your Soma na Mbiti sign-in code'] if p.exists() else []
def code(v):return re.search(r'\n\n([0-9]{6})\n\n',mails(v)[-1]['body']).group(1)
def start(d,v):
 clear();r=d.login(v);assert r['twoStep'] and r.get('user') is None and r.get('account') is None;return r['twoStep'],code(v)
a=Device();step,c=start(a,f['a']);assert step['expiresIn']==600 and step['resendAfter']==60 and 'code' not in step and c not in json.dumps(step)
assert a.call()['user'] is None and a.call()['twoStep']['challengeId']==step['challengeId'];a.call({'action':'trade','version':1,'symbol':'SCOM','q':1,'side':'buy'},401);assert a.call(path='speaking/account.php')['record'] is None
outsider=Device();outsider.verify(step,c,status=401);a.verify({**step,'challengeId':'0'*32},c,status=409)
r=a.call({'action':'resend_login_code','challengeId':step['challengeId']},429);assert r['mailLimited'] and r['twoStep']['hasCode'];assert a.verify(step,c)['user']['verified'];a.call({'action':'trade','version':1,'symbol':'SCOM','q':2,'side':'buy'});sp=a.call(path='speaking/account.php')['record'];a.call({'action':'field','email':f['a']['email'],'version':sp['version'],'module':0,'field':'notes','value':'Rehearsal notes retained through two-step sign-in.'},path='speaking/account.php');assert a.cookie() is None;a.verify(step,c,status=401)
a.call({'action':'logout'});age_cooldown(f['a']);step=a.login(f['a'])['twoStep'];assert step
# Trust is explicit, fixed-duration and account-bound.
c=code(f['a']);a.verify(step,c,True);cookie=a.cookie();assert cookie and cookie.has_nonstandard_attr('HttpOnly') and 2591990<cookie.expires-time.time()<=2592000
n=len(mails(f['a']));a.call({'action':'logout'});assert a.login(f['a'])['user']['verified'];assert len(mails(f['a']))==n and a.cookie().expires==cookie.expires
# Expired remembered markers require a new code, without losing saved work.
php("query('UPDATE auth_tokens SET expires_at=? WHERE user_id=(SELECT id FROM users WHERE email=?) AND purpose=?',[time()-1,getenv('QA_EMAIL'),'device']);",{'QA_EMAIL':f['a']['email']});a.call({'action':'logout'});age_cooldown(f['a']);r=a.login(f['a']);assert r['twoStep'];assert a.verify(r['twoStep'],code(f['a']),True)['account']['state']['cash']==99950
age_cooldown(f['b']);r=a.login(f['b']);assert r['twoStep'];assert a.call()['user'] is None;a.verify(r['twoStep'],code(f['b']));assert a.call()['user']['email']==f['b']['email']
# An unrelated browser is never trusted by another browser's successful verification.
b=Device();step,c=start(b,f['a']);b.verify(step,c,True);a.call({'action':'logout'});a.login(f['a']);assert a.call()['user']['email']==f['a']['email'];a.call({'action':'forget_browsers'});assert a.call()['user']['verified'];b.call({'action':'logout'});age_cooldown(f['a']);assert b.login(f['a'])['twoStep']
# Cancel and stale challenge IDs cannot complete an older sign-in.
s=b.call()['twoStep'];old=code(f['a']);b.call({'action':'cancel_login','challengeId':s['challengeId']});b.verify(s,old,status=401);assert b.call()['twoStep'] is None
# Resending changes the code without refreshing the guess budget.
d=Device();s,old=start(d,f['a']);d.verify(s,'not-six-digits',status=400);age_cooldown(f['a']);newstep=d.call({'action':'resend_login_code','challengeId':s['challengeId']})['twoStep'];new=code(f['a']);assert new!=old
d.verify(newstep,old,status=400)
for n in range(2):d.verify(newstep,'000000' if new!='000000' else '111111',status=400)
r=d.verify(newstep,'000000' if new!='000000' else '111111',status=429);assert r['restartLogin'] and r['retryAfter']==900;assert d.call()['twoStep'] is None;d.login(f['a'],429)
# Correct passwords cannot reset the global wrong-code guard by creating new challenges.
d=Device();s,c=start(d,f['b']);wrong='000000' if c!='000000' else '111111';d.verify(s,wrong,status=400);d.verify(s,wrong,status=400);age_cooldown(f['b']);s=d.login(f['b'])['twoStep'];c=code(f['b']);wrong='000000' if c!='000000' else '111111';d.verify(s,wrong,status=400);d.verify(s,wrong,status=400);d.verify(s,wrong,status=429)
# Expired code can be resent while its original password proof is still fresh.
d=Device();s,c=start(d,f['a']);d.mutate_pending("$_SESSION['signin_step']['expires']=time()-1;");assert not d.verify(s,c,status=400)['twoStep']['hasCode'];age_cooldown(f['a']);s=d.call({'action':'resend_login_code','challengeId':s['challengeId']})['twoStep'];d.verify(s,code(f['a']))
d.call({'action':'logout'});clear();s=d.login(f['a'])['twoStep'];d.mutate_pending("$_SESSION['signin_step']['deadline']=time()-1;");d.verify(s,code(f['a']),status=401);assert d.call()['twoStep'] is None
# Email completion also confirms an account that had not followed its verification link yet.
e=Device();s,c=start(e,f['unverified']);assert e.verify(s,c)['user']['verified']
# Account-wide mail limits work across sessions; resending cannot create a flood.
clear()
for n in range(5):
 q=Device();q.login(f['b']);age_cooldown(f['b'])
r=Device().login(f['b'],429);assert r['mailLimited'] and not r['twoStep']['hasCode'] and r['retryAfter']>3500
# Recovery revokes remembered browsers and pending password proofs.
q=Device();s,c=start(q,f['a']);q.verify(s,c,True);q.call({'action':'logout'});pending=Device();age_cooldown(f['a']);s=pending.login(f['a'])['twoStep'];oldcode=code(f['a']);reset=Device();reset.call({'action':'forgot','email':f['a']['email']});mail=[json.loads(l) for l in (ROOT/'.local/two-step-mail.jsonl').read_text().splitlines()];token=re.search(r'reset=([a-f0-9]{64})',next(m['body'] for m in reversed(mail) if m['to']==f['a']['email'] and m['subject'].startswith('Reset'))).group(1);newpass=secrets.token_urlsafe(24);reset.call({'action':'reset_password','token':token,'password':newpass});pending.verify(s,oldcode,status=401);age_cooldown(f['a']);fresh=q.login({**f['a'],'password':newpass});assert fresh['twoStep'];assert q.verify(fresh['twoStep'],code(f['a']))['account']['state']['cash']==99950;assert q.call(path='speaking/account.php')['record']['progress']['modules']['0']['notes'].startswith('Rehearsal notes retained')
# Delivery failure never grants access; restoring delivery lets a resend complete the same sign-in.
clear();failureConfig=ROOT/'.local/two-step-failure-config.php'
php("$c=settings();$c['site_url']='http://localhost:8794';$c['mail_log']=getcwd().'/.local/does-not-exist/two-step.jsonl';file_put_contents('.local/two-step-failure-config.php','<?php return '.var_export($c,true).';');chmod('.local/two-step-failure-config.php',0600);")
with (ROOT/'.local/two-step-failure-server.log').open('w') as log:
 server=subprocess.Popen(['php','-S','127.0.0.1:8794','-t','public'],cwd=ROOT,env={**ENV,'HISA_CONFIG_PATH':str(failureConfig)},stdout=log,stderr=log)
 originalBase=BASE;BASE='http://localhost:8794'
 try:
  for retry in range(50):
   try:x=Device();break
   except urllib.error.URLError:time.sleep(.05)
  else:raise AssertionError('Local mail-failure test server did not start')
  r=x.login(f['b'],503);assert r['twoStep'] and not r['twoStep']['hasCode'] and x.call()['user'] is None;x.verify(r['twoStep'],'000000',status=400)
 finally:
  BASE=originalBase;server.terminate();server.wait(timeout=10)
age_cooldown(f['b']);s=x.call({'action':'resend_login_code','challengeId':r['twoStep']['challengeId']})['twoStep'];assert x.verify(s,code(f['b']))['user']['verified']
# Keep a fresh known fixture for browser QA; owner credentials are never used.
f['a']['password']=newpass;p.write_text(json.dumps(f));p.chmod(0o600);clear()
print('PASS: password-only access blocked, no exposed code, session/challenge binding, resend cooldown, single-use codes, explicit trusted browser, fixed expiry, cross-account isolation, forget browsers, code-guess and distributed restart limits, expiry/resend, email confirmation, mail quota, reset revocation, delivery failure and recovery.')
