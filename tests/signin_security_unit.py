"""Concurrency and session-boundary checks in an isolated local security database."""
from pathlib import Path
import subprocess,os,json,concurrent.futures
root=Path(__file__).resolve().parents[1];env={**os.environ,'HISA_CONFIG_PATH':str(root/'.local/signin-security-config.php')}
prefix="require 'private/bootstrap.php';require 'private/signin-security.php';if(!str_ends_with(settings()['dsn'],'/signin-security.sqlite'))throw new Exception('Disposable database required');"
def run(code):
 p=subprocess.run(['php','-r',prefix+code],cwd=root,env=env,capture_output=True,text=True,check=True);return p
run("$_SERVER['REMOTE_ADDR']='192.0.2.99';foreach(signin_keys('parallel@example.test') as $k)query('DELETE FROM rate_limits WHERE bucket=?',[$k['bucket']]);")
with concurrent.futures.ThreadPoolExecutor(max_workers=8) as pool:
 results=list(pool.map(lambda n:run("$_SERVER['REMOTE_ADDR']='192.0.2.99';echo json_encode(signin_finish('parallel@example.test',false));"),range(12)))
waits=[json.loads(r.stdout)['retryAfter'] for r in results];assert waits.count(0)==4 and all(898<=w<=900 for w in waits if w)
row=json.loads(run("$_SERVER['REMOTE_ADDR']='192.0.2.99';foreach(signin_keys('parallel@example.test') as $k)if($k['scope']==='email')echo json_encode(query('SELECT hits FROM rate_limits WHERE bucket=?',[$k['bucket']])->fetch());").stdout);assert row['hits']==5
assert all('parallel@example.test' not in r.stderr and '192.0.2.99' not in r.stderr for r in results)
# Use an isolated session store. No browser session is read or changed.
store=root/'.local/security-unit-sessions';store.mkdir(exist_ok=True);store.chmod(0o700)
code="ini_set('session.save_path',"+json.dumps(str(store))+");session_begin();$_SESSION['user_id']='synthetic';$_SESSION['last_seen']=time()-7200;$_SESSION['authenticated_at']=time();session_write_close();session_begin();if(isset($_SESSION['user_id']))throw new Exception('Idle session not expired');$_SESSION['user_id']='synthetic';$_SESSION['last_seen']=time();$_SESSION['authenticated_at']=time()-28800;session_write_close();session_begin();if(isset($_SESSION['user_id']))throw new Exception('Absolute session not expired');$_SESSION['user_id']='legacy';unset($_SESSION['authenticated_at']);session_write_close();session_begin();if(!isset($_SESSION['authenticated_at']))throw new Exception('Legacy session not bounded');echo 'PASS';"
assert run(code).stdout=='PASS'
print('PASS: twelve concurrent attempts allow only four ordinary failures before the five-attempt pause, lock count stays capped, audit logs omit raw credentials/identifiers, two-hour idle and eight-hour absolute expiry, legacy sessions acquire an absolute deadline.')
