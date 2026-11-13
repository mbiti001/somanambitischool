"""Security regression checks against the dedicated disposable localhost:8792 copy."""
from trusted_fixture import trusted_fixture
import pathlib,subprocess,os,json,secrets,time,urllib.request,urllib.error,http.cookiejar,re,sqlite3
ROOT=pathlib.Path(__file__).resolve().parents[1];BASE='http://localhost:8792';CONFIG=ROOT/'.local/signin-security-config.php';DB=ROOT/'.local/signin-security.sqlite'
ENV={**os.environ,'HISA_CONFIG_PATH':str(CONFIG)}
def php(code):
 r=subprocess.run(['php','-r',"require 'private/bootstrap.php';require 'private/signin-security.php';if(!str_ends_with(settings()['dsn'],'/signin-security.sqlite'))throw new Exception('Disposable database required');"+code],cwd=ROOT,env=ENV,check=True,capture_output=True,text=True);return r.stdout.strip()
def clear():php("query('DELETE FROM rate_limits');")
f={k:{'email':'security-'+k+'-'+str(time.time_ns())+'@example.test','password':'Old-pass-123!' if k=='a' else secrets.token_urlsafe(24)} for k in ['a','b']};path=ROOT/'.local/signin-security-fixture.json';path.write_text(json.dumps(f));path.chmod(0o600)
php("$f=json_decode(file_get_contents('.local/signin-security-fixture.json'),true);foreach($f as $v){$id=bin2hex(random_bytes(16));query('INSERT INTO users (id,email,display_name,password_hash,verified_at,session_version,created_at) VALUES (?,?,?,?,?,1,?)',[$id,$v['email'],'Security QA',password_hash($v['password'],PASSWORD_DEFAULT),gmdate('c'),gmdate('c')]);query('INSERT INTO learning_accounts (user_id,state_json,version,updated_at) VALUES (?,?,1,?)',[$id,json_encode(fresh_state()),gmdate('c')]);}")
class Device:
 def __init__(self):self.jar=http.cookiejar.CookieJar();self.op=urllib.request.build_opener(urllib.request.HTTPCookieProcessor(self.jar));self.csrf='';self.call()
 def call(self,body=None,status=200,headers=None):
  if body and body.get('action')=='login':trusted_fixture(self.op,ROOT,BASE,body.get('email'))
  h={'Origin':BASE,'Content-Type':'application/json','X-CSRF-Token':self.csrf,**(headers or {})};req=urllib.request.Request(BASE+'/api.php',data=None if body is None else json.dumps(body).encode(),headers=h)
  try:r=self.op.open(req)
  except urllib.error.HTTPError as e:r=e
  d=json.load(r);assert r.status==status,(r.status,d);self.csrf=d.get('csrf',self.csrf);return d,r.headers
 def login(self,fixture,status=200,**kw):return self.call({'action':'login',**fixture},status,**kw)
