<?php
// In-memory database only. No hosting configuration, account or shared fixture is used.
declare(strict_types=1);
$testDb=new PDO('sqlite::memory:');$testDb->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);$testDb->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE,PDO::FETCH_ASSOC);
function query(string $sql,array $params=[]):PDOStatement{global $testDb;$stmt=$testDb->prepare($sql);$stmt->execute($params);return $stmt;}
require dirname(__DIR__).'/private/terms.php';
function ensure_progress(bool $ok,string $message):void{if(!$ok)throw new RuntimeException($message);}
$empty=term_progress_summary([],false,false);ensure_progress($empty['masteryPercent']===0&&$empty['acceptedWriting']===0&&!$empty['completed'],'Empty enrollment must not imply progress.');
$rows=[];for($i=0;$i<10;$i++)$rows[]=['week'=>$i,'passed'=>true,'opening'=>'accepted','closing'=>'accepted'];
$full=term_progress_summary($rows,false,false);ensure_progress($full['masteryPercent']===100&&$full['weeklyPassed']===10&&$full['acceptedWriting']===20&&!$full['finalPassed']&&!$full['completed'],'100% teaching mastery is not final course completion.');
$final=term_progress_summary($rows,true,true);ensure_progress(!$final['completed'],'A passed final without an issued record must remain completion-pending.');
$award=term_progress_summary($rows,true,true,true);ensure_progress($award['completed'],'An issued course completion must remain visible.');
$rows[]=['week'=>11,'passed'=>true,'opening'=>'accepted','closing'=>'accepted'];$rows[]=['week'=>0,'passed'=>true,'opening'=>'accepted','closing'=>'accepted'];
ensure_progress(term_progress_summary($rows,true,true)['masteryPercent']===100,'Duplicates or non-teaching weeks cannot inflate progress.');
query('CREATE TABLE assessment_attempts (id TEXT,user_id TEXT,assessment_key TEXT,passed INTEGER,submitted_at TEXT)');query('CREATE TABLE term_papers (id TEXT,enrollment_id TEXT,week_index INTEGER,task_kind TEXT,teacher_status TEXT,status TEXT,revision INTEGER)');query('CREATE TABLE term_certificates (enrollment_id TEXT)');
foreach([['one','alice','t-deadbeef-w01',1,'2026-11-14'],['duplicate','alice','t-deadbeef-w01',1,'2026-11-15'],['retry','alice','t-deadbeef-w01',0,'2026-11-16'],['active','alice','t-deadbeef-w02',1,null],['other-user','bob','t-deadbeef-w02',1,'2026-11-14'],['other-course','alice','t-feedface-w02',1,'2026-11-14'],['invalid-week','alice','t-deadbeef-w11',1,'2026-11-14']] as $row)query('INSERT INTO assessment_attempts VALUES (?,?,?,?,?)',$row);
foreach([['o1','enrollment',0,'opening','accepted','submitted',1],['o2','enrollment',0,'opening','revision','submitted',2],['draft','enrollment',0,'closing','accepted','draft',2],['c1','enrollment',0,'closing','accepted','submitted',1],['other','another-enrollment',1,'opening','accepted','submitted',1]] as $row)query('INSERT INTO term_papers VALUES (?,?,?,?,?,?,?)',$row);
$progress=term_progress_for_enrollment(['id'=>'enrollment','offering_id'=>'deadbeef'],'alice');ensure_progress($progress['masteryPercent']===10&&$progress['acceptedWriting']===1,'Progress must use own submitted passes and latest submitted writing, ignoring drafts, failures and other courses.');
ensure_progress(!$progress['midPassed']&&!$progress['finalPassed']&&!$progress['completed'],'Milestones must not be inferred from weekly credit.');
query('INSERT INTO assessment_attempts VALUES (?,?,?,?,?)',['mid','alice','t-deadbeef-m',1,'2026-11-14']);query('INSERT INTO assessment_attempts VALUES (?,?,?,?,?)',['final','alice','t-deadbeef-f',1,'2026-11-14']);query('INSERT INTO term_certificates VALUES (?)',['enrollment']);
$progress=term_progress_for_enrollment(['id'=>'enrollment','offering_id'=>'deadbeef'],'alice');ensure_progress($progress['midPassed']&&$progress['finalPassed']&&$progress['completed'],'Saved milestones must be separate, authentic records.');
echo "PASS: 0–100% teaching mastery, separate final/completion milestones, latest accepted writing, no duplicate inflation and per-student/per-course isolation.\n";
