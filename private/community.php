<?php
declare(strict_types=1);
require_once __DIR__.'/students.php';
const CAMPUS_COURSES=['college'=>'College Success: Think, Learn & Write','investing'=>'Investing in Kenyan shares','speaking'=>'Public Speaking Studio'];
function campus_course($v):string{demand(is_string($v)&&isset(CAMPUS_COURSES[$v]),'Choose a valid course.');return $v;}
function campus_installed():bool{try{query('SELECT course_id FROM campus_rooms LIMIT 1');return true;}catch(PDOException $e){if(preg_match('/no such table|doesn.t exist/i',$e->getMessage()))return false;throw $e;}}
function campus_install():void{
 $mysql=db()->getAttribute(PDO::ATTR_DRIVER_NAME)==='mysql';$sql=file_get_contents(dirname(__DIR__).'/database/campus-community.sql');
 if(!$mysql)$sql=str_replace(['BIGINT PRIMARY KEY AUTO_INCREMENT',' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'],['INTEGER PRIMARY KEY AUTOINCREMENT',''],$sql);
 else{$collation=query("SELECT COLLATION_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='users' AND COLUMN_NAME='id'")->fetchColumn();if(!is_string($collation)||!preg_match('/^utf8mb4_[a-zA-Z0-9_]+$/D',$collation))throw new RuntimeException('Check account collation.');$sql=str_replace('utf8mb4_unicode_ci',$collation,$sql);}
 foreach(explode(';',$sql) as $q)if(trim($q)!=='')db()->exec($q);
 foreach(CAMPUS_COURSES as $id=>$label)if(!query('SELECT course_id FROM campus_rooms WHERE course_id=?',[$id])->fetch())query('INSERT INTO campus_rooms (course_id,locked,version) VALUES (?,0,1)',[$id]);
}
function campus_audit(array $actor,string $action,string $subject,array $detail=[]):void{query('INSERT INTO campus_audit (actor_id,action,subject,detail_json,created_at) VALUES (?,?,?,?,?)',[$actor['id'],$action,$subject,json_encode($detail,JSON_THROW_ON_ERROR),gmdate('c')]);}
function campus_member(string $id,string $course):bool{return (bool)query('SELECT 1 FROM campus_enrollments WHERE user_id=? AND course_id=?',[$id,$course])->fetchColumn();}
function campus_name(array $u):string{$s=query('SELECT full_name FROM course_students WHERE user_id=?',[$u['id']])->fetchColumn();return $s?:$u['display_name'];}
function campus_avatar_url(string $id,?string $stamp=null):string{return 'avatar.php?student='.rawurlencode($id).($stamp?'&v='.rawurlencode($stamp):'');}
function campus_grades(string $id):array{
 $state=json_decode(query('SELECT state_json FROM learning_accounts WHERE user_id=?',[$id])->fetchColumn()?:'{}',true);$attempts=query('SELECT id,assessment_key,score,maximum,passed,submitted_at FROM assessment_attempts WHERE user_id=? AND assessment_key NOT LIKE \'college-%\' AND submitted_at IS NOT NULL ORDER BY submitted_at DESC,id DESC LIMIT 100',[$id])->fetchAll();
 $sp=query('SELECT state_json FROM speaking_accounts WHERE user_id=?',[$id])->fetchColumn();$speaking=$sp?json_decode($sp,true):['modules'=>[]];$sq=query('SELECT id,module_index,score,submitted_at FROM speaking_quiz_results WHERE user_id=? ORDER BY submitted_at DESC,id DESC LIMIT 100',[$id])->fetchAll();
 $summary=[];foreach(query('SELECT assessment_key,COUNT(*) AS attempts,MAX(passed) AS passed,MAX(100.0*score/maximum) AS best_percent FROM assessment_attempts WHERE user_id=? AND assessment_key NOT LIKE \'college-%\' AND submitted_at IS NOT NULL GROUP BY assessment_key',[$id])->fetchAll() as $a)$summary[$a['assessment_key']]=['bestPercent'=>round((float)$a['best_percent'],2),'passed'=>(bool)$a['passed'],'attempts'=>(int)$a['attempts']];
 $speakingSummary=[];foreach($speaking['modules']??[] as $i=>$m)$speakingSummary[]=['module'=>(int)$i,'passed'=>(bool)($m['passed']??false),'imported'=>(bool)($m['importedCompletion']??false)];
 $cert=query('SELECT certificate_number,course_title,issued_at FROM course_certificates WHERE user_id=?',[$id])->fetch();
 return ['investing'=>['sectionsPassed'=>array_sum(array_map(fn($l)=>mastery_count($l,$state),course_catalog()['lessons'])),'modulePasses'=>$state['modulePasses']??[],'summary'=>(object)$summary,'attempts'=>$attempts],'speaking'=>['modules'=>$speakingSummary,'attempts'=>$sq,'summary'=>query('SELECT module_index,COUNT(*) AS attempts,MAX(score) AS best_score FROM speaking_quiz_results WHERE user_id=? GROUP BY module_index',[$id])->fetchAll()],'certificate'=>$cert?:null,'historyLimit'=>100,'academic'=>function_exists('academic_gradebook')?academic_gradebook($id):null];
}
function campus_room(array $u,string $course,$before=null):array{
 $staff=campus_staff($u);$joined=campus_member($u['id'],$course);$room=query('SELECT * FROM campus_rooms WHERE course_id=?',[$course])->fetch();$messages=[];
 if($joined||$staff){$params=[$course];$where='m.course_id=?';if(!$staff){$where.=' AND (m.status=? OR m.user_id=?)';array_push($params,'approved',$u['id']);}if($before!==null){demand(is_string($before)&&ctype_digit($before)&&strlen($before)<19,'Choose a valid history page.');$where.=' AND m.id<?';$params[]=$before;}
  $rows=query('SELECT m.*,u.display_name,s.full_name,a.updated_at AS avatar_updated FROM campus_messages m JOIN users u ON u.id=m.user_id LEFT JOIN course_students s ON s.user_id=u.id LEFT JOIN campus_avatars a ON a.user_id=u.id WHERE '.$where.' ORDER BY m.id DESC LIMIT 51',$params)->fetchAll();$more=count($rows)>50;$rows=array_slice($rows,0,50);
  foreach(array_reverse($rows) as $r)$messages[]=['id'=>(string)$r['id'],'name'=>$r['full_name']?:$r['display_name'],'avatar'=>$r['avatar_updated']?campus_avatar_url($r['user_id'],$r['avatar_updated']):null,'body'=>$r['status']==='hidden'&&!$staff?'[Message removed by moderation]':$r['body'],'status'=>$r['status'],'at'=>$r['created_at'],'mine'=>$r['user_id']===$u['id']];
 }
 return ['course'=>$course,'title'=>CAMPUS_COURSES[$course],'joined'=>$joined,'locked'=>(bool)$room['locked'],'roomVersion'=>(int)$room['version'],'messages'=>$messages,'more'=>$more??false,'controls'=>campus_controls($u['id'])];
}
function campus_text($raw,int $max=1200):string{demand(is_string($raw)&&preg_match('//u',$raw)===1,'Use valid plain text.');$v=trim($raw);demand(text_length($v)>0&&text_length($v)<=$max&&!preg_match('/[\x00-\x08\x0b\x0c\x0e-\x1f\x7f]/u',$v),'Use between 1 and '.$max.' characters of plain text.');return $v;}
function campus_post(array $u,array $b):array{
 $course=campus_course($b['course']??null);$staff=campus_staff($u);demand(campus_member($u['id'],$course)||$staff,'Join this course before posting.');$c=campus_controls($u['id']);if(!$staff&&($c['muted']||$c['read_only']||$c['suspended']))reply(['error'=>'Posting is restricted for this account. Contact the student office.'],403);
 if(!$staff&&query('SELECT locked FROM campus_rooms WHERE course_id=?',[$course])->fetchColumn())reply(['error'=>'This chatroom is temporarily closed to new posts.'],403);
 demand(!array_diff(array_keys($b),['action','course','text','requestId']),'Only plain-text messages are accepted.');$text=campus_text($b['text']??null);$key=$b['requestId']??'';demand(is_string($key)&&preg_match('/^[a-f0-9]{32}$/D',$key),'Refresh the chat before posting.');
 $old=query('SELECT user_id,course_id,body FROM campus_messages WHERE request_key=?',[$key])->fetch();if($old){demand($old['user_id']===$u['id']&&$old['course_id']===$course&&$old['body']===$text,'This submission was already used.');return campus_room($u,$course);}
 demand((int)query('SELECT COUNT(*) FROM campus_messages WHERE course_id=?',[$course])->fetchColumn()<5000,'This room has reached its storage limit. Contact the student office.');limited('campus-chat',10,60,$u['id']);$count=(int)query('SELECT COUNT(*) FROM campus_messages WHERE user_id=? AND created_at>?',[$u['id'],gmdate('c',time()-86400)])->fetchColumn();demand($count<50||$staff,'You have reached today’s 50-message limit. Return tomorrow.');
 query('INSERT INTO campus_messages (user_id,course_id,body,status,created_at,request_key) VALUES (?,?,?,?,?,?)',[$u['id'],$course,$text,$staff?'approved':'pending',gmdate('c'),$key]);return campus_room($u,$course);
}
function campus_admin_student(string $id):array{
 $u=query('SELECT id,email,display_name,verified_at FROM users WHERE id=?',[$id])->fetch();demand((bool)$u,'Student not found.');$s=$u['verified_at']!==null?student_public(student_record($u)):null;
 return ['id'=>$id,'name'=>$u['display_name'],'email'=>$u['email'],'verified'=>$u['verified_at']!==null,'student'=>$s,'controls'=>campus_controls($id),'protected'=>campus_staff($u),'grades'=>campus_grades($id),'avatar'=>query('SELECT updated_at FROM campus_avatars WHERE user_id=?',[$id])->fetchColumn()?campus_avatar_url($id):null,'enrollments'=>query('SELECT course_id,joined_at FROM campus_enrollments WHERE user_id=?',[$id])->fetchAll()];
}
function campus_admin_controls(array $u,array $b):void{
 $target=campus_admin_student((string)($b['student']??''));demand(!$target['protected']&&$target['id']!==$u['id'],'Super administrator access cannot be restricted here.');$reason=campus_text($b['reason']??null,250);$version=$b['version']??null;demand(is_int($version),'Refresh the student record.');foreach(['muted','read_only','suspended'] as $k)demand(is_bool($b[$k]??null),'Choose each restriction explicitly.');
 db()->beginTransaction();try{$old=campus_controls($target['id']);if((int)$old['version']!==$version){db()->rollBack();reply(['error'=>'Another administrator changed this record. Refresh before saving.'],409);}
  if($version===0)query('INSERT INTO campus_controls (user_id,muted,read_only,suspended,version,reason,updated_at) VALUES (?,?,?,?,1,?,?)',[$target['id'],(int)$b['muted'],(int)$b['read_only'],(int)$b['suspended'],$reason,gmdate('c')]);
  else{$q=query('UPDATE campus_controls SET muted=?,read_only=?,suspended=?,version=version+1,reason=?,updated_at=? WHERE user_id=? AND version=?',[(int)$b['muted'],(int)$b['read_only'],(int)$b['suspended'],$reason,gmdate('c'),$target['id'],$version]);if(!$q->rowCount())throw new RuntimeException('Concurrent restriction update.');}
  if($b['suspended']&&!$old['suspended']){query('UPDATE users SET session_version=session_version+1 WHERE id=?',[$target['id']]);query('DELETE FROM auth_tokens WHERE user_id=? AND purpose=?',[$target['id'],'device']);}
  campus_audit($u,'student_controls',$target['id'],['reason'=>$reason,'muted'=>$b['muted'],'read_only'=>$b['read_only'],'suspended'=>$b['suspended']]);db()->commit();
 }catch(Throwable $e){if(db()->inTransaction())db()->rollBack();throw $e;}
}
