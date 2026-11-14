"""Academic pathway checks against disposable localhost fixture only."""
from pathlib import Path
import urllib.request,urllib.error,http.cookiejar,json,subprocess,os,re,secrets
ROOT=Path(__file__).resolve().parents[1];BASE='http://localhost:8796';ENV={**os.environ,'HISA_CONFIG_PATH':str(ROOT/'.local/community-config.php'),'HISA_STUDENT_CONFIG_PATH':str(ROOT/'.local/community-student-config.php')}
def php(code):
 return subprocess.run(['php','-r',"require 'private/bootstrap.php';require 'private/academic.php';if((settings()['environment']??'')!=='development'||!str_ends_with(settings()['dsn'],'/community.sqlite'))throw new Exception('Isolated QA only');"+code],cwd=ROOT,env=ENV,capture_output=True,text=True,check=True).stdout.strip()
fixture=json.loads((ROOT/'.local/community-fixture.json').read_text())
# Login helper is reused without importing destructive campus test cases.
helper=(ROOT/'tests/community_api.py').read_text();exec(helper[helper.index('class Device:'):helper.index('guest=Device();')])
php("academic_install();query('DELETE FROM rate_limits');query('DELETE FROM academic_drafts');query('DELETE FROM academic_submissions');query('DELETE FROM academic_college_passes');query('DELETE FROM academic_integrity_acceptance');query('DELETE FROM campus_controls');query(\"DELETE FROM assessment_attempts WHERE assessment_key LIKE 'college-%'\");")
a=Device('alice');php("query('DELETE FROM rate_limits');");other=Device('alice');b=Device('bob');staff=Device('staff');guest=Device()
guest.call('academic.php?view=college',status=401)
for learner in [a,b]:learner.call('academic.php',{'action':'enroll_foundation','consent':True})
public=a.call('academic.php?view=college');assert len(public['course']['modules'])==4 and all('questions' not in m for m in public['course']['modules'])
guest.call('academic.php?view=task&course=college&module=0',status=401)
a.call('academic.php?view=review',status=403);a.call('academic.php',{'action':'install'},403)
r=a.call('academic.php?view=task&course=college&module=0');assert r['available'] and r['studentNumber'].startswith('SMB-')
def doc(n):
 return {'title':'QA paper: learning with a plan','introduction':'Baldwin (2020) discusses learning.', 'body':' '.join(['thinking']*(n-8)), 'conclusion':'A considered plan helps.', 'references':'Baldwin, A. (2020). College success. OpenStax. https://openstax.org/books/college-success/pages/1-introduction','assistance':'QA fixture, not real academic work.'}
# 5 words in introduction (Baldwin,2020,discusses,learning =4), 4 conclusion? A,considered,plan,helps =4: total8.
assert json.loads(php("echo json_encode(academic_words('Baldwin (2020) discusses learning. A considered plan helps.'));"))==8
base={'course':'college','module':0,'task':'opening'}
def save(device,document,version=0,submit=False,status=200,**extra):return device.call('academic.php',{'action':'submit' if submit else 'draft',**base,'document':document,'draftVersion':version,'original':True,'policyVersion':2,**extra},status)
save(a,doc(299),submit=True,status=400);save(a,doc(300),submit=True,status=400,original=False)
draft=save(a,doc(300))['record'];assert draft['tasks']['opening']['draft']['version']==1
assert other.call('academic.php?view=task&course=college&module=0')['tasks']['opening']['draft']['document']['body']==doc(300)['body']
save(other,doc(301),status=409)
a.call('academic.php',{'action':'draft',**base,'document':doc(300),'draftVersion':1},403,headers={'X-CSRF-Token':'bad'})
sub=save(a,doc(300),1,True)['record']['tasks']['opening']['submission'];assert sub['words']==300 and sub['status']=='pending'
save(a,doc(301),status=400);b.call('academic.php?view=submission&id='+sub['id'],status=404)
assert b.call('academic.php?view=task&course=college&module=0')['tasks']['opening']['submission'] is None
rubric={k:True for k in ['originality','reasoning','structure','evidence','apa']}
def review(device,sub,decision='accepted',status=200,**kw):return device.call('academic.php',{'action':'review','id':sub['id'],'status':decision,'feedback':'QA teacher reviewed all criteria and source use.','rubric':rubric,**kw},status)
review(a,sub,status=403);review(staff,sub,status=400,rubric={**rubric,'apa':False});review(staff,sub,'revision')
revision=save(a,doc(310),0,True)['record']['tasks']['opening']['submission'];assert revision['revision']==2
review(staff,revision,'integrity_review');save(a,doc(320),submit=True);latest=a.call('academic.php?view=task&course=college&module=0')['tasks']['opening']['submission'];review(staff,latest)
# Closing boundaries, both accepted before exam, and sequencing of foundation modules.
base['task']='closing';save(a,doc(501),submit=True,status=400);closing=save(a,doc(500),submit=True)['record']['tasks']['closing']['submission']
assert a.call('assessments.php?key=college-0')['eligible'] is False
review(staff,closing);assert a.call('assessments.php?key=college-0')['eligible'] is True
# A teacher may not review their own submission.
base['task']='opening';own=save(staff,doc(300),submit=True)['record']['tasks']['opening']['submission'];review(staff,own,status=400)
# Start server-timed exam, no solution until full attempt ends. Correct fixture answers are read privately from local bank.
attempt=a.call('assessments.php',{'action':'start','key':'college-0'})['attempt'];assert 'correct' not in json.dumps(attempt)
bank=json.loads((ROOT/'private/college-course.json').read_text());answers={q['id']:q['correct'] for q in bank['modules'][0]['questions']}
while attempt['status']!='submitted':
 q=attempt['question'];r=a.call('assessments.php',{'action':'answer','attemptId':attempt['id'],'version':attempt['version'],'questionId':q['id'],'value':answers[q['id']]});attempt=r['attempt']
assert attempt['result']['passed'];assert a.call('academic.php?view=task&course=college&module=1')['available'];assert not a.call('academic.php?view=task&course=college&module=2')['available']
a.call('academic.php?view=task&course=investing&module=0',status=403)
# Finish remaining foundation through backend fixtures: objective passes do not grant subject writing acceptance.
php("$f=json_decode(file_get_contents('.local/community-fixture.json'),true);foreach([1,2,3] as $i)query('INSERT INTO academic_college_passes (user_id,module_index,passed_at) VALUES (?,?,?)',[$f['alice']['id'],$i,gmdate('c')]);")
php("require_once 'private/terms.php';terms_install();$f=json_decode(file_get_contents('.local/community-fixture.json'),true);$u=query('SELECT * FROM users WHERE id=?',[$f['alice']['id']])->fetch();term_enroll($u,'investing12',time(),['consentVersion'=>TERM_CONSENT_VERSION,'peerConsent'=>true]);")
a.call('academic.php?view=task&course=investing&module=0',status=403) # New enrollment uses the current edition, without inventing historical access.
assert not a.call('assessments.php?key=module-0')['eligible']
assert a.call('community.php?view=gradebook')['grades']['academic']['collegeComplete']
# Existing restriction middleware protects new writing endpoint.
staff.controls('alice',read_only=True);save(a,doc(300),status=403);staff.controls('alice',read_only=False)
base['task']='opening';save(b,doc(300),submit=True)
print('PASS: private drafts across devices, conflicts/CSRF, word limits, integrity acknowledgment, review rubric/roles/self-review, revision and holds, sanitized authorized curriculum and guest denial, timed College exam, foundation/module gates, gradebook and account restrictions.')
