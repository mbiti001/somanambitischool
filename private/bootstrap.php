<?php
declare(strict_types=1);
ini_set('display_errors','0');
ini_set('log_errors','1');
require_once __DIR__.'/practice.php';
require_once __DIR__.'/campus-policy.php';
require_once __DIR__.'/session-policy.php';
require_once __DIR__.'/browser-security.php';
function settings(): array {
    static $config;
    if($config!==null)return $config;
    $path=getenv('HISA_CONFIG_PATH')?:__DIR__.'/config.php';
    if(!is_file($path))throw new RuntimeException('Server configuration missing.');
    $config=require $path;
    if(strlen($config['app_key']??'')<32||str_contains($config['app_key'],'REPLACE_'))throw new RuntimeException('Server key missing.');
    if(($config['environment']??'production')==='production'&&!str_starts_with($config['site_url']??'','https://'))throw new RuntimeException('HTTPS required.');
    return $config;
}
function db(): PDO {
    static $pdo;
    if($pdo)return $pdo;
    $c=settings();$pdo=new PDO($c['dsn'],$c['db_user']??null,$c['db_password']??null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,PDO::ATTR_EMULATE_PREPARES=>false]);
    if($pdo->getAttribute(PDO::ATTR_DRIVER_NAME)==='sqlite'){$pdo->exec('PRAGMA foreign_keys = ON');$pdo->exec('PRAGMA busy_timeout = 5000');}
    return $pdo;
}
function query(string $sql,array $args=[]): PDOStatement {$q=db()->prepare($sql);$q->execute($args);return $q;}
function session_begin(): void {
    if(session_status()===PHP_SESSION_ACTIVE)return;
    $secure=str_starts_with(settings()['site_url'],'https://');
    ini_set('session.use_strict_mode','1');ini_set('session.use_only_cookies','1');ini_set('session.gc_maxlifetime','7200');
    session_name('hisa_session');session_set_cookie_params(['lifetime'=>0,'path'=>'/','secure'=>$secure,'httponly'=>true,'samesite'=>'Lax']);session_start();
    $reason=school_session_reason($_SESSION,time());
    if($reason!==null){$_SESSION=['session_expired'=>$reason];session_regenerate_id(true);}
    elseif(empty($_SESSION['user_id'])&&isset($_SESSION['last_seen'])&&time()-$_SESSION['last_seen']>=7200){$_SESSION=[];session_regenerate_id(true);}
    school_session_initialise($_SESSION,time());
    $_SESSION['last_seen']=time();if(!isset($_SESSION['csrf']))$_SESSION['csrf']=bin2hex(random_bytes(32));
}
function account_user(bool $writing=true): ?array {
    if(empty($_SESSION['user_id']))return null;
    $u=query('SELECT id,email,display_name,verified_at,session_version FROM users WHERE id = ?',[$_SESSION['user_id']])->fetch();
    if(!$u||(int)$u['session_version']!==($_SESSION['session_version']??null)){unset($_SESSION['user_id'],$_SESSION['session_version']);return null;}
    campus_guard($u,$writing);return $u;
}
function auth_session(array $u): void {$_SESSION=[];session_regenerate_id(false);$_SESSION=['user_id'=>$u['id'],'session_version'=>(int)$u['session_version'],'csrf'=>bin2hex(random_bytes(32)),'last_seen'=>time(),'authenticated_at'=>time(),'user_activity_at'=>time(),'runtime_id'=>bin2hex(random_bytes(16))];}
function reply(array $data,int $status=200): never {http_response_code($status);header('Content-Type: application/json; charset=utf-8');header('Cache-Control: no-store, private');header('X-Content-Type-Options: nosniff');header('Referrer-Policy: no-referrer');echo json_encode($data,JSON_THROW_ON_ERROR);exit;}
function email_input($value): string {if(is_string($value))$value=trim($value);demand(is_string($value)&&strlen($value)<=254&&filter_var($value,FILTER_VALIDATE_EMAIL)!==false,'Enter a valid email address.');return strtolower($value);}
function password_input($value): string {demand(is_string($value)&&preg_match('//u',$value)===1&&!str_contains($value,"\0")&&text_length($value)>=15&&strlen($value)<=72,'Use at least 15 characters and no more than 72 bytes. A few unrelated words make a useful passphrase.');return $value;}
function limited(string $scope,int $max,int $seconds,string $identifier=''):void {
    $bucket=hash_hmac('sha256',$scope.'|'.($_SERVER['REMOTE_ADDR']??'unknown').'|'.$identifier,settings()['app_key']);$now=time();$cutoff=$now-$seconds;
    $update='UPDATE rate_limits SET hits = CASE WHEN window_start < ? THEN 1 ELSE hits+1 END, window_start = CASE WHEN window_start < ? THEN ? ELSE window_start END WHERE bucket = ?';
    $args=[$cutoff,$cutoff,$now,$bucket];$q=query($update,$args);
    if(!$q->rowCount())try{query('INSERT INTO rate_limits (bucket,window_start,hits) VALUES (?,?,1)',[$bucket,$now]);}catch(PDOException $e){if(!in_array($e->getCode(),['23000','23505'],true))throw $e;query($update,$args);}
    $row=query('SELECT hits,window_start FROM rate_limits WHERE bucket = ?',[$bucket])->fetch();
    if((int)$row['hits']>$max){$remaining=max(1,(int)$row['window_start']+$seconds-$now);header('Retry-After: '.$remaining);reply(['error'=>'Too many attempts. Please wait and try again.','retryAfter'=>$remaining,'lockScope'=>'connection'],429);}
}
function send_account_mail(string $to,string $subject,string $body):bool {
    $c=settings();$from=$c['mail_from']??'';
    // The sender also becomes a sendmail argument: accept only a simple mailbox.
    if(!filter_var($from,FILTER_VALIDATE_EMAIL)||!preg_match('/\A[a-zA-Z0-9._+\-]+@[a-zA-Z0-9.\-]+\z/D',$from))throw new RuntimeException('Email sender not configured.');
    if(($c['mail_transport']??'mail')==='log'){
        if(($c['environment']??'production')!=='development')throw new RuntimeException('Development mail cannot be used in production.');
        return file_put_contents($c['mail_log'],json_encode(['to'=>$to,'subject'=>$subject,'body'=>$body])."\n",FILE_APPEND|LOCK_EX)!==false;
    }
    // Align the envelope with the domain's SPF/DKIM identity, rather than the
    // shared server's default username@hostname, which Gmail can reject.
    return mail($to,$subject,$body,['From'=>$from,'MIME-Version'=>'1.0','Content-Type'=>'text/plain; charset=UTF-8'],'-f'.$from);
}
function issue_token(array $u,string $purpose): bool {
    $token=bin2hex(random_bytes(32));$hash=hash('sha256',$token);$seconds=$purpose==='verify'?86400:1800;
    $url=rtrim(settings()['site_url'],'/').($purpose==='verify'?'/verify.php?token=':'/?reset=').rawurlencode($token);
    $subject=$purpose==='verify'?'Verify your Hisa Classroom email':'Reset your Hisa Classroom password';
    $body=($purpose==='verify'?"Confirm your email to save lessons and your practice portfolio across devices.":"Use this link to set a new password. If you did not request a reset, ignore this email.")."\n\n".$url."\n\nThis link expires in ".($purpose==='verify'?'24 hours.':'30 minutes.')."\nHisa Classroom — educational practice only.";
    query('DELETE FROM auth_tokens WHERE user_id = ? AND purpose = ?',[$u['id'],$purpose]);
    query('INSERT INTO auth_tokens (token_hash,user_id,purpose,expires_at) VALUES (?,?,?,?)',[$hash,$u['id'],$purpose,time()+$seconds]);
    if(!send_account_mail($u['email'],$subject,$body)){query('DELETE FROM auth_tokens WHERE token_hash = ?',[$hash]);return false;}
    return true;
}
function account_payload(?array $u): array {
    $out=['csrf'=>$_SESSION['csrf'],'user'=>null,'account'=>null,'session'=>null,'sessionExpired'=>$_SESSION['session_expired']??null];
    if(!$u)return $out;
    campus_guard($u,false);
    $out['session']=school_session_metadata($_SESSION,time());
    $out['user']=['name'=>$u['display_name'],'email'=>$u['email'],'verified'=>$u['verified_at']!==null];
    if($u['verified_at']===null)return $out;
    $r=query('SELECT state_json,version,updated_at FROM learning_accounts WHERE user_id = ?',[$u['id']])->fetch();
    $out['account']=['state'=>normalize_learning_state(json_decode($r['state_json'],true,512,JSON_THROW_ON_ERROR)),'version'=>(int)$r['version'],'updatedAt'=>$r['updated_at']];
    return $out;
}
