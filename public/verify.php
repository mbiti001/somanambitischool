<?php
declare(strict_types=1);
require dirname(__DIR__).'/private/bootstrap.php';
header('Cache-Control: no-store');header('Referrer-Policy: no-referrer');header('X-Content-Type-Options: nosniff');
try {
    $token=$_GET['token']??'';
    if(!is_string($token)||preg_match('/^[a-f0-9]{64}$/D',$token)!==1)throw new InvalidArgumentException();
    db()->beginTransaction();$lock=db()->getAttribute(PDO::ATTR_DRIVER_NAME)==='mysql'?' FOR UPDATE':'';
    $t=query('SELECT * FROM auth_tokens WHERE token_hash = ? AND purpose = ? AND expires_at > ?'.$lock,[hash('sha256',$token),'verify',time()])->fetch();
    if(!$t){db()->rollBack();header('Location: /?verification=expired#account',true,303);exit;}
    query('UPDATE users SET verified_at = ? WHERE id = ?',[gmdate('c'),$t['user_id']]);
    query('DELETE FROM auth_tokens WHERE user_id = ? AND purpose = ?',[$t['user_id'],'verify']);db()->commit();
    header('Location: /?verification=success#account',true,303);
}catch(Throwable $e){header('Location: /?verification=expired#account',true,303);}
