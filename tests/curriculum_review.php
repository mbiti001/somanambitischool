<?php
// Synthetic identities, an in-memory SQLite database and a disposable role config.
// Optional source-root argument supports reviewing an unmerged implementation.
declare(strict_types=1);
$sourceRoot=$argv[1]??dirname(__DIR__);
$testDb=new PDO('sqlite::memory:');$testDb->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);$testDb->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE,PDO::FETCH_ASSOC);
function db():PDO{global $testDb;return $testDb;}
function query(string $sql,array $args=[]):PDOStatement{$s=db()->prepare($sql);$s->execute($args);return $s;}
function demand(bool $ok,string $message):void{if(!$ok)throw new InvalidArgumentException($message);}
function text_length(string $text):int{return preg_match_all('/./us',$text);}
function settings():array{return ['app_key'=>str_repeat('synthetic-only-key-',3)];}
final class ReviewReply extends RuntimeException{public function __construct(public array $payload,public int $status){parent::__construct('Synthetic reply');}}
function reply(array $data,int $status=200):never{throw new ReviewReply($data,$status);}
function check_review(bool $ok,string $why):void{if(!$ok)throw new RuntimeException($why);}
function denied_review(callable $call,int $status):void{try{$call();}catch(ReviewReply $r){check_review($r->status===$status,'Unexpected denial status');return;}throw new RuntimeException('Expected access denial');}
function invalid_review(callable $call):void{try{$call();}catch(InvalidArgumentException $e){return;}throw new RuntimeException('Expected invalid context rejection');}
$roles=tempnam(sys_get_temp_dir(),'soma-review-roles-');chmod($roles,0600);file_put_contents($roles,'<?php return '.var_export(['super_admin_emails'=>['owner@example.invalid','owner2@example.invalid']],true).';');putenv('HISA_CAMPUS_CONFIG_PATH='.$roles);
try{
 require $sourceRoot.'/private/campus-policy.php';require $sourceRoot.'/private/curriculum-review.php';require $sourceRoot.'/private/course-library.php';
 db()->exec('PRAGMA foreign_keys=ON;CREATE TABLE users(id TEXT PRIMARY KEY,email TEXT,verified_at TEXT);CREATE TABLE campus_audit(id INTEGER PRIMARY KEY,actor_id TEXT,action TEXT,subject TEXT,detail_json TEXT,created_at TEXT);CREATE TABLE campus_enrollments(user_id TEXT,course_id TEXT);CREATE TABLE academic_college_passes(user_id TEXT,module_index INTEGER);');
 $users=[];foreach(['owner','owner2','student','professor','unverified'] as $id){$u=['id'=>$id,'email'=>($id==='unverified'?'owner':$id).'@example.invalid','verified_at'=>$id==='unverified'?null:'2026-11-14'];$users[$id]=$u;query('INSERT INTO users VALUES (?,?,?)',array_values($u));}
 denied_review(fn()=>curriculum_review_guard(null),401);denied_review(fn()=>curriculum_review_guard($users['unverified']),401);denied_review(fn()=>curriculum_review_guard($users['student']+['superAdmin'=>true]),403);denied_review(fn()=>curriculum_review_guard($users['professor']),403);curriculum_review_guard($users['owner']);
 check_review(!curriculum_review_ready(),'GET should not install tables');
 foreach(['college'=>curriculum_course('college')]+term_courses() as $id=>$c){foreach(array_keys($c['weeks']) as $m){$d=curriculum_review_payload($users['owner'],$id,(string)$m);check_review($d['content']['title']===$c['weeks'][$m]['title'],'Module content mismatch');check_review(!empty($d['content']['questions']),'Owner must see module questions');check_review(array_key_exists('correct',$d['content']['questions'][0]),'Owner must see marking keys');foreach($d['content']['questions'] as $q)check_review(in_array('question-'.$q['id'],curriculum_context($id,(string)$m)['keys'],true),'Question page missing from allowed notes');}if(isset($c['final'])){check_review(count(curriculum_review_payload($users['owner'],$id,'final')['content']['questions'])===25,'Owner final paper incomplete');}}
 check_review(!curriculum_review_ready(),'Read-only review unexpectedly changed schema');
 foreach([['../../config','0'],['college','final'],['investing12','-1'],['investing12','10'],['investing12','1x']] as [$id,$m])invalid_review(fn()=>curriculum_context($id,$m));
 $b=['course'=>'creative-writing-ai','module'=>'0','page'=>'overview','note'=>'A specific private suggestion <script> is plain text.','version'=>0];
 $saved=curriculum_save_note($users['owner'],$b);check_review($saved['version']===1,'First version wrong');$b['version']=1;$b['note']='Revised private suggestion.';$saved=curriculum_save_note($users['owner'],$b);check_review($saved['version']===2,'Second version wrong');
 check_review(count(curriculum_notes('owner','creative-writing-ai',0))===1,'Latest note should hide superseded revisions');check_review(curriculum_notes('owner','creative-writing-ai',0)['overview']['text']===$b['note'],'Latest text mismatch');check_review(curriculum_notes('owner2','creative-writing-ai',0)===[],'Another reviewer can see private notes');
 $b['version']=1;denied_review(fn()=>curriculum_save_note($users['owner'],$b),409);check_review((int)query('SELECT COUNT(*) FROM curriculum_review_notes')->fetchColumn()===2,'Stale overwrite inserted revision');check_review(!db()->inTransaction(),'Conflict left transaction open');
 $other=$b;$other['version']=0;$other['page']='practice';check_review(curriculum_save_note($users['owner'],$other)['version']===1,'Independent suggestion should start at version 1');$other['page']='overview';check_review(curriculum_save_note($users['owner2'],$other)['version']===1,'Reviewer-specific version incorrectly shared');
 foreach([['page'=>'question-unknown'],['page'=>'guide-999'],['version'=>'2'],['note'=>str_repeat('x',12001)],['note'=>"bad\0text"],['note'=>"\xff"]] as $bad)invalid_review(fn()=>curriculum_save_note($users['owner'],array_replace($b,$bad)));
 check_review((int)query('SELECT COUNT(*) FROM curriculum_review_notes')->fetchColumn()===4,'Invalid notes changed history');check_review((int)query('SELECT COUNT(*) FROM campus_audit')->fetchColumn()===4,'Saved suggestions should be audited');
 // Library access uses genuine enrollment rows, not forged browser role flags.
 db()->exec(str_replace(' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci','',file_get_contents($sourceRoot.'/database/course-terms.sql')));
 query('INSERT INTO term_offerings(id,course_id,start_date,created_at) VALUES (?,?,?,?)',['12345678','creative-writing-ai','2026-11-16','2026-11-14']);query('INSERT INTO term_offerings(id,course_id,start_date,created_at) VALUES (?,?,?,?)',['abcdef12','philosophy','2026-11-16','2026-11-14']);
 check_review(!course_library_allowed($users['unverified'],'creative-writing-ai'),'Unverified account obtained library');check_review(course_library($users['student'],null)['courses']===[],'Unenrolled student lists private libraries');denied_review(fn()=>course_library($users['student'],'creative-writing-ai'),403);
 query('INSERT INTO term_enrollments(id,offering_id,user_id,consent_version,enrolled_at) VALUES (?,?,?,?,?)',['enrollment','12345678','student',2,'2026-11-14']);$library=course_library($users['student'],'creative-writing-ai');check_review(count($library['modules'])===10&&!empty($library['sources']),'Legitimate learner cannot open own library');denied_review(fn()=>course_library($users['student'],'philosophy'),403);invalid_review(fn()=>course_library($users['student'],'../../private/config'));
 query("UPDATE term_enrollments SET status='unenrolled_inactive' WHERE id='enrollment'");denied_review(fn()=>course_library($users['student'],'creative-writing-ai'),403);query("INSERT INTO term_professors VALUES ('12345678','professor','2026-11-14')");check_review(course_library_allowed($users['professor'],'creative-writing-ai'),'Scoped professor cannot open own subject library');check_review(!course_library_allowed($users['professor'],'philosophy'),'Professor can open unrelated library');denied_review(fn()=>curriculum_review_guard($users['professor']),403);
 check_review(count(course_library($users['owner'],null)['courses'])===count(term_courses())+1,'Verified owner lacks full library access');
 // API guard order is a deliberate invariant: helpers are internal and do not authenticate.
 $api=file_get_contents($sourceRoot.'/public/course-review.php');check_review(strpos($api,'curriculum_review_guard($u)')<strpos($api,'curriculum_review_payload('),'Review GET precedes owner guard');check_review(strpos($api,'student_request_body(')<strpos($api,'curriculum_save_note('),'Suggestion save/install precedes CSRF parsing');
 echo "PASS: existing verified owner only; all course modules/finals retain review keys; private versioned notes and conflict rollback; invalid contexts/pages rejected; legitimate enrollment/scoped faculty library only; no role grant or shared database touched.\n";
}finally{if(is_file($roles))unlink($roles);putenv('HISA_CAMPUS_CONFIG_PATH');}
