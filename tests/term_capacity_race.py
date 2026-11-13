"""Eight concurrent enrollment requests to one disposable class; never production."""
from pathlib import Path
import os,subprocess,concurrent.futures,json
ROOT=Path(__file__).resolve().parents[1];ENV={**os.environ,'HISA_CONFIG_PATH':str(ROOT/'.local/terms-config.php'),'HISA_STUDENT_CONFIG_PATH':str(ROOT/'.local/community-student-config.php')}
base="require 'private/bootstrap.php';require 'private/assessments.php';if((settings()['environment']??'')!=='development'||!str_ends_with(settings()['dsn'],'/terms.sqlite'))throw new Exception('Isolated fixture only');db()->exec('PRAGMA busy_timeout=10000');"
# A far future virtual enrollment date makes this race independent of other QA classes.
def run(i):
 code=base+"$u=query('SELECT * FROM users ORDER BY email')->fetchAll();$eligible=array_values(array_filter($u,fn($r)=>(str_starts_with($r['email'],'term-qa-')||in_array($r['email'],['community-alice@example.test','community-bob@example.test']))));"
 # Fixture emails are read inside PHP, not printed or embedded in commands.
 code=base+"$f=json_decode(file_get_contents('.local/community-fixture.json'),true);$ids=[$f['alice']['id'],$f['bob']['id']];foreach(query('SELECT id FROM users WHERE email LIKE ? ORDER BY email',['term-qa-%@example.test'])->fetchAll(PDO::FETCH_COLUMN) as $id)$ids[]=$id;$u=query('SELECT * FROM users WHERE id=?',[$ids["+str(i)+"]])->fetch();try{$r=term_enroll($u,'speaking12',time()+200*86400,['peerConsent'=>true,'consentVersion'=>1]);echo 'saved';}catch(InvalidArgumentException $e){echo str_contains($e->getMessage(),'six-student')?'full':'invalid';}"
 r=subprocess.run(['php','-r',code],cwd=ROOT,env=ENV,capture_output=True,text=True);assert r.returncode==0,r.stderr;return r.stdout
with concurrent.futures.ThreadPoolExecutor(max_workers=8) as pool:results=list(pool.map(run,range(8)))
assert results.count('saved')==6 and results.count('full')==2,results
print('PASS: eight simultaneous applicants produced exactly six enrollments and two full-class responses.')
