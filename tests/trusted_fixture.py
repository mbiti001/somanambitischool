"""Provision a trusted LOCAL QA browser for legacy, non-two-step regression tests.
Never use this helper in the two_step tests; those exercise real email-code checks.
"""
from pathlib import Path
import urllib.parse,urllib.request,http.cookiejar,subprocess,os,json,secrets

def trusted_fixture(opener, root, base, email):
 if not isinstance(email,str):return
 url=urllib.parse.urlparse(base)
 if url.hostname not in ['localhost','127.0.0.1']:raise RuntimeError('Trusted fixtures are localhost-only')
 config={8787:'config.php',8791:'full-study-config.php',8792:'signin-security-config.php',8793:'two-step-config.php'}.get(url.port)
 if not config:raise RuntimeError('Unrecognised local test server')
 root=Path(root);raw=secrets.token_hex(32)
 program="""require 'private/bootstrap.php';$c=settings();if(($c['environment']??'')!=='development'||!str_starts_with($c['dsn'],'sqlite:'.getcwd().'/.local/'))throw new Exception('Disposable development SQLite required');$v=json_decode(file_get_contents('php://stdin'),true);$u=query('SELECT * FROM users WHERE email=?',[strtolower(trim($v['email']))])->fetch();if($u){$h=hash_hmac('sha256','browser|'.$v['raw'].'|'.$u['session_version'],$c['app_key']);query('INSERT INTO auth_tokens (token_hash,user_id,purpose,expires_at) VALUES (?,?,?,?)',[$h,$u['id'],'device',time()+3600]);}"""
 subprocess.run(['php','-r',program],input=json.dumps({'email':email,'raw':raw}),cwd=root,env={**os.environ,'HISA_CONFIG_PATH':str(root/'.local'/config)},check=True,capture_output=True,text=True)
 jar=next(h.cookiejar for h in opener.handlers if isinstance(h,urllib.request.HTTPCookieProcessor));session=next(c for c in jar if c.name=='hisa_session')
 jar.set_cookie(http.cookiejar.Cookie(0,'hisa_device',raw,None,False,session.domain,session.domain_specified,session.domain_initial_dot,'/',True,False,None,True,None,None,{'HttpOnly':None},False))
