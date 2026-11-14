<?php
declare(strict_types=1);
require dirname(__DIR__).'/private/bootstrap.php';require_once dirname(__DIR__).'/private/students.php';
try{
 session_begin();$u=account_user(false);$method=$_SERVER['REQUEST_METHOD'];
 if($method==='POST'){$b=student_request_body(2000);if(!$u)reply(['csrf'=>$_SESSION['csrf'],'authenticated'=>false,'verified'=>false,'session'=>null,'error'=>'Your session has ended. Sign in again to continue.'],401);demand(($b['action']??'')==='activity','Choose a valid session action.');limited('session-activity',120,300,$u['id'].':'.($_SESSION['runtime_id']??''));if(!school_session_touch($_SESSION,time()))reply(['authenticated'=>false,'session'=>null,'error'=>'Your session has ended.'],401);}
 elseif($method!=='GET')reply(['error'=>'Method not allowed.'],405);
 reply(['csrf'=>$_SESSION['csrf'],'authenticated'=>(bool)$u,'verified'=>(bool)($u&&$u['verified_at']!==null),'session'=>$u?school_session_metadata($_SESSION,time()):null,'sessionExpired'=>$_SESSION['session_expired']??null]);
}catch(InvalidArgumentException|JsonException $e){reply(['error'=>'Refresh your session and try again.'],400);}catch(Throwable $e){error_log('Session check unavailable: '.get_class($e));reply(['error'=>'Your session could not be checked. Please reconnect.'],503);}
