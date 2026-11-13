<?php
declare(strict_types=1);
require dirname(__DIR__).'/private/bootstrap.php';require dirname(__DIR__).'/private/students.php';require dirname(__DIR__).'/private/certificates.php';require_once dirname(__DIR__).'/private/academic.php';
try{
 session_begin();$user=account_user();
 if($_SERVER['REQUEST_METHOD']==='GET'){
  if(!$user||$user['verified_at']===null)reply(['csrf'=>$_SESSION['csrf'],'student'=>null,'admin'=>false]);
  $certificate=query('SELECT * FROM course_certificates WHERE user_id=?',[$user['id']])->fetch();
  reply(['certificate'=>$certificate?certificate_public($certificate):null,'csrf'=>$_SESSION['csrf'],'student'=>student_public(student_record($user)),'admin'=>student_admin($user),'superAdmin'=>campus_staff($user),'assessmentSummary'=>student_assessment_summary($user['id']),'academic'=>academic_ready()?['enabled'=>true,'collegeComplete'=>college_complete($user['id']),'collegePasses'=>array_map('intval',query('SELECT module_index FROM academic_college_passes WHERE user_id=? ORDER BY module_index',[$user['id']])->fetchAll(PDO::FETCH_COLUMN))]:['enabled'=>false]]);
 }
 if($_SERVER['REQUEST_METHOD']!=='POST')reply(['error'=>'Method not allowed.'],405);
 $body=student_request_body();if(!$user)reply(['error'=>'Sign in to view your student record.'],401);if($user['verified_at']===null)reply(['error'=>'Verify your email to receive a student number.'],403);
 $record=student_record($user);if(($body['action']??'')==='admin_overview')limited('student-admin',120,60,$user['id']);else limited('student-profile',30,3600,$user['id']);
 if(($body['action']??'')==='save_profile')reply(['student'=>student_save_profile($user,$record,$body)]);
 if(($body['action']??'')==='clear_profile')reply(['student'=>student_clear_profile($user,$body)]);
 if(($body['action']??'')==='admin_overview'){
  if(!student_admin($user))reply(['error'=>'This area is for authorised student administrators.'],403);
  $search=$body['search']??'';demand(is_string($search),'Enter a valid search.');$page=$body['page']??1;demand(is_int($page),'Choose a valid page.');reply(['overview'=>student_admin_overview($search,$page)]);
 }
 reply(['error'=>'Unknown student action.'],400);
}catch(InvalidArgumentException|JsonException $e){reply(['error'=>$e instanceof InvalidArgumentException?$e->getMessage():'Invalid request.'],400);}
catch(Throwable $e){try{if(db()->inTransaction())db()->rollBack();}catch(Throwable $ignored){}error_log('Student service unavailable: '.get_class($e));reply(['error'=>'Student records are temporarily unavailable. Your existing learning progress is safe. Please try again later.'],503);}
