<?php
declare(strict_types=1);
require dirname(__DIR__).'/private/bootstrap.php';require dirname(__DIR__).'/private/assessments.php';
try{
 session_begin();$user=account_user();$now=time();
 if($_SERVER['REQUEST_METHOD']==='GET'){
  $key=$_GET['key']??'';demand(is_string($key),'Choose an assessment.');reply(assessment_overview($key,$user,$now));
 }
 if($_SERVER['REQUEST_METHOD']!=='POST')reply(['error'=>'Method not allowed.'],405);
 $body=student_request_body();if(!$user)reply(['error'=>'Sign in to take an assessment.'],401);if($user['verified_at']===null)reply(['error'=>'Verify your email before taking an assessment.'],403);
 limited('assessment-requests',100,60,$user['id']);$action=$body['action']??'';
 if($action==='start'){
  limited('assessment-start',20,3600,$user['id']);$key=$body['key']??'';demand(is_string($key),'Choose an assessment.');$policy=assessment_retake_policy($key,$user['id'],$now);$existing=query('SELECT id FROM assessment_attempts WHERE active_key=?',[$user['id'].':'.$key])->fetchColumn();if(!$existing&&!$policy['canStart'])reply(['error'=>assessment_policy_message($policy),'retakePolicy'=>$policy],403);$row=assessment_start($key,$user,$now);reply(['attempt'=>assessment_current_payload($row,$now)]);
 }
 if($action==='answer'){
  $id=$body['attemptId']??'';demand(is_string($id),'Choose an attempt.');$result=assessment_record_answer($id,$user,$body,$now);$data=$result['data'];if(($data['attempt']['status']??'')==='submitted')$data['learning']=account_payload($user);reply($data,$result['status']);
 }
 if($action==='resume'||$action==='result'){
  $id=$body['attemptId']??'';demand(is_string($id)&&preg_match('/^[a-f0-9]{32}$/D',$id)===1,'Choose an attempt.');$row=assessment_resume($id,$user['id'],$now);if(!$row)reply(['error'=>'This attempt was not found.'],404);$data=['attempt'=>assessment_current_payload($row,$now)];if($row['submitted_at']!==null)$data['learning']=account_payload($user);reply($data);
 }
 reply(['error'=>'Unknown assessment action.'],400);
}catch(InvalidArgumentException|JsonException $e){try{if(db()->inTransaction())db()->rollBack();}catch(Throwable $ignored){}reply(['error'=>$e instanceof InvalidArgumentException?$e->getMessage():'Invalid request.'],400);}
catch(Throwable $e){try{if(db()->inTransaction())db()->rollBack();}catch(Throwable $ignored){}error_log('Assessment service unavailable: '.get_class($e));reply(['error'=>'The assessment connection is unavailable. Reconnect to check your saved position. The timer continues on the server.'],503);}
