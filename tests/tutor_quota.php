<?php
require dirname(__DIR__).'/private/bootstrap.php';require dirname(__DIR__).'/private/tutor.php';
if((settings()['environment']??'')!=='development'||db()->getAttribute(PDO::ATTR_DRIVER_NAME)!=='sqlite')throw new RuntimeException('Disposable local SQLite only.');
// Restore the pre-test shared site counter; unique test-user rows cannot affect real users.
$site=hash_hmac('sha256','tutor:'.gmdate('Y-m-d').':site',settings()['app_key']);$old=query('SELECT * FROM rate_limits WHERE bucket=?',[$site])->fetch();query('DELETE FROM rate_limits WHERE bucket=?',[$site]);
try{$id=bin2hex(random_bytes(12));$c=['user_daily_limit'=>1,'site_daily_limit'=>2];
 if(!tutor_reserve($id,$c)||tutor_reserve($id,$c)||!tutor_reserve($id.'other',$c)||tutor_reserve($id.'third',$c))throw new RuntimeException('Quota failure');
 if((int)query('SELECT hits FROM rate_limits WHERE bucket=?',[$site])->fetchColumn()!==2)throw new RuntimeException('Rejected calls consumed shared quota');
 echo "PASS: account and site daily caps enforced atomically; rejected account request rolls back site reservation.\n";
}finally{query('DELETE FROM rate_limits WHERE bucket=?',[$site]);if($old)query('INSERT INTO rate_limits (bucket,window_start,hits) VALUES (?,?,?)',[$old['bucket'],$old['window_start'],$old['hits']]);}
