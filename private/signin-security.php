<?php
declare(strict_types=1);
// Opaque keys reuse the existing rate-limit table; no account names or IPs are stored here.
function signin_keys(string $email):array {
    $key=settings()['app_key'];$ip=$_SERVER['REMOTE_ADDR']??'unknown';
    $keys=[['bucket'=>hash_hmac('sha256','signin-v1:ip:'.$ip,$key),'limit'=>30,'scope'=>'connection']];
    if($email!=='')$keys[]=['bucket'=>hash_hmac('sha256','signin-v1:email:'.$email,$key),'limit'=>5,'scope'=>'email'];
    usort($keys,fn($a,$b)=>strcmp($a['bucket'],$b['bucket']));return $keys;
}
function signin_remaining(array $row,int $limit,int $now):int {return (int)$row['hits']>=$limit?max(0,(int)$row['window_start']+900-$now):0;}
function signin_check(string $email):array {
    $wait=['retryAfter'=>0,'lockScope'=>'email'];$now=time();
    foreach(signin_keys($email) as $key){$row=query('SELECT hits,window_start FROM rate_limits WHERE bucket=?',[$key['bucket']])->fetch();$seconds=$row?signin_remaining($row,$key['limit'],$now):0;if($seconds>$wait['retryAfter'])$wait=['retryAfter'=>$seconds,'lockScope'=>$key['scope']];}
    return $wait;
}
// Serialize final authentication decisions so parallel requests cannot bypass a lock.
function signin_finish(string $email,bool $valid):array {
    $now=time();$keys=signin_keys($email);db()->beginTransaction();
    try{
        $rows=[];$mysql=db()->getAttribute(PDO::ATTR_DRIVER_NAME)==='mysql';
        foreach($keys as $key){
            $sql=$mysql?'INSERT INTO rate_limits (bucket,window_start,hits) VALUES (?,?,0) ON DUPLICATE KEY UPDATE bucket=VALUES(bucket)':'INSERT INTO rate_limits (bucket,window_start,hits) VALUES (?,?,0) ON CONFLICT(bucket) DO UPDATE SET bucket=excluded.bucket';
            query($sql,[$key['bucket'],$now]);
            $rows[$key['bucket']]=query('SELECT hits,window_start FROM rate_limits WHERE bucket=?'.($mysql?' FOR UPDATE':''),[$key['bucket']])->fetch();
        }
        $wait=['retryAfter'=>0,'lockScope'=>'email'];
        foreach($keys as $key){$seconds=signin_remaining($rows[$key['bucket']],$key['limit'],$now);if($seconds>$wait['retryAfter'])$wait=['retryAfter'=>$seconds,'lockScope'=>$key['scope']];}
        if(!$wait['retryAfter'])foreach($keys as $key){
            $row=$rows[$key['bucket']];$expired=(int)$row['window_start']+900<=$now;$hits=$expired?0:(int)$row['hits'];$start=$expired?$now:(int)$row['window_start'];
            if($valid){if($key['scope']==='email')query('DELETE FROM rate_limits WHERE bucket=?',[$key['bucket']]);}
            else{$hits++;if($hits===$key['limit'])$start=$now;query('UPDATE rate_limits SET hits=?,window_start=? WHERE bucket=?',[$hits,$start,$key['bucket']]);$seconds=signin_remaining(['hits'=>$hits,'window_start'=>$start],$key['limit'],$now);if($seconds>$wait['retryAfter'])$wait=['retryAfter'=>$seconds,'lockScope'=>$key['scope']];}
        }
        db()->commit();
        if(!$valid||$wait['retryAfter'])error_log(json_encode(['event'=>$wait['retryAfter']?'signin_temporarily_blocked':'signin_failed','email_key'=>$email===''?null:substr(hash_hmac('sha256',$email,settings()['app_key']),0,16),'connection_key'=>substr(hash_hmac('sha256',$_SERVER['REMOTE_ADDR']??'unknown',settings()['app_key']),0,16),'retry_after'=>$wait['retryAfter']]));
        return $wait;
    }catch(Throwable $e){if(db()->inTransaction())db()->rollBack();throw $e;}
}
function signin_block(array $wait):void {
    if(!$wait['retryAfter'])return;
    header('Retry-After: '.$wait['retryAfter']);
    reply(['error'=>'Sign-in is temporarily paused after repeated unsuccessful attempts. Wait for the timer, or use Forgot password to recover your account.','loginLocked'=>true]+$wait,429);
}
