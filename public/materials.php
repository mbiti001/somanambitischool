<?php
declare(strict_types=1);
require dirname(__DIR__).'/private/bootstrap.php';require_once dirname(__DIR__).'/private/material-access.php';
try{
 session_begin();header('Cache-Control: private, no-store');header('Vary: Cookie');$u=account_user();
 if($_SERVER['REQUEST_METHOD']!=='GET')reply(['error'=>'Method not allowed.'],405);
 if(($_GET['view']??'')==='access')reply(['csrf'=>$_SESSION['csrf'],'signedIn'=>(bool)$u,'verified'=>(bool)($u&&$u['verified_at']!==null),'access'=>['investing'=>material_access($u,'investing'),'speaking'=>material_access($u,'speaking'),'college'=>material_access($u,'college')],'teacherCleared'=>campus_staff($u)||($u&&$u['verified_at']!==null&&terms_ready()&&(bool)query('SELECT 1 FROM term_professors WHERE user_id=? LIMIT 1',[$u['id']])->fetchColumn())]);
 $course=$_GET['course']??'';demand(is_string($course)&&in_array($course,['investing','speaking'],true),'Choose a valid course.');material_require($u,$course);reply(['csrf'=>$_SESSION['csrf'],'course'=>$course==='investing'?course_catalog():legacy_speaking_catalogue()]);
}catch(InvalidArgumentException $e){reply(['error'=>$e->getMessage()],400);}catch(Throwable $e){error_log('Protected course catalogue unavailable: '.get_class($e));reply(['error'=>'Course materials are temporarily unavailable. Please retry.'],503);}
