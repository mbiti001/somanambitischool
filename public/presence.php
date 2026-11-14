<?php
declare(strict_types=1);
require dirname(__DIR__).'/private/bootstrap.php';require_once dirname(__DIR__).'/private/students.php';require_once dirname(__DIR__).'/private/presence.php';
try{
 session_begin();$u=account_user(false);$method=$_SERVER['REQUEST_METHOD'];
 if(!in_array($method,['GET','POST'],true))reply(['error'=>'Method not allowed.'],405);
 if(!$u||$u['verified_at']===null)reply(['verified'=>false,'error'=>'Sign in with a verified school account.'],401);
 $runtime=(string)($_SESSION['runtime_id']??'');
 if($method==='GET'){
  limited('presence-read',120,60,$u['id']);
  $raw=$_GET['ids']??'';demand(is_string($raw)&&strlen($raw)<=5100,'Choose valid online indicators.');$ids=$raw===''?[]:explode(',',$raw);
  reply(['verified'=>true,'csrf'=>$_SESSION['csrf'],'runtime'=>$runtime,'installed'=>school_presence_ready(),'online'=>(object)school_presence_status($u,$ids)]);
 }
 $b=student_request_body(5000);$action=$b['action']??'';
 if($action==='setup'){if(!campus_staff($u))reply(['error'=>'Verified school owner access required.'],403);school_presence_install();reply(['installed'=>true,'notice'=>'Online indicators enabled.']);}
 demand(in_array($action,['pulse','leave'],true),'Choose a valid online-indicator action.');
 demand(is_string($b['runtime']??null)&&hash_equals($runtime,$b['runtime']),'Your session changed. Refresh the online indicator.');
 $tab=$b['tab']??'';demand(is_string($tab)&&preg_match('/^[a-f0-9]{64}$/D',$tab)===1,'Refresh the online indicator.');
 demand(is_int($b['sequence']??null)&&$b['sequence']>0&&$b['sequence']<=2147483647,'Refresh the online indicator.');
 if(!school_presence_ready())reply(['installed'=>false,'online'=>false]);
 limited('school-presence',120,300,$u['id'].':'.$runtime);
 if($action==='leave'){school_presence_leave($runtime,$tab,$b['sequence']);reply(['online'=>false]);}
 reply(['online'=>school_presence_touch($u,$runtime,$tab,$b['sequence'])]);
}catch(InvalidArgumentException|JsonException $e){reply(['error'=>$e instanceof InvalidArgumentException?$e->getMessage():'Invalid request.'],400);}
catch(Throwable $e){error_log('Online indicator unavailable: '.get_class($e));reply(['error'=>'Online indicators are temporarily unavailable.'],503);}
