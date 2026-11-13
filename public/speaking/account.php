<?php
declare(strict_types=1);
require dirname(__DIR__,2).'/private/bootstrap.php';require dirname(__DIR__,2).'/private/students.php';require dirname(__DIR__,2).'/private/speaking.php';require_once dirname(__DIR__,2).'/private/academic.php';require_once dirname(__DIR__,2).'/private/terms.php';
try{
 session_begin();$user=account_user();$publicUser=$user?['name'=>$user['display_name'],'email'=>$user['email'],'verified'=>$user['verified_at']!==null]:null;
 if($_SERVER['REQUEST_METHOD']==='GET'){
  if(!$user||$user['verified_at']===null)reply(['csrf'=>$_SESSION['csrf'],'user'=>$publicUser,'record'=>null]);
  limited('speaking-read',180,60,$user['id']);student_record($user);
  if(isset($_GET['module'])){$i=filter_var($_GET['module'],FILTER_VALIDATE_INT);demand($i!==false&&$i>=0&&$i<6,'Choose a valid module.');$page=max(1,min(100000,(int)($_GET['page']??1)));$offset=($page-1)*20;$rows=query('SELECT * FROM speaking_quiz_results WHERE user_id=? AND module_index=? ORDER BY submitted_at DESC,id DESC LIMIT 20 OFFSET '.$offset,[$user['id'],$i])->fetchAll();$total=(int)query('SELECT COUNT(*) FROM speaking_quiz_results WHERE user_id=? AND module_index=?',[$user['id'],$i])->fetchColumn();reply(['csrf'=>$_SESSION['csrf'],'user'=>$publicUser,'results'=>array_map('speaking_result',$rows),'total'=>$total,'page'=>$page]);}
  reply(['csrf'=>$_SESSION['csrf'],'user'=>$publicUser,'record'=>speaking_payload(speaking_row($user['id']))]);
 }
 if($_SERVER['REQUEST_METHOD']!=='POST')reply(['error'=>'Method not allowed.'],405);
 $body=student_request_body(1000000);if(!$user)reply(['error'=>'Sign in to save speaking progress.','signedOut'=>true],401);if($user['verified_at']===null)reply(['error'=>'Verify your email to save online.'],403);
 if(($body['email']??'')!==$user['email'])reply(['error'=>'The signed-in account changed. Refresh before saving.','accountChanged'=>true],409);
 limited('speaking-write',300,300,$user['id']);$response=speaking_write($user,$body);reply($response['data']+['user'=>$publicUser,'csrf'=>$_SESSION['csrf']],$response['status']);
}catch(InvalidArgumentException|JsonException $e){reply(['error'=>$e instanceof InvalidArgumentException?$e->getMessage():'Choose valid JSON data.'],400);}
catch(Throwable $e){if(isset($user)&&$user)error_log('Speaking account service unavailable: '.get_class($e));reply(['error'=>'Online saving is temporarily unavailable. Keep this page open and retry; your unsaved draft remains here.'],503);}
