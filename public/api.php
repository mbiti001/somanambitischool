<?php
declare(strict_types=1);
require dirname(__DIR__).'/private/bootstrap.php';
require_once dirname(__DIR__).'/private/academic.php';require_once dirname(__DIR__).'/private/terms.php';require_once dirname(__DIR__).'/private/material-access.php';
require dirname(__DIR__).'/private/signin-security.php';
require dirname(__DIR__).'/private/two-step.php';
try {
    session_begin();
    if($_SERVER['REQUEST_METHOD']==='GET'){$payload=account_payload(account_user());if(!$payload['user'])$payload['twoStep']=step_public();reply($payload);}
    if($_SERVER['REQUEST_METHOD']!=='POST')reply(['error'=>'Method not allowed.'],405);
    if(($_SERVER['HTTP_ORIGIN']??'')!==rtrim(settings()['site_url'],'/')||($_SERVER['HTTP_SEC_FETCH_SITE']??'')==='cross-site')reply(['error'=>'Please submit this request from Hisa Classroom.'],403);
    if(!str_starts_with($_SERVER['CONTENT_TYPE']??'','application/json'))reply(['error'=>'Expected JSON.'],415);
    if((int)($_SERVER['CONTENT_LENGTH']??0)>180000)reply(['error'=>'Request too large.'],413);
    $raw=file_get_contents('php://input',false,null,0,180001);if(strlen($raw)>180000)reply(['error'=>'Request too large.'],413);
    try{$b=json_decode($raw,true,512,JSON_THROW_ON_ERROR);}catch(JsonException $e){reply(['error'=>'Invalid request.'],400);}
    if(!is_array($b))reply(['error'=>'Invalid request.'],400);
    if(!hash_equals($_SESSION['csrf'],$_SERVER['HTTP_X_CSRF_TOKEN']??''))reply(['error'=>'Your session changed. Refresh the page and try again.','reload'=>true],403);
    $action=$b['action']??'';
    if($action==='register'){
        limited('register',10,3600);
        $email=email_input($b['email']??null);$password=password_input($b['password']??null);$name=$b['name']??'';demand(is_string($name),'Enter your name.');$name=trim($name);
        demand(text_length($name)>=1&&text_length($name)<=80,'Enter your name (up to 80 characters).');
        if(query('SELECT id FROM users WHERE email = ?',[$email])->fetch())reply(['error'=>'Unable to create this account. Try signing in or resetting your password.'],400);
        $u=['id'=>bin2hex(random_bytes(16)),'email'=>$email,'display_name'=>$name,'session_version'=>1];$now=gmdate('c');
        db()->beginTransaction();
        query('INSERT INTO users (id,email,display_name,password_hash,session_version,created_at) VALUES (?,?,?,?,1,?)',[$u['id'],$email,$name,password_hash($password,PASSWORD_DEFAULT),$now]);
        query('INSERT INTO learning_accounts (user_id,state_json,version,updated_at) VALUES (?,?,1,?)',[$u['id'],json_encode(fresh_state(),JSON_THROW_ON_ERROR),$now]);
        db()->commit();auth_session($u);$sent=issue_token($u,'verify');
        $u['verified_at']=null;reply(account_payload($u)+['notice'=>$sent?'Check your email for your verification link.':'Your account was created, but we could not send your email. Use Resend verification to try again.'],201);
    }
    if($action==='login'){
        limited('login-requests-v2',60,900);
        signin_block(signin_check(''));
        try{$email=email_input($b['email']??null);}catch(InvalidArgumentException $e){signin_block(signin_finish('',false));reply(['error'=>'Enter a valid email address.'],400);}
        signin_block(signin_check($email));
        $password=$b['password']??'';
        $u=query('SELECT * FROM users WHERE email = ?',[$email])->fetch();
        $dummy='$2y$12$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2uheWG/igi.';
        $format=is_string($password)&&strlen($password)<=72&&!str_contains($password,"\0");
        $valid=password_verify($format?$password:'invalid-signin-input',$u['password_hash']??$dummy)&&$format&&$u!==false;
        if(!$valid){signin_block(signin_finish($email,false));reply(['error'=>'Email or password is incorrect.'],401);}
        campus_guard($u,false);
        if(password_needs_rehash($u['password_hash'],PASSWORD_DEFAULT))query('UPDATE users SET password_hash = ? WHERE id = ?',[password_hash($password,PASSWORD_DEFAULT),$u['id']]);
        if(step_trusted($u)){signin_block(signin_finish($email,true));auth_session($u);reply(account_payload($u));}
        step_begin($u);
    }
    if($action==='verify_login_code')step_check($b);
    if($action==='resend_login_code'){$stepUser=step_pending($b);step_send($stepUser);}
    if($action==='cancel_login'){step_pending($b);unset($_SESSION['signin_step']);reply(account_payload(null));}
    if($action==='logout'){
        try{school_presence_end((string)($_SESSION['runtime_id']??''));}catch(Throwable $ignored){}
        $_SESSION=[];session_regenerate_id(true);$_SESSION['csrf']=bin2hex(random_bytes(32));reply(account_payload(null));
    }
    if($action==='forgot'){
        limited('forgot',5,3600);$email=email_input($b['email']??null);$u=query('SELECT * FROM users WHERE email = ?',[$email])->fetch();
        if($u&&!issue_token($u,'reset'))error_log('Hisa Classroom: password reset delivery failed');
        reply(['notice'=>'If an account exists for that address, a password reset link has been sent. Check your inbox and spam folder.']);
    }
    if($action==='reset_password'){
        limited('reset-password',10,3600);$password=password_input($b['password']??null);$token=$b['token']??'';
        demand(is_string($token)&&preg_match('/^[a-f0-9]{64}$/D',$token)===1,'This reset link is invalid or expired.');
        db()->beginTransaction();$lock=db()->getAttribute(PDO::ATTR_DRIVER_NAME)==='mysql'?' FOR UPDATE':'';
        $t=query('SELECT * FROM auth_tokens WHERE token_hash = ? AND purpose = ? AND expires_at > ?'.$lock,[hash('sha256',$token),'reset',time()])->fetch();
        if(!$t){db()->rollBack();reply(['error'=>'This reset link is invalid or expired. Request a new one.'],400);}
        query('UPDATE users SET password_hash = ?, session_version = session_version + 1 WHERE id = ?',[password_hash($password,PASSWORD_DEFAULT),$t['user_id']]);
        $resetEmail=query('SELECT email FROM users WHERE id=?',[$t['user_id']])->fetchColumn();foreach(signin_keys((string)$resetEmail) as $key)if($key['scope']==='email')query('DELETE FROM rate_limits WHERE bucket=?',[$key['bucket']]);
        query('DELETE FROM auth_tokens WHERE user_id = ? AND purpose IN (?,?)',[$t['user_id'],'reset','device']);db()->commit();
        step_cookie('',time()-3600);
        $_SESSION=[];session_regenerate_id(true);$_SESSION['csrf']=bin2hex(random_bytes(32));reply(['csrf'=>$_SESSION['csrf'],'notice'=>'Password updated. Sign in with your new password.']);
    }
    $u=account_user();if(!$u)reply(['error'=>'Sign in to save your progress.','signedOut'=>true],401);
    if($action==='forget_browsers'){query('DELETE FROM auth_tokens WHERE user_id=? AND purpose=?',[$u['id'],'device']);step_cookie('',time()-3600);reply(['notice'=>'Remembered browsers removed. Future sign-ins will require an email code. Current study sessions stay open.']);}
    if($action==='resend'){
        limited('resend',3,3600,$u['id']);
        if($u['verified_at']!==null)reply(['notice'=>'Your email is already verified.']);
        if(!issue_token($u,'verify'))reply(['error'=>'Email could not be sent. Please try again later.'],503);
        reply(['notice'=>'Verification email sent. Check your inbox and spam folder.']);
    }
    if($u['verified_at']===null)reply(['error'=>'Verify your email before saving account progress.','unverified'=>true],403);
    material_require($u,'investing');
    if(($b['expectedEmail']??null)!==$u['email'])reply(['error'=>'The signed-in account changed. Reopen the form before saving.','accountChanged'=>true,'conflict'=>true],409);
    $row=query('SELECT state_json,version,updated_at FROM learning_accounts WHERE user_id = ?',[$u['id']])->fetch();
    if(!is_int($b['version']??null)||$b['version']!==(int)$row['version'])reply(['error'=>'Your account changed on another device. The latest progress has been loaded. Please review and try again.','conflict'=>true],409);
    $current=json_decode($row['state_json'],true,512,JSON_THROW_ON_ERROR);
    if(in_array($action,['section','lesson'],true)&&academic_ready()){demand(is_int($b['lesson']??null)&&$b['lesson']>=0&&$b['lesson']<count(course_catalog()['lessons']),'Choose a valid lesson.');academic_guard_learning($u,'investing',mastery_module_index($b['lesson']));}
    if($action==='import'&&academic_ready())reply(['error'=>'New course credit must follow the academic pathway. Browser progress remains available locally; account-based learning requires College Success and teacher-reviewed module writing.','academicRequired'=>true],403);
    if($action==='import'){
        demand((int)$row['version']===1,'Browser progress can only be imported into a new, unused account.');
        $state=import_progress($b['state']??null);
    }else $state=change_progress($current,$b);
    $now=gmdate('c');$q=query('UPDATE learning_accounts SET state_json = ?, version = version+1, updated_at = ? WHERE user_id = ? AND version = ?',[json_encode($state,JSON_THROW_ON_ERROR),$now,$u['id'],(int)$row['version']]);
    if(!$q->rowCount())reply(['error'=>'Your account changed on another device. The latest progress has been loaded. Please review and try again.','conflict'=>true],409);
    reply(['account'=>['state'=>$state,'version'=>(int)$row['version']+1,'updatedAt'=>$now]]);
}catch(InvalidArgumentException $e){try{if(db()->inTransaction())db()->rollBack();}catch(Throwable $ignored){}reply(['error'=>$e->getMessage()],400);}
catch(Throwable $e){try{if(db()->inTransaction())db()->rollBack();}catch(Throwable $ignored){}error_log('Hisa Classroom API failure: '.get_class($e).' in '.basename($e->getFile()).':'.$e->getLine());http_response_code(503);header('Content-Type: application/json');header('Cache-Control: no-store');echo json_encode(['error'=>'Account service is temporarily unavailable. Please refresh before trying again.']);}
