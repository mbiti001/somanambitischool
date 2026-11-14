"""Clock-controlled disposable session checks: no real one-hour sleep or production access."""
from pathlib import Path
import urllib.request,urllib.error,http.cookiejar,json,subprocess,os,re,secrets,uuid
ROOT=Path(__file__).resolve().parents[1];BASE='http://localhost:8799';ENV={**os.environ,'HISA_CONFIG_PATH':str(ROOT/'.local/flex-config.php'),'HISA_STUDENT_CONFIG_PATH':str(ROOT/'.local/community-student-config.php')}
def php(code,data=None):
 prefix="require 'private/bootstrap.php';if((settings()['environment']??'')!=='development'||!str_ends_with(settings()['dsn'],'/flex.sqlite'))throw new Exception('Disposable flex fixture only');"
 return subprocess.run(['php','-r',prefix+code],input=data,cwd=ROOT,env=ENV,capture_output=True,text=True,check=True).stdout.strip()
fixture=json.loads((ROOT/'.local/community-fixture.json').read_text());helper=(ROOT/'tests/community_api.py').read_text();exec(helper[helper.index('class Device:'):helper.index('guest=Device();')].replace('community-mail.jsonl','flex-mail.jsonl'))
def clock(d,idle,absolute=None):
 sid=next(c.value for c in d.jar if c.name=='hisa_session')
 php("$b=json_decode(stream_get_contents(STDIN),true);session_name('hisa_session');session_id($b['sid']);session_start();if(empty($_SESSION['user_id']))throw new Exception('QA session required');$_SESSION['user_activity_at']=time()-$b['idle'];if($b['absolute']!==null)$_SESSION['authenticated_at']=time()-$b['absolute'];session_write_close();",json.dumps({'sid':sid,'idle':idle,'absolute':absolute}))
php("query('DELETE FROM rate_limits');query('DELETE FROM campus_controls');");a=Device('alice');php("query('DELETE FROM rate_limits');");b=Device('alice')
clock(a,3590);first=a.call('session.php');assert first['authenticated'] and first['session']['idleSeconds']==3600
a.call('materials.php?view=access');again=a.call('session.php');assert again['session']['idleDeadline']==first['session']['idleDeadline']
renewed=a.call('session.php',{'action':'activity'});assert renewed['session']['idleDeadline']>=renewed['session']['serverNow']+3599
clock(a,3600);a.call('materials.php?course=investing',status=401);assert not a.call('session.php')['authenticated'];a.call('session.php',{'action':'activity'},401)
assert b.call('session.php')['authenticated'];assert len(b.call('materials.php?course=investing')['course']['lessons'])==18
clock(b,0,28800);ended=b.call('session.php');assert not ended['authenticated'] and ended['sessionExpired']=='absolute'
php("query('DELETE FROM rate_limits');");c=Device('alice');c.call('session.php',{'action':'activity'},403,headers={'X-CSRF-Token':'bad'});c.call('api.php',{'action':'logout'});assert not c.call('session.php')['authenticated'];assert c.call('api.php')['user'] is None
print('PASS: passive polls do not renew one-hour access; trusted activity endpoint renews; expired protected reads/heartbeats fail; another browser stays independent; absolute cap, CSRF and explicit logout hold.')
