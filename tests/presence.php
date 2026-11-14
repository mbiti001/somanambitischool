<?php
declare(strict_types=1);
/* Isolated memory-only fixtures. No shared database or real account is touched. */
$dir=sys_get_temp_dir().'/soma-presence-'.bin2hex(random_bytes(6));mkdir($dir,0700);
file_put_contents($dir.'/config.php','<?php return '.var_export(['environment'=>'development','site_url'=>'http://localhost','app_key'=>str_repeat('presence-test-',4),'dsn'=>'sqlite::memory:'],true).';');
file_put_contents($dir.'/roles.php','<?php return '.var_export(['super_admin_emails'=>['owner@example.invalid']],true).';');
putenv('HISA_CONFIG_PATH='.$dir.'/config.php');putenv('HISA_CAMPUS_CONFIG_PATH='.$dir.'/roles.php');
require dirname(__DIR__).'/private/bootstrap.php';require_once dirname(__DIR__).'/private/presence.php';
function checkPresence(bool $value,string $message):void{if(!$value)throw new RuntimeException($message);}
function asPresence(array $u,string $runtime):void{$_SESSION=['user_id'=>$u['id'],'runtime_id'=>$runtime,'session_version'=>1,'csrf'=>'test-csrf','authenticated_at'=>time(),'user_activity_at'=>time()];}
try{
 db()->exec('CREATE TABLE users(id TEXT PRIMARY KEY,email TEXT,display_name TEXT,verified_at TEXT,session_version INTEGER); CREATE TABLE term_enrollments(id TEXT,offering_id TEXT,user_id TEXT,status TEXT); CREATE TABLE term_professors(offering_id TEXT,user_id TEXT); CREATE TABLE campus_enrollments(user_id TEXT,course_id TEXT); CREATE TABLE campus_rooms(course_id TEXT); CREATE TABLE campus_controls(user_id TEXT,suspended INTEGER,read_only INTEGER,muted INTEGER,version INTEGER);');
 $users=[];foreach(['owner','alice','bob','other','professor','wrongprof','unverified','legacy','inactive','finished'] as $name){$u=['id'=>md5($name),'email'=>$name.'@example.invalid','display_name'=>$name,'verified_at'=>$name==='unverified'?null:gmdate('c'),'session_version'=>1];$users[$name]=$u;query('INSERT INTO users VALUES (?,?,?,?,?)',array_values($u));}
 $r1=str_repeat('a',32);$r2=str_repeat('b',32);$ta=str_repeat('1',64);$tb=str_repeat('2',64);$tc=str_repeat('3',64);$unknown=str_repeat('f',32);
 $initial=(int)query('SELECT total_changes()')->fetchColumn();
 checkPresence(school_presence_status(null,[$users['alice']['id'],$unknown])===[$users['alice']['id']=>false,$unknown=>false],'Guests must receive only false values.');
 checkPresence(!school_presence_ready(),'Read-only status must not install the table.');
 foreach(['alice','unverified'] as $name){asPresence($users[$name],$r1);try{school_presence_install();throw new RuntimeException('Non-owner installed presence.');}catch(InvalidArgumentException $expected){}}
 checkPresence((int)query('SELECT total_changes()')->fetchColumn()===$initial,'Guest/unverified/learner setup changed records.');
 asPresence($users['owner'],$r1);school_presence_install();checkPresence(school_presence_ready(),'Owner installation failed.');school_presence_install();
 foreach(['alice'=>'enrolled','bob'=>'enrolled','finished'=>'finished','inactive'=>'unenrolled_inactive'] as $name=>$status)query('INSERT INTO term_enrollments VALUES (?,?,?,?)',[$name,'class-one',$users[$name]['id'],$status]);
 query('INSERT INTO term_enrollments VALUES (?,?,?,?)',['other','class-two',$users['other']['id'],'enrolled']);query('INSERT INTO term_professors VALUES (?,?)',['class-one',$users['professor']['id']]);query('INSERT INTO term_professors VALUES (?,?)',['class-two',$users['wrongprof']['id']]);
 query('INSERT INTO campus_rooms VALUES (?)',['speaking']);foreach(['alice','legacy'] as $name)query('INSERT INTO campus_enrollments VALUES (?,?)',[$users[$name]['id'],'speaking']);
 foreach($users as $name=>$u){asPresence($u,$r1);if($name==='unverified')checkPresence(!school_presence_touch($u,$r1,$ta,1),'Unverified user acquired a lease.');else checkPresence(school_presence_touch($u,$r1,$ta,1),'Verified fixture could not pulse.');}
 $ids=array_column($users,'id');$ids[]=$unknown;$ids[]='not-an-id';
 $before=(int)query('SELECT total_changes()')->fetchColumn();$status=school_presence_status($users['alice'],$ids);
 foreach(['alice','bob','finished','legacy'] as $name)checkPresence($status[$users[$name]['id']]===true,'Own/exact-class/legacy scope missing: '.$name);
 foreach(['other','professor','wrongprof','unverified','inactive'] as $name)checkPresence($status[$users[$name]['id']]===false,'Forbidden target became visible: '.$name);
 checkPresence($status[$unknown]===false&&$status['not-an-id']===false,'Unknown and denied targets must both be false.');
 checkPresence((int)query('SELECT total_changes()')->fetchColumn()===$before,'Status lookup wrote records.');
 $prof=school_presence_status($users['professor'],$ids);checkPresence($prof[$users['alice']['id']]&&$prof[$users['bob']['id']]&&!$prof[$users['other']['id']]&&!$prof[$users['inactive']['id']],'Professor scope crossed offering/status boundaries.');
 $owner=school_presence_status($users['owner'],$ids);checkPresence($owner[$users['other']['id']]&&!$owner[$users['unverified']['id']]&&!$owner[$unknown],'Owner visibility must retain verified/existence boundaries.');
 checkPresence(!in_array(true,school_presence_status($users['unverified'],$ids),true),'Unverified viewer received online details.');
 try{school_presence_status($users['owner'],array_fill(0,51,$unknown));throw new RuntimeException('More than 50 IDs accepted.');}catch(InvalidArgumentException $expected){}
 query('UPDATE users SET session_version=2 WHERE id=?',[$users['bob']['id']]);checkPresence(!school_presence_status($users['alice'],[$users['bob']['id']])[$users['bob']['id']],'Revoked-session lease remained online.');
 query('INSERT INTO campus_controls VALUES (?,1,0,0,1)',[$users['legacy']['id']]);checkPresence(!school_presence_status($users['alice'],[$users['legacy']['id']])[$users['legacy']['id']],'Suspended target remained online.');
 // Distinct tabs and devices are independent. Sequence tombstones stop old pulses.
 query('DELETE FROM school_presence_leases');$alice=$users['alice'];asPresence($alice,$r1);$auth=$_SESSION;
 checkPresence(school_presence_touch($alice,$r1,$ta,1),'First tab pulse failed.');checkPresence(school_presence_touch($alice,$r1,$tb,1),'Second tab pulse failed.');
 checkPresence($_SESSION===$auth,'Presence renewed or otherwise changed authentication timestamps.');
 school_presence_leave($r1,$ta,3);checkPresence(!school_presence_touch($alice,$r1,$ta,2),'Late pulse revived a hidden tab.');
 checkPresence(school_presence_status($alice,[$alice['id']])[$alice['id']],'Hiding one tab removed another tab.');
 asPresence($alice,$r2);checkPresence(school_presence_touch($alice,$r2,$tc,1),'Separate device pulse failed.');school_presence_end($r1);checkPresence(school_presence_status($alice,[$alice['id']])[$alice['id']],'Ending one runtime removed another device.');
 school_presence_end($r2);checkPresence(!school_presence_status($alice,[$alice['id']])[$alice['id']],'Ending last runtime did not go offline.');
 asPresence($alice,$r1);$_SESSION['user_activity_at']=time()-3590;$auth=$_SESSION;checkPresence(school_presence_touch($alice,$r1,$ta,1),'Near-expiry pulse failed.');$expiry=(int)query('SELECT expires_at FROM school_presence_leases')->fetchColumn();checkPresence($expiry<=time()+10,'Presence exceeded the existing idle deadline.');checkPresence($_SESSION===$auth,'Near-expiry pulse renewed auth.');
 $_SESSION['user_activity_at']=time()-3600;checkPresence(!school_presence_touch($alice,$r1,$tb,1),'Expired authentication acquired presence.');
 asPresence($users['other'],$r2);checkPresence(!school_presence_touch($alice,$r1,$tc,9),'Client runtime or user impersonation was accepted.');
 query('UPDATE school_presence_leases SET expires_at=?',[time()-1]);checkPresence(!school_presence_status($alice,[$alice['id']])[$alice['id']],'Expired leases remained online.');
 echo "PASS: owner-only setup; boolean-only verified exact-class/professor/legacy scope; no presence read writes or auth renewal; tab/device independence, ordered leave, expiry, suspension and session revocation.\n";
}finally{foreach(glob($dir.'/*')?:[] as $file)unlink($file);rmdir($dir);}
