<?php
declare(strict_types=1);
function step_email_hint(string $email):string{[$name,$domain]=explode('@',$email,2);return substr($name,0,1).'•••@'.$domain;}
function step_public():?array{
 $p=$_SESSION['signin_step']??null;if(!$p)return null;
 if($p['deadline']<=time()){unset($_SESSION['signin_step']);return null;}
 return ['challengeId'=>$p['id'],'emailHint'=>step_email_hint($p['email']),'expiresIn'=>max(0,($p['expires']??0)-time()),'resendAfter'=>max(0,($p['resend_at']??0)-time()),'deadlineIn'=>$p['deadline']-time(),'hasCode'=>isset($p['code_hash'])&&($p['expires']??0)>time()];
}
function step_cookie(string $value,int $expires):void{setcookie('hisa_device',$value,['expires'=>$expires,'path'=>'/','secure'=>str_starts_with(settings()['site_url'],'https://'),'httponly'=>true,'samesite'=>'Lax']);}
function step_device_hash(string $raw,array $u):string{return hash_hmac('sha256','browser|'.$raw.'|'.$u['session_version'],settings()['app_key']);}
function step_trusted(array $u):bool{
 $raw=$_COOKIE['hisa_device']??'';if(!is_string($raw)||!preg_match('/^[a-f0-9]{64}$/D',$raw))return false;
 // Reading a valid browser token does not renew its fixed 30-day expiry.
 return (bool)query('SELECT token_hash FROM auth_tokens WHERE token_hash=? AND user_id=? AND purpose=? AND expires_at>?',[step_device_hash($raw,$u),$u['id'],'device',time()])->fetch();
}
function step_remember(array $u):void{
 $raw=bin2hex(random_bytes(32));$expires=time()+2592000;
 query('DELETE FROM auth_tokens WHERE user_id=? AND purpose=? AND expires_at<=?',[$u['id'],'device',time()]);
 query('INSERT INTO auth_tokens (token_hash,user_id,purpose,expires_at) VALUES (?,?,?,?)',[step_device_hash($raw,$u),$u['id'],'device',$expires]);step_cookie($raw,$expires);
}
function step_mail_quota(array $u):int{
 $now=time();$keys=[['bucket'=>hash_hmac('sha256','step-cooldown|'.$u['id'],settings()['app_key']),'max'=>1,'seconds'=>60],['bucket'=>hash_hmac('sha256','step-hour|'.$u['id'],settings()['app_key']),'max'=>5,'seconds'=>3600]];
 usort($keys,fn($a,$b)=>strcmp($a['bucket'],$b['bucket']));db()->beginTransaction();
 try{$mysql=db()->getAttribute(PDO::ATTR_DRIVER_NAME)==='mysql';$rows=[];$wait=0;
  foreach($keys as $key){query($mysql?'INSERT INTO rate_limits (bucket,window_start,hits) VALUES (?,?,0) ON DUPLICATE KEY UPDATE bucket=VALUES(bucket)':'INSERT INTO rate_limits (bucket,window_start,hits) VALUES (?,?,0) ON CONFLICT(bucket) DO UPDATE SET bucket=excluded.bucket',[$key['bucket'],$now]);$r=query('SELECT * FROM rate_limits WHERE bucket=?'.($mysql?' FOR UPDATE':''),[$key['bucket']])->fetch();$rows[$key['bucket']]=$r;if((int)$r['hits']>=$key['max'])$wait=max($wait,(int)$r['window_start']+$key['seconds']-$now);}
  if($wait>0){db()->commit();return $wait;}
  foreach($keys as $key){$r=$rows[$key['bucket']];$expired=(int)$r['window_start']+$key['seconds']<=$now;query('UPDATE rate_limits SET hits=?,window_start=? WHERE bucket=?',[$expired?1:(int)$r['hits']+1,$expired?$now:$r['window_start'],$key['bucket']]);}
  db()->commit();return 0;
 }catch(Throwable $e){if(db()->inTransaction())db()->rollBack();throw $e;}
}
function step_code_hash(array $p,string $code):string{return hash_hmac('sha256',$p['id'].'|'.$p['user_id'].'|'.$p['session_version'].'|'.$code,settings()['app_key']);}
function step_send(array $u):void{
 if(($_SESSION['signin_step']['deadline']??0)<=time()){unset($_SESSION['signin_step']);reply(['error'=>'This sign-in has expired. Enter your password again.','restartLogin'=>true],401);}
 limited('step-mail-connection',60,3600);$wait=step_mail_quota($u);
 if($wait){$_SESSION['signin_step']['resend_at']=time()+$wait;header('Retry-After: '.$wait);reply(['error'=>'Please wait before requesting another sign-in email. Use the latest code already sent, or try again later.','mailLimited'=>true,'retryAfter'=>$wait,'twoStep'=>step_public(),'csrf'=>$_SESSION['csrf']],429);}
 $p=$_SESSION['signin_step'];do{$code=str_pad((string)random_int(0,999999),6,'0',STR_PAD_LEFT);}while(isset($p['code_hash'])&&hash_equals($p['code_hash'],step_code_hash($p,$code)));$expiry=min(time()+600,$p['deadline']);$_SESSION['signin_step']['resend_at']=time()+60;
 try{$sent=send_account_mail($u['email'],'Your Soma na Mbiti sign-in code',"Your sign-in code is:\n\n".$code."\n\nIt expires in ".max(1,(int)ceil(($expiry-time())/60))." minutes. Enter it only at ".rtrim(settings()['site_url'],'/').". Never share this code.\n\nIf you did not try to sign in, someone may know your password. Open the website yourself and use Forgot password to change it.\n\nSoma na Mbiti");}catch(Throwable $e){$sent=false;error_log('Sign-in code delivery failed: '.get_class($e));}
 if($expiry<=time()){unset($_SESSION['signin_step']);reply(['error'=>'This sign-in has expired. Enter your password again.','restartLogin'=>true],401);}
 if(!$sent)reply(['error'=>'We could not send your code. Check your connection and use Resend code after the wait.','twoStep'=>step_public(),'csrf'=>$_SESSION['csrf']],503);
 $_SESSION['signin_step']['code_hash']=step_code_hash($p,$code);$_SESSION['signin_step']['expires']=$expiry;
 reply(['twoStep'=>step_public(),'csrf'=>$_SESSION['csrf'],'notice'=>'A sign-in code has been sent to your email.']);
}
function step_begin(array $u):never{
 $_SESSION=[];session_regenerate_id(false);$_SESSION=['csrf'=>bin2hex(random_bytes(32)),'last_seen'=>time(),'signin_step'=>['id'=>bin2hex(random_bytes(16)),'user_id'=>$u['id'],'email'=>$u['email'],'session_version'=>(int)$u['session_version'],'deadline'=>time()+900,'attempts'=>0,'resend_at'=>0]];
 step_send($u);exit;
}
function step_pending(array $body):array{
 $p=$_SESSION['signin_step']??null;
 if(!$p||$p['deadline']<=time()){unset($_SESSION['signin_step']);reply(['error'=>'This sign-in has expired. Enter your password again.','restartLogin'=>true],401);}
 if(!is_string($body['challengeId']??null)||!hash_equals($p['id'],$body['challengeId']))reply(['error'=>'The sign-in changed in another tab. Start again.','restartLogin'=>true],409);
 $u=query('SELECT * FROM users WHERE id=?',[$p['user_id']])->fetch();
 if(!$u||(int)$u['session_version']!==$p['session_version']){unset($_SESSION['signin_step']);reply(['error'=>'Your account security changed. Enter your password again.','restartLogin'=>true],401);}
 return $u;
}
function step_check(array $body):never{
 $u=step_pending($body);$wait=signin_check($u['email']);
 if($wait['retryAfter']){unset($_SESSION['signin_step']);header('Retry-After: '.$wait['retryAfter']);reply(['error'=>'Sign-in is temporarily paused after unsuccessful security checks. Use Forgot password or try again after the wait.','restartLogin'=>true,'loginLocked'=>true]+$wait,429);}
 limited('step-code-connection',30,900);$p=$_SESSION['signin_step'];
 if(!isset($p['code_hash'])||($p['expires']??0)<=time())reply(['error'=>'The code has expired or has not been sent. Request a new code.','twoStep'=>step_public()],400);
 $code=$body['code']??null;$valid=is_string($code)&&preg_match('/^[0-9]{6}$/D',$code)===1&&hash_equals($p['code_hash'],step_code_hash($p,$code));
 if(!$valid){$_SESSION['signin_step']['attempts']++;$wait=signin_finish($u['email'],false);
  if($wait['retryAfter']||$_SESSION['signin_step']['attempts']>=5){unset($_SESSION['signin_step']);header('Retry-After: '.max(1,$wait['retryAfter']));reply(['error'=>'Too many incorrect security checks. Enter your password again after the wait, or use Forgot password.','restartLogin'=>true,'loginLocked'=>true]+$wait,429);}
  reply(['error'=>'That code is incorrect. Use the latest six-digit code in your email.','twoStep'=>step_public()],400);
 }
 signin_block(signin_finish($u['email'],true));
 // Recheck the account version under the user-row lock before granting access.
 db()->beginTransaction();try{$lock=db()->getAttribute(PDO::ATTR_DRIVER_NAME)==='mysql'?' FOR UPDATE':'';$fresh=query('SELECT * FROM users WHERE id=?'.$lock,[$u['id']])->fetch();
  if(!$fresh||(int)$fresh['session_version']!==$p['session_version']){db()->rollBack();unset($_SESSION['signin_step']);reply(['error'=>'Your account security changed. Enter your password again.','restartLogin'=>true],401);}
  if($fresh['verified_at']===null){$fresh['verified_at']=gmdate('c');query('UPDATE users SET verified_at=? WHERE id=?',[$fresh['verified_at'],$fresh['id']]);query('DELETE FROM auth_tokens WHERE user_id=? AND purpose=?',[$fresh['id'],'verify']);}
  if(($body['rememberBrowser']??false)===true)step_remember($fresh);db()->commit();
 }catch(Throwable $e){if(db()->inTransaction())db()->rollBack();throw $e;}
 auth_session($fresh);reply(account_payload($fresh)+['notice'=>'Email code checked. You are signed in.']);
}
