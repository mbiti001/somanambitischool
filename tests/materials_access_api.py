"""Verified, subject-scoped material and practice checks; disposable flex fixture only."""
from pathlib import Path
import urllib.request,urllib.error,http.cookiejar,json,subprocess,os,re,secrets,uuid
ROOT=Path(__file__).resolve().parents[1];BASE='http://localhost:8799';ENV={**os.environ,'HISA_CONFIG_PATH':str(ROOT/'.local/flex-config.php'),'HISA_STUDENT_CONFIG_PATH':str(ROOT/'.local/community-student-config.php')}
def php(code):
 return subprocess.run(['php','-r',"require 'private/bootstrap.php';require 'private/material-access.php';if((settings()['environment']??'')!=='development'||!str_ends_with(settings()['dsn'],'/flex.sqlite'))throw new Exception('Disposable flex fixture only');"+code],cwd=ROOT,env=ENV,capture_output=True,text=True,check=True).stdout.strip()
fixture=json.loads((ROOT/'.local/community-fixture.json').read_text());helper=(ROOT/'tests/community_api.py').read_text();exec(helper[helper.index('class Device:'):helper.index('guest=Device();')].replace('community-mail.jsonl','flex-mail.jsonl'))
php("query('DELETE FROM rate_limits');query('DELETE FROM campus_controls');")
guest=Device();a=Device('alice');b=Device('bob')
status=guest.call('materials.php?view=access');assert not status['verified'] and not any(status['access'].values())
guest.call('materials.php?course=investing',status=401);guest.call('academic.php?view=college',status=401);guest.call('speaking/content.php',status=401)
guest.call('tutor.php',{'question':'What is a share?','lesson':0,'unit':0},401)
for url in ['course.js','course.json','speaking/content.js','speaking/content.json','speaking/index.html']:
 raw,_=guest.call(url,raw=True);assert b'retired' in raw.lower() or b'index.php' in raw.lower();assert len(raw)<500
assert len(a.call('materials.php?course=investing')['course']['lessons'])==18
# An enrollment in another subject must not open investing material.
ids=json.loads((ROOT/'.local/flex-ui-fixture.json').read_text());bobid=fixture['bob']['id']
php("$f=json_decode(file_get_contents('.local/community-fixture.json'),true);query(\"UPDATE term_enrollments SET status='unenrolled_inactive' WHERE user_id=? AND offering_id IN (SELECT id FROM term_offerings WHERE course_id='investing12')\",[$f['bob']['id']]);")
assert not b.call('materials.php?view=access')['access']['investing'];b.call('materials.php?course=investing',status=403)
php("$f=json_decode(file_get_contents('.local/community-fixture.json'),true);query(\"UPDATE term_enrollments SET status='enrolled' WHERE user_id=? AND offering_id IN (SELECT id FROM term_offerings WHERE course_id='investing12')\",[$f['bob']['id']]);query('DELETE FROM rate_limits');")
# A fresh verified fixture has no implicit course enrollment or teacher permission.
raw=php("$f=json_decode(file_get_contents('.local/community-fixture.json'),true);$u=query('SELECT * FROM users WHERE email=?',['flex-unenrolled@example.test'])->fetch();if(!$u){$u=query('SELECT * FROM users WHERE id=?',[$f['alice']['id']])->fetch();$u['id']=bin2hex(random_bytes(16));$u['email']='flex-unenrolled@example.test';$u['display_name']='Unenrolled QA';query('INSERT INTO users ('.implode(',',array_keys($u)).') VALUES ('.implode(',',array_fill(0,count($u),'?')).')',array_values($u));query('INSERT INTO learning_accounts (user_id,state_json,updated_at) VALUES (?,?,?)',[$u['id'],json_encode(fresh_state()),gmdate('c')]);}query('DELETE FROM campus_enrollments WHERE user_id=?',[$u['id']]);echo json_encode(['id'=>$u['id'],'email'=>$u['email']]);")
fixture['outsider']={**json.loads(raw),'password':fixture['alice']['password']};outsider=Device('outsider')
outsider.call('materials.php?course=investing',status=403);outsider.call('speaking/content.php',status=403)
r=outsider.call('academic.php?view=college',status=403);assert r['foundationEnrollmentRequired']
outsider.call('api.php',{'action':'trade','version':1,'side':'buy','s':'SCOM','q':1,'expectedEmail':fixture['outsider']['email']},403)
outsider.call('academic.php',{'action':'enroll_foundation','consent':True});course=outsider.call('academic.php?view=college')['course'];assert len(course['modules'])==4 and all('questions' not in m for m in course['modules'])
# Account-bound writes reject a stale student's payload before changing state.
a.call('api.php',{'action':'reset','version':1,'expectedEmail':fixture['bob']['email']},409)
print('PASS: no anonymous materials/practice/hints, retired static payloads, subject-scoped access, explicit verified foundation enrollment and stale-account write protection.')