def wrong(v):return {**v,'password':'This is not the correct password.'}
clear();a=Device();initial,_=a.login(f['a']);assert initial['user']['verified'];assert next(c for c in a.jar if c.name=='hisa_session').has_nonstandard_attr('HttpOnly')
old_csrf=a.csrf;probe=Device();probe.login(f['b'],403,headers={'Origin':'https://wrong.example'});probe.login(f['b'],403,headers={'X-CSRF-Token':'invalid'});assert probe.call()[0]['user'] is None
# Four wrong attempts give a generic failure; the fifth locks even a new device with the correct password.
for n in range(4):probe.login(wrong(f['a']),401)
locked,headers=probe.login(wrong(f['a']),429);assert locked['loginLocked'] and locked['lockScope']=='email' and int(headers['Retry-After'])==locked['retryAfter']==900
peer=Device();peer.login({'email':'  '+f['a']['email'].upper()+'  ','password':f['a']['password']},429,headers={'X-Forwarded-For':'203.0.113.22'})
assert a.call()[0]['user']['email']==f['a']['email'];assert Device().login(f['b'])[0]['user']['email']==f['b']['email']
# Retry does not move the lock deadline. Expiring the opaque row models waiting without sleeping.
email=json.dumps(f['a']['email']);php("$_SERVER['REMOTE_ADDR']='127.0.0.1';foreach(signin_keys("+email+") as $k)if($k['scope']==='email')query('UPDATE rate_limits SET window_start=? WHERE bucket=?',[time()-50,$k['bucket']]);")
with sqlite3.connect(DB) as db:before=db.execute('SELECT bucket,window_start FROM rate_limits WHERE hits=5').fetchall()
r,_=peer.login(f['a'],429);assert 847<=r['retryAfter']<=850
with sqlite3.connect(DB) as db:assert db.execute('SELECT bucket,window_start FROM rate_limits WHERE hits=5').fetchall()==before
php("$_SERVER['REMOTE_ADDR']='127.0.0.1';foreach(signin_keys("+email+") as $k)if($k['scope']==='email')query('UPDATE rate_limits SET window_start=? WHERE bucket=?',[time()-901,$k['bucket']]);")
assert peer.login(f['a'])[0]['account']==initial['account'];assert peer.csrf!=old_csrf
# Non-existent accounts get the same generic errors and temporary pause.
clear();unknown={'email':'unknown-'+str(time.time_ns())+'@example.test','password':'This account does not exist.'}
for n in range(4):Device().login(unknown,401)
r,_=Device().login(unknown,429);assert r['loginLocked'] and r['retryAfter']==900
# Rotating source IPs does not evade the email guard; successful other accounts cannot clear a connection's failures.
clear();result=json.loads(php("$e='distributed@example.test';for($i=1;$i<=5;$i++){$_SERVER['REMOTE_ADDR']='192.0.2.'.$i;$r=signin_finish($e,false);}$_SERVER['REMOTE_ADDR']='198.51.100.8';echo json_encode(signin_check($e));"));assert result['retryAfter']==900 and result['lockScope']=='email'
clear()
for n in range(29):
 d=Device();d.login({'email':f'unknown-{n}-{time.time_ns()}@example.test','password':'Incorrect but well formed.'},401)
r,_=Device().login(wrong(f['b']),429);assert r['lockScope']=='connection' and r['retryAfter']==900
Device().login(f['a'],429);php("query('UPDATE rate_limits SET window_start=?',[time()-901]);");assert Device().login(f['b'])[0]['user']['verified']
# A successful sign-in clears only its own failure counter.
clear();d=Device();d.login(wrong(f['b']),401);d.login(f['b']);assert json.loads(php("$_SERVER['REMOTE_ADDR']='127.0.0.1';$a=[];foreach(signin_keys("+json.dumps(f['b']['email'])+") as $k)$a[$k['scope']]=query('SELECT hits FROM rate_limits WHERE bucket=?',[$k['bucket']])->fetchColumn();echo json_encode($a);"))=={'connection':1,'email':False}
# Existing password recovery remains available while sign-in is paused; a valid single-use reset clears its email pause.
clear();beforeReset=Device();beforeReset.login(f['b']);d=Device()
for n in range(4):d.login(wrong(f['b']),401)
d.login(wrong(f['b']),429);d.call({'action':'forgot','email':f['b']['email']})
mail=[json.loads(x) for x in (ROOT/'.local/signin-security-mail.jsonl').read_text().splitlines()];token=re.search(r'reset=([a-f0-9]{64})',next(x['body'] for x in reversed(mail) if x['to']==f['b']['email'])).group(1)
new=secrets.token_urlsafe(24);d.call({'action':'reset_password','token':token,'password':new});assert beforeReset.call()[0]['user'] is None;d.login({**f['b'],'password':new});d.call({'action':'reset_password','token':token,'password':new},400)
# New passwords require 15 characters and are never silently truncated at bcrypt's 72-byte boundary.
clear();d=Device();signup={'action':'register','name':'Security QA','email':'new-'+str(time.time_ns())+'@example.test'}
d.call({**signup,'password':'12345678901234'},400);d.call({**signup,'password':'é'*37},400);d.call({**signup,'password':'Valid test phrase 927'},201)
print('PASS: CSRF/origin rejection; legacy-password login; five-failure email pause; cross-device/case/whitespace/IP-spoof resistance; accurate retry time; fixed lock deadline; expiry recovery; unknown-user parity; distributed-attempt guard; connection pause; success counter reset; reset-link recovery and session revocation; 15-character new password policy; byte limit.')
clear()
