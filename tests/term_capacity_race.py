"""Eight concurrent applicants to one temporary SQLite intake, with no app config."""
from pathlib import Path
import os,subprocess,concurrent.futures,tempfile,sqlite3
ROOT=Path(__file__).resolve().parents[1]
with tempfile.TemporaryDirectory(prefix='soma-capacity-') as tmp:
 path=Path(tmp);database=path/'race.sqlite';con=sqlite3.connect(database)
 con.executescript('CREATE TABLE users (id TEXT PRIMARY KEY,email TEXT,display_name TEXT,verified_at TEXT); CREATE TABLE academic_college_passes (user_id TEXT,module_index INTEGER); CREATE TABLE campus_audit (actor_id TEXT,action TEXT,subject TEXT,detail_json TEXT,created_at TEXT); CREATE TABLE assessment_attempts (id TEXT,user_id TEXT,assessment_key TEXT,passed INTEGER,submitted_at TEXT);')
 con.executescript((ROOT/'database/course-terms.sql').read_text().replace(' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci',''))
 for i in range(8):
  con.execute('INSERT INTO users VALUES (?,?,?,?)',(f'learner{i}',f'learner{i}@example.test',f'Learner {i}','2026-11-16T00:00:00+00:00'))
  con.executemany('INSERT INTO academic_college_passes VALUES (?,?)',[(f'learner{i}',m) for m in range(4)])
 con.commit();con.close()
 terms=str(ROOT/'private/terms.php').replace('\\','\\\\').replace("'","\\'")
 worker=path/'worker.php';worker.write_text("""<?php
 declare(strict_types=1);
 function db():PDO{static $db;if(!$db){$db=new PDO('sqlite:'.getenv('SOMA_RACE_DB'));$db->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);$db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE,PDO::FETCH_ASSOC);$db->exec('PRAGMA busy_timeout=15000');}return $db;}
 function query(string $sql,array $p=[]):PDOStatement{$s=db()->prepare($sql);$s->execute($p);return $s;}
 function demand(bool $ok,string $m):void{if(!$ok)throw new InvalidArgumentException($m);}
 require '"""+terms+"""';
 if(getenv('SOMA_RACE_MODE')==='route'){$off=query('SELECT * FROM term_offerings LIMIT 1')->fetch();echo term_allocate_batch($off,0,'opening',strtotime('2026-11-16T13:00:00+03:00'))?'routed':'none';exit;}
 $u=query('SELECT * FROM users WHERE id=?',[getenv('SOMA_RACE_USER')])->fetch();try{term_enroll($u,'speaking12',strtotime('2026-11-16T12:00:00+03:00'),['peerConsent'=>true,'consentVersion'=>TERM_CONSENT_VERSION]);echo 'saved';}catch(InvalidArgumentException $e){echo str_contains($e->getMessage(),'six-student')?'full':'invalid';}
 """)
 def run(i):
  env={**os.environ,'SOMA_RACE_DB':str(database),'SOMA_RACE_USER':f'learner{i}'}
  r=subprocess.run(['php',str(worker)],cwd=ROOT,env=env,capture_output=True,text=True);assert r.returncode==0,r.stderr;return r.stdout
 with concurrent.futures.ThreadPoolExecutor(max_workers=8) as pool:results=list(pool.map(run,range(8)))
 assert results.count('saved')==6 and results.count('full')==2,results
 con=sqlite3.connect(database);assert con.execute('SELECT COUNT(*) FROM term_offerings').fetchone()[0]==1;assert con.execute('SELECT COUNT(*) FROM term_enrollments').fetchone()[0]==6
 for i,(en,) in enumerate(con.execute('SELECT id FROM term_enrollments ORDER BY id LIMIT 2').fetchall()):
  con.execute('INSERT INTO term_papers (id,enrollment_id,week_index,task_kind,revision,document_json,word_count,status,originality,peer_consent,consent_version,teacher_status,updated_at,submitted_at) VALUES (?,?,0,?,1,?,300,?,1,1,2,?,?,?)',(f'paper{i}',en,'opening','{}','submitted','pending','2026-11-16T09:00:00+00:00','2026-11-16T09:00:00+00:00'))
 con.commit();con.close()
 def sweep(_):
  env={**os.environ,'SOMA_RACE_DB':str(database),'SOMA_RACE_MODE':'route'}
  r=subprocess.run(['php',str(worker)],cwd=ROOT,env=env,capture_output=True,text=True);assert r.returncode==0,r.stderr;return r.stdout
 with concurrent.futures.ThreadPoolExecutor(max_workers=4) as pool:routes=list(pool.map(sweep,range(4)))
 assert routes.count('routed')==1 and routes.count('none')==3,routes
 con=sqlite3.connect(database);assert con.execute('SELECT COUNT(*) FROM term_batches').fetchone()[0]==1;assert con.execute('SELECT COUNT(*) FROM term_peer_routes').fetchone()[0]==2;assert con.execute('SELECT COUNT(*) FROM term_peer_assignments').fetchone()[0]==2;con.close()
print('PASS: eight simultaneous applicants produced six seats; four concurrent peer sweeps produced one batch with exactly two routes and two reviews. No shared configuration or database changed.')
