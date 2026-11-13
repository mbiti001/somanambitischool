"""HTTP security and cross-device checks against isolated localhost course fixtures."""
from pathlib import Path
import urllib.request,urllib.error,http.cookiejar,json,subprocess,os,re,secrets,uuid
ROOT=Path(__file__).resolve().parents[1];BASE='http://localhost:8798';ENV={**os.environ,'HISA_CONFIG_PATH':str(ROOT/'.local/terms-config.php'),'HISA_STUDENT_CONFIG_PATH':str(ROOT/'.local/community-student-config.php')}
def php(code):return subprocess.run(['php','-r',"require 'private/bootstrap.php';require 'private/assessments.php';if((settings()['environment']??'')!=='development'||!str_ends_with(settings()['dsn'],'/terms.sqlite'))throw new Exception('Isolated fixture only');"+code],cwd=ROOT,env=ENV,capture_output=True,text=True,check=True).stdout.strip()
fixture=json.loads((ROOT/'.local/community-fixture.json').read_text());helper=(ROOT/'tests/community_api.py').read_text();exec(helper[helper.index('class Device:'):helper.index('guest=Device();')].replace('community-mail.jsonl','terms-mail.jsonl'))
php("query('DELETE FROM rate_limits');query('DELETE FROM campus_controls');")
a=Device('alice');php("query('DELETE FROM rate_limits');");other=Device('alice');b=Device('bob');staff=Device('staff');guest=Device();ids=json.loads((ROOT/'.local/terms-ui-fixture.json').read_text());ai=ids['ai'];ph=ids['philosophy']
c=guest.call('terms.php');assert len(c['courses'])==4 and not c['enrollments'];assert '"questions"' not in json.dumps(c) and '"correct"' not in json.dumps(c)
guest.call('terms.php?view=class&offering='+ai,status=401)
b.call('terms.php?view=class&offering='+ph,status=403)
a.call('terms.php?view=office',status=403)
a.call('terms.php?view=preview&course=philosophy&week=0',status=400)
preview=staff.call('terms.php?view=preview&course=philosophy&week=0');assert preview['preview'] and 'sections' in preview['content'] and 'questions' not in preview['content']
initial=a.call('terms.php?view=class&offering='+ai+'&week=0')['practice']['version']
body={'action':'practice','offering':ai,'week':0,'notes':'QA across-device notes for the AI course.','version':initial}
a.call('terms.php',body,403,headers={'X-CSRF-Token':'wrong'})
a.call('terms.php',body);r=other.call('terms.php?view=class&offering='+ai+'&week=0');assert r['practice']['notes']==body['notes'] and r['practice']['version']==initial+1
other.call('terms.php',body,409)
r=a.call('terms.php?view=class&offering='+ph+'&week=0');assert r['practice']['version']==0 and not r['practice']['notes'];assert r['content']['readings'] and r['sources'] and 'sections' not in r['content']
# Course switch does not overwrite the first course's notes or result.
r=other.call('terms.php?view=class&offering='+ai+'&week=0');assert r['practice']['version']==initial+1 and r['statuses'][0]['passed']
# Account read-only blocks new writes without exposing another learner's data.
staff.controls('alice',read_only=True);a.call('terms.php',{**body,'version':initial+1},403);staff.controls('alice',read_only=False)
# Check all assignment URLs and private quiz structures without hitting production.
courses=json.loads((ROOT/'private/term-courses.json').read_text())
for c in courses.values():
 assert len(c['weeks'])==10 and len(c['final']['a'])==15 and len(c['final']['b'])==10
 assert all(len(w['questions'])==10 and w['readings'] and w['video']['end']>w['video'].get('start',0) for w in c['weeks'])
 assert all(k in c['sources'] and c['sources'][k]['url'].startswith('https://') for w in c['weeks'] for k in w['readings'])
 for w in c['weeks']:
  assert all(isinstance(q['correct'],bool) and q['explanation'] for q in w['questions'])
print('PASS: signed-out and unenrolled protection, professor scope, CSRF, cross-device notes, stale-version conflicts, course/result isolation, read-only enforcement, four full private banks and assigned source links.')
