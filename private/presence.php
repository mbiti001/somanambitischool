<?php
declare(strict_types=1);
/* Ephemeral presence only: no learning, last-seen, IP, or browser records. */
const SCHOOL_PRESENCE_SECONDS=150;
function school_presence_missing(PDOException $e):bool{return preg_match('/no such table|doesn.t exist/i',$e->getMessage())===1;}
function school_presence_ready():bool{try{query('SELECT user_id FROM school_presence_leases LIMIT 1');return true;}catch(PDOException $e){if(school_presence_missing($e))return false;throw $e;}}
function school_presence_install():void{
 demand(campus_staff(account_user(false)),'Only the verified school owner can enable online indicators.');
 $sql=file_get_contents(dirname(__DIR__).'/database/presence.sql');
 if(db()->getAttribute(PDO::ATTR_DRIVER_NAME)==='sqlite'){$sql=str_replace(' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci','',$sql);$sql=str_replace(',INDEX school_presence_runtime (runtime_id),INDEX school_presence_expiry (discard_at)','',$sql);}
 else{$collation=query("SELECT COLLATION_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='users' AND COLUMN_NAME='id'")->fetchColumn();if(!is_string($collation)||!preg_match('/^utf8mb4_[a-zA-Z0-9_]+$/D',$collation))throw new RuntimeException('Check account collation.');$sql=str_replace('utf8mb4_unicode_ci',$collation,$sql);}
 db()->exec($sql);
 if(db()->getAttribute(PDO::ATTR_DRIVER_NAME)==='sqlite'){db()->exec('CREATE INDEX IF NOT EXISTS school_presence_runtime ON school_presence_leases(runtime_id)');db()->exec('CREATE INDEX IF NOT EXISTS school_presence_expiry ON school_presence_leases(discard_at)');}
}
function school_presence_ids(array $ids):array{
 demand(count($ids)<=50,'Choose no more than 50 online indicators at a time.');$out=[];
 foreach($ids as $id){demand(is_string($id)&&strlen($id)<=100&&!preg_match('/[\x00-\x1f\x7f]/',$id),'Use valid student identifiers.');$out[$id]=false;}return $out;
}
function school_presence_rows(string $sql,array $params):array{try{return query($sql,$params)->fetchAll();}catch(PDOException $e){if(school_presence_missing($e))return [];throw $e;}}
function school_presence_status(?array $viewer,array $ids):array{
 $out=school_presence_ids($ids);
 if(!$out||!$viewer||($viewer['verified_at']??null)===null||!school_presence_ready())return $out;
 // Only real, verified, current-version leases can be online; unknown and denied
 // identifiers retain exactly the same false value. No account lookup is exposed.
 $valid=array_values(array_filter(array_keys($out),fn($id)=>preg_match('/^[a-f0-9]{32}$/D',(string)$id)===1));if(!$valid)return $out;
 $marks=implode(',',array_fill(0,count($valid),'?'));$allowed=[$viewer['id']=>true];
 if(campus_staff($viewer))foreach($valid as $id)$allowed[$id]=true;
 else{
  $active="('enrolled','finished','completed')";
  foreach(school_presence_rows('SELECT b.user_id FROM term_enrollments a JOIN term_enrollments b ON a.offering_id=b.offering_id WHERE a.user_id=? AND a.status IN '.$active.' AND b.status IN '.$active.' AND b.user_id IN ('.$marks.')',array_merge([$viewer['id']],$valid)) as $r)$allowed[$r['user_id']]=true;
  foreach(school_presence_rows('SELECT e.user_id FROM term_professors p JOIN term_enrollments e ON p.offering_id=e.offering_id WHERE p.user_id=? AND e.status IN '.$active.' AND e.user_id IN ('.$marks.')',array_merge([$viewer['id']],$valid)) as $r)$allowed[$r['user_id']]=true;
  foreach(school_presence_rows('SELECT b.user_id FROM campus_enrollments a JOIN campus_enrollments b ON a.course_id=b.course_id JOIN campus_rooms r ON r.course_id=a.course_id WHERE a.user_id=? AND b.user_id IN ('.$marks.')',array_merge([$viewer['id']],$valid)) as $r)$allowed[$r['user_id']]=true;
 }
 $now=time();$online=query('SELECT DISTINCT l.user_id FROM school_presence_leases l JOIN users u ON u.id=l.user_id AND u.session_version=l.session_version WHERE u.verified_at IS NOT NULL AND l.expires_at>? AND l.user_id IN ('.$marks.')',array_merge([$now],$valid))->fetchAll();
 foreach($online as $r)if(isset($allowed[$r['user_id']])&&!campus_controls($r['user_id'])['suspended'])$out[$r['user_id']]=true;
 return $out;
}
function school_presence_write(array $u,string $runtime,string $tab,?int $sequence,bool $leaving):bool{
 if(($u['verified_at']??null)===null||!school_presence_ready())return false;
 demand(preg_match('/^[a-f0-9]{32}$/D',$runtime)===1&&preg_match('/^[a-f0-9]{64}$/D',$tab)===1,'Refresh your online indicator.');
 if(!hash_equals((string)($_SESSION['runtime_id']??''),$runtime)||($_SESSION['user_id']??null)!==$u['id'])return false;
 $meta=school_session_metadata($_SESSION,time());if(!$meta)return false;
 $now=time();$expiry=$leaving?0:min($now+SCHOOL_PRESENCE_SECONDS,(int)$meta['expiresAt']);if(!$leaving&&$expiry<=$now)return false;
 // A short tombstone prevents an older in-flight pulse overtaking a later leave.
 // Neither timestamp is exposed; expired lease/tombstone rows are discarded.
 query('DELETE FROM school_presence_leases WHERE discard_at<=?',[$now]);
 if($sequence===null)$sequence=1+(int)query('SELECT sequence FROM school_presence_leases WHERE user_id=? AND runtime_id=? AND tab_id=?',[$u['id'],$runtime,$tab])->fetchColumn();
 demand($sequence>0&&$sequence<=2147483647,'Refresh your online indicator.');
 $args=[$u['id'],$runtime,$tab,(int)$u['session_version'],$expiry,$sequence,$now+300];
 if(db()->getAttribute(PDO::ATTR_DRIVER_NAME)==='mysql')query('INSERT INTO school_presence_leases (user_id,runtime_id,tab_id,session_version,expires_at,sequence,discard_at) VALUES (?,?,?,?,?,?,?) ON DUPLICATE KEY UPDATE session_version=IF(VALUES(sequence)>sequence,VALUES(session_version),session_version),expires_at=IF(VALUES(sequence)>sequence,VALUES(expires_at),expires_at),discard_at=IF(VALUES(sequence)>sequence,VALUES(discard_at),discard_at),sequence=GREATEST(sequence,VALUES(sequence))',$args);
 else query('INSERT INTO school_presence_leases (user_id,runtime_id,tab_id,session_version,expires_at,sequence,discard_at) VALUES (?,?,?,?,?,?,?) ON CONFLICT(user_id,runtime_id,tab_id) DO UPDATE SET session_version=excluded.session_version,expires_at=excluded.expires_at,sequence=excluded.sequence,discard_at=excluded.discard_at WHERE excluded.sequence>school_presence_leases.sequence',$args);
 $row=query('SELECT sequence,expires_at FROM school_presence_leases WHERE user_id=? AND runtime_id=? AND tab_id=?',[$u['id'],$runtime,$tab])->fetch();
 return !$leaving&&(int)$row['sequence']===$sequence&&(int)$row['expires_at']>$now;
}
function school_presence_touch(array $u,string $runtime,string $tab,?int $sequence=null):bool{return school_presence_write($u,$runtime,$tab,$sequence,false);}
function school_presence_leave(string $runtime,string $tab,?int $sequence=null):void{
 $u=account_user(false);if($u)school_presence_write($u,$runtime,$tab,$sequence,true);
}
function school_presence_end(string $runtime):void{
 if(preg_match('/^[a-f0-9]{32}$/D',$runtime)!==1||!school_presence_ready())return;
 query('DELETE FROM school_presence_leases WHERE runtime_id=?',[$runtime]);
}
