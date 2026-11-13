<?php
declare(strict_types=1);
require_once __DIR__.'/terms.php';
function legacy_speaking_catalogue():array{static $c;return $c??=json_decode(file_get_contents(__DIR__.'/legacy-speaking-course.json'),true,128,JSON_THROW_ON_ERROR);}
function material_missing_table(PDOException $e):bool{return (bool)preg_match('/no such table|doesn.t exist/i',$e->getMessage());}
function material_historical(array $u,string $course):bool{
 $id=$u['id'];
 try{if(query('SELECT 1 FROM term_legacy_access WHERE user_id=? AND course_id=?',[$id,$course])->fetchColumn())return true;}catch(PDOException $e){if(!material_missing_table($e))throw $e;}
 if($course==='college'){
  if(query('SELECT 1 FROM campus_enrollments WHERE user_id=? AND course_id=?',[$id,'college'])->fetchColumn())return true;
  if(query('SELECT 1 FROM academic_college_passes WHERE user_id=? LIMIT 1',[$id])->fetchColumn())return true;
 }
 if(academic_ready()){
  if(query('SELECT 1 FROM academic_submissions WHERE user_id=? AND course_id=? LIMIT 1',[$id,$course])->fetchColumn())return true;
  $drafts=query('SELECT document_json FROM academic_drafts WHERE user_id=? AND course_id=?',[$id,$course])->fetchAll(PDO::FETCH_COLUMN);foreach($drafts as $raw){$doc=json_decode($raw,true);if(trim(implode(' ',array_filter($doc??[],'is_string')))!=='')return true;}
 }
 if($course==='investing'){
  $s=json_decode(query('SELECT state_json FROM learning_accounts WHERE user_id=?',[$id])->fetchColumn()?:'{}',true);
  if(!empty($s['completed'])||!empty($s['modulePasses'])||!empty($s['history'])||!empty($s['journal']))return true;foreach($s['unitPasses']??[] as $passes)if(!empty($passes))return true;
  return (bool)query("SELECT 1 FROM assessment_attempts WHERE user_id=? AND assessment_key NOT LIKE 'college-%' AND assessment_key NOT LIKE 't-%' LIMIT 1",[$id])->fetchColumn();
 }
 if($course==='speaking'){
  if(query('SELECT 1 FROM speaking_quiz_results WHERE user_id=? LIMIT 1',[$id])->fetchColumn())return true;
  $s=json_decode(query('SELECT state_json FROM speaking_accounts WHERE user_id=?',[$id])->fetchColumn()?:'{}',true);foreach($s['modules']??[] as $m)if(!empty($m['passed'])||!empty($m['notes'])||!empty($m['bestScore']))return true;
 }
 return false;
}
function material_access(?array $u,string $course):bool{
 if(!$u||$u['verified_at']===null||!in_array($course,['college','investing','speaking'],true))return false;
 if(campus_staff($u))return true;if(material_historical($u,$course))return true;
 if($course==='college'||!terms_ready())return false;
 $id=$course==='investing'?'investing12':'speaking12';
 if(query("SELECT 1 FROM term_enrollments e JOIN term_offerings o ON o.id=e.offering_id WHERE e.user_id=? AND o.course_id=? AND e.status<>'unenrolled_inactive' LIMIT 1",[$u['id'],$id])->fetchColumn())return true;
 return (bool)query('SELECT 1 FROM term_professors p JOIN term_offerings o ON o.id=p.offering_id WHERE p.user_id=? AND o.course_id=? LIMIT 1',[$u['id'],$id])->fetchColumn();
}
function material_require(?array $u,string $course):void{
 if(!$u)reply(['error'=>'Sign in to study or practise. Guest practice is not available.','authenticationRequired'=>true],401);
 if($u['verified_at']===null)reply(['error'=>'Verify your email before enrolling, studying or practising.','verificationRequired'=>true],403);
 if(!material_access($u,$course))reply(['error'=>'Enroll in the appropriate course before opening its lessons or practice.','enrollmentRequired'=>true,'foundationEnrollmentRequired'=>$course==='college'],403);
}
function foundation_enroll(array $u,array $b):void{
 demand($u['verified_at']!==null,'Verify your email before enrolling.');demand(($b['consent']??false)===true,'Confirm enrollment in the required College Success foundation.');
 if(!campus_member($u['id'],'college')){try{query('INSERT INTO campus_enrollments (user_id,course_id,joined_at) VALUES (?,?,?)',[$u['id'],'college',gmdate('c')]);campus_audit($u,'foundation_enroll','college',['course'=>'College Success']);}catch(PDOException $e){if(!in_array((string)$e->getCode(),['23000','23505'],true))throw $e;}}
}
