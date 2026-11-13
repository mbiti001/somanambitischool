<?php
/* Role boundaries use a fresh temporary database and synthetic accounts only.
 * No .local fixture, production configuration or real student record is opened. */
$reviewDir=sys_get_temp_dir().'/soma-role-review-'.bin2hex(random_bytes(6));mkdir($reviewDir,0700);
$reviewConfig=$reviewDir.'/config.php';$reviewRoles=$reviewDir.'/roles.php';
file_put_contents($reviewConfig,'<?php return '.var_export(['environment'=>'development','site_url'=>'http://localhost','app_key'=>str_repeat('review-only-',4),'dsn'=>'sqlite:'.$reviewDir.'/roles.sqlite'],true).';');chmod($reviewConfig,0600);
file_put_contents($reviewRoles,'<?php return '.var_export(['super_admin_emails'=>['owner@example.invalid']],true).';');chmod($reviewRoles,0600);
putenv('HISA_CONFIG_PATH='.$reviewConfig);putenv('HISA_CAMPUS_CONFIG_PATH='.$reviewRoles);
require dirname(__DIR__).'/private/bootstrap.php';require dirname(__DIR__).'/private/terms.php';
function reviewCheck(bool $ok,string $message):void{if(!$ok)throw new RuntimeException($message);}
function reviewDenied(callable $action,string $message):void{try{$action();}catch(InvalidArgumentException $e){return;}throw new RuntimeException($message);}
try{
 db()->exec("CREATE TABLE users(id TEXT PRIMARY KEY,email TEXT,display_name TEXT,verified_at TEXT);CREATE TABLE term_offerings(id TEXT PRIMARY KEY,course_id TEXT,start_date TEXT,created_at TEXT);CREATE TABLE term_enrollments(id TEXT PRIMARY KEY,offering_id TEXT,user_id TEXT,status TEXT);CREATE TABLE term_professors(offering_id TEXT,user_id TEXT,assigned_at TEXT,PRIMARY KEY(offering_id,user_id));CREATE TABLE term_papers(id TEXT PRIMARY KEY,enrollment_id TEXT,week_index INTEGER,task_kind TEXT,revision INTEGER,status TEXT,teacher_status TEXT,rubric_json TEXT,feedback TEXT,version INTEGER,reviewed_at TEXT);CREATE TABLE term_batches(id TEXT PRIMARY KEY,offering_id TEXT);CREATE TABLE term_peer_assignments(id TEXT PRIMARY KEY,batch_id TEXT,paper_id TEXT,submitted_at TEXT);CREATE TABLE term_certificates(id TEXT PRIMARY KEY,enrollment_id TEXT);CREATE TABLE assessment_attempts(id TEXT PRIMARY KEY,user_id TEXT,assessment_key TEXT,passed INTEGER,submitted_at TEXT,paper_json TEXT,result_json TEXT,score INTEGER,maximum INTEGER);CREATE TABLE campus_audit(id INTEGER PRIMARY KEY,actor_id TEXT,action TEXT,subject TEXT,detail_json TEXT,created_at TEXT);");
 db()->exec("CREATE TABLE term_peer_routes(paper_id TEXT PRIMARY KEY,batch_id TEXT,outcome TEXT,reason TEXT,decided_by TEXT,decided_at TEXT);ALTER TABLE term_papers ADD COLUMN originality INTEGER DEFAULT 0;ALTER TABLE term_papers ADD COLUMN peer_consent INTEGER DEFAULT 0;ALTER TABLE term_papers ADD COLUMN consent_version INTEGER DEFAULT 0;ALTER TABLE term_papers ADD COLUMN submitted_at TEXT;");
 $student=['id'=>'student','email'=>'student@example.invalid','display_name'=>'Synthetic Learner','verified_at'=>gmdate('c')];$owner=['id'=>'owner','email'=>'owner@example.invalid','display_name'=>'Synthetic Owner','verified_at'=>gmdate('c')];$other=['id'=>'other','email'=>'other@example.invalid','display_name'=>'Synthetic Classmate','verified_at'=>gmdate('c')];$unverified=['id'=>'unverified','email'=>'unverified@example.invalid','display_name'=>'Unverified','verified_at'=>null];
 foreach([$student,$owner,$other,$unverified] as $u)query('INSERT INTO users VALUES (?,?,?,?)',array_values($u));
 foreach(['11111111','22222222'] as $off)query('INSERT INTO term_offerings VALUES (?,?,?,?)',[$off,'philosophy','2026-11-16',gmdate('c')]);
 query('INSERT INTO term_enrollments VALUES (?,?,?,?)',['own','11111111','student','enrolled']);query('INSERT INTO term_enrollments VALUES (?,?,?,?)',['peer','11111111','other','enrolled']);query('INSERT INTO term_enrollments VALUES (?,?,?,?)',['outside','22222222','other','enrolled']);
 foreach([['own-paper','own'],['peer-paper','peer'],['outside-paper','outside']] as [$id,$en])query('INSERT INTO term_papers (id,enrollment_id,week_index,task_kind,revision,status,teacher_status,rubric_json,feedback,version,reviewed_at) VALUES (?,?,0,?,1,?,?,NULL,NULL,1,NULL)',[$id,$en,'opening','submitted','pending']);
 $grant=['offering'=>'11111111','email'=>$student['email'],'enabled'=>true,'reason'=>'Synthetic owner explicitly clears this class professor.'];
 reviewCheck(!term_professor($student,'11111111'),'Course enrollment alone granted teacher access.');
 reviewDenied(fn()=>term_assign_professor($student,$grant+['role'=>'super_admin','superAdmin'=>true]),'Student granted their own teacher access.');
 reviewCheck((int)query('SELECT COUNT(*) FROM term_professors')->fetchColumn()===0,'Unauthorized grant changed role records.');
 reviewDenied(fn()=>term_assign_professor($owner,[...$grant,'email'=>$unverified['email']]),'Unverified target received private teacher access.');
 reviewDenied(fn()=>term_curriculum_preview($student,'philosophy',0),'Student opened global faculty curriculum preview.');
 term_assign_professor($owner,$grant);
 reviewCheck(term_professor($student,'11111111'),'Owner-authorized class professor was not recognized.');reviewCheck(!term_professor($student,'22222222'),'Professor access escaped its assigned class.');
 reviewDenied(fn()=>term_assign_professor($student,[...$grant,'offering'=>'22222222']),'Class professor expanded their own authority.');
 $rubric=array_fill_keys(TERM_RUBRIC,3);$body=['version'=>1,'decision'=>'accepted','rubric'=>$rubric,'feedback'=>'The synthetic response meets the stated rubric criteria.'];
 reviewDenied(fn()=>term_teacher_review($student,[...$body,'paper'=>'own-paper'],time()),'A cleared professor graded their own student work.');
 reviewDenied(fn()=>term_teacher_review($student,[...$body,'paper'=>'outside-paper'],time()),'A cleared professor graded outside their assigned class.');
 term_teacher_review($student,[...$body,'paper'=>'peer-paper'],time());reviewCheck(query('SELECT teacher_status FROM term_papers WHERE id=?',['peer-paper'])->fetchColumn()==='accepted','Assigned professor could not review class work.');
 reviewDenied(fn()=>term_set_extension($student,['enrollment'=>'own','week'=>0,'until'=>time()+3600,'reason'=>'A sufficiently detailed synthetic extension explanation.'],time()),'Professor extended their own assessed deadline.');
 query('INSERT INTO term_batches VALUES (?,?)',['batch','11111111']);query('INSERT INTO term_peer_assignments VALUES (?,?,?,?)',['review','batch','own-paper',gmdate('c')]);
 reviewDenied(fn()=>term_correct_peer($student,['assignment'=>'review','rubric'=>$rubric,'reason'=>'A sufficiently detailed synthetic correction explanation.'],time()),'Professor corrected marks on their own paper.');
 term_assign_professor($owner,[...$grant,'enabled'=>false]);reviewCheck(!term_professor($student,'11111111'),'Revoked role remained effective.');
 reviewDenied(fn()=>term_teacher_review($student,[...$body,'paper'=>'peer-paper','version'=>2],time()),'Revoked professor retained marking permission.');
 reviewCheck((int)query("SELECT COUNT(*) FROM campus_audit WHERE action='term_professor_role'")->fetchColumn()===2,'Grant/revocation audit is missing.');
 echo "PASS: student self-grants and forged role flags rejected; verified owner clearance is class-scoped, audited and revocable; own-paper marking, own-deadline extensions and outside-class marking rejected.\n";
}finally{foreach(glob($reviewDir.'/*')?:[] as $file)unlink($file);rmdir($reviewDir);}
