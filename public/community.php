<?php
declare(strict_types=1);
require dirname(__DIR__).'/private/bootstrap.php';require dirname(__DIR__).'/private/community.php';require_once dirname(__DIR__).'/private/academic.php';
try{
 session_begin();$u=account_user();if(!$u||$u['verified_at']===null)reply(['error'=>'Sign in and verify your email to use your student campus.','csrf'=>$_SESSION['csrf'],'signedOut'=>true],401);
 $staff=campus_staff($u);$method=$_SERVER['REQUEST_METHOD'];$b=$method==='POST'?student_request_body(12000):[];if(!in_array($method,['GET','POST'],true))reply(['error'=>'Method not allowed.'],405);
 if($method==='POST'&&($b['action']??'')==='install'){if(!$staff)reply(['error'=>'Super administrator access required.'],403);campus_install();campus_audit($u,'install','campus');reply(['notice'=>'Student campus services installed.']);}
 $base=['csrf'=>$_SESSION['csrf'],'superAdmin'=>$staff,'name'=>campus_name($u),'avatarSupported'=>true,'avatarProcessor'=>function_exists('imagecreatefromstring')?'server-gd':'compact-jpeg','courses'=>CAMPUS_COURSES];
 if(!campus_installed())reply($base+['installed'=>false,'error'=>'The student campus is awaiting administrator setup.'],503);
 $base['installed']=true;student_record($u);
 if($method==='GET'){
  $view=$_GET['view']??'profile';
  if($view==='profile')reply($base+['student'=>student_public(student_record($u)),'avatar'=>query('SELECT updated_at FROM campus_avatars WHERE user_id=?',[$u['id']])->fetchColumn()?campus_avatar_url($u['id']):null,'controls'=>campus_controls($u['id'])]);
  if($view==='gradebook')reply($base+['grades'=>campus_grades($u['id'])]);
  if($view==='chat')reply($base+['room'=>campus_room($u,campus_course($_GET['course']??null),$_GET['before']??null)]);
  if(!$staff)reply(['error'=>'Super administrator access required.'],403);
  limited('campus-admin-read',120,60,$u['id']);
  if($view==='admin'){
   $search=trim((string)($_GET['search']??''));demand(text_length($search)<=100,'Use a shorter search.');$page=max(1,min(100000,(int)($_GET['page']??1)));$needle='%'.str_replace(['!','%','_'],['!!','!%','!_'],$search).'%';$where="u.email LIKE ? ESCAPE '!' OR u.display_name LIKE ? ESCAPE '!' OR s.full_name LIKE ? ESCAPE '!'";$p=[$needle,$needle,$needle];
   $total=(int)query('SELECT COUNT(*) FROM users u LEFT JOIN course_students s ON s.user_id=u.id WHERE '.$where,$p)->fetchColumn();
   $rows=query('SELECT u.id,u.email,u.display_name,u.verified_at,s.student_sequence,s.full_name,c.suspended,c.read_only,c.muted FROM users u LEFT JOIN course_students s ON s.user_id=u.id LEFT JOIN campus_controls c ON c.user_id=u.id WHERE '.$where.' ORDER BY u.created_at DESC,u.id LIMIT 25 OFFSET '.(($page-1)*25),$p)->fetchAll();
   $queue=query("SELECT m.id,m.course_id,m.body,m.status,m.created_at,COALESCE(s.full_name,u.display_name) AS display_name,(SELECT COUNT(*) FROM campus_reports r WHERE r.message_id=m.id) AS reports FROM campus_messages m JOIN users u ON u.id=m.user_id LEFT JOIN course_students s ON s.user_id=u.id WHERE m.status='pending' OR EXISTS(SELECT 1 FROM campus_reports r WHERE r.message_id=m.id) ORDER BY m.id DESC LIMIT 50")->fetchAll();
   reply($base+['students'=>$rows,'total'=>$total,'page'=>$page,'queue'=>$queue,'rooms'=>query('SELECT * FROM campus_rooms')->fetchAll(),'audit'=>query('SELECT a.id,a.action,a.subject,a.detail_json,a.created_at,u.display_name AS actor FROM campus_audit a LEFT JOIN users u ON u.id=a.actor_id ORDER BY a.id DESC LIMIT 50')->fetchAll(),'pending'=>(int)query("SELECT COUNT(*) FROM campus_messages WHERE status='pending'")->fetchColumn()]);
  }
  if($view==='student')reply($base+['record'=>campus_admin_student((string)($_GET['student']??''))]);
  reply(['error'=>'Unknown campus view.'],400);
 }
 $action=$b['action']??'';limited('campus-write',120,300,$u['id']);
 if($action==='join'){$course=campus_course($b['course']??null);demand(($b['consent']??false)===true,'Agree to the course-room guidelines first.');if(!campus_member($u['id'],$course))try{query('INSERT INTO campus_enrollments (user_id,course_id,joined_at) VALUES (?,?,?)',[$u['id'],$course,gmdate('c')]);}catch(PDOException $e){if(!in_array((string)$e->getCode(),['23000','23505'],true))throw $e;}reply($base+['room'=>campus_room($u,$course)]);}
 if($action==='post')reply($base+['room'=>campus_post($u,$b)]);
 if($action==='report'){$id=(string)($b['message']??'');$m=query('SELECT course_id,status FROM campus_messages WHERE id=?',[$id])->fetch();demand($m&&$m['status']==='approved'&&campus_member($u['id'],$m['course_id']),'This message is unavailable.');limited('campus-report',5,86400,$u['id']);try{query('INSERT INTO campus_reports (message_id,user_id,created_at) VALUES (?,?,?)',[$id,$u['id'],gmdate('c')]);}catch(PDOException $e){if(!in_array((string)$e->getCode(),['23000','23505'],true))throw $e;}reply(['notice'=>'Reported privately to the super administrator.']);}
 if($action==='remove_avatar'){query('DELETE FROM campus_avatars WHERE user_id=?',[$u['id']]);reply(['notice'=>'Profile picture removed.']);}
 if(!$staff)reply(['error'=>'Super administrator access required.'],403);
 if($action==='controls'){campus_admin_controls($u,$b);reply(['record'=>campus_admin_student($b['student']),'notice'=>'Student restrictions updated.']);}
 if($action==='moderate'){
  $id=(string)($b['message']??'');$status=$b['status']??'';demand(in_array($status,['approved','hidden'],true),'Choose an approval or removal.');$reason=campus_text($b['reason']??null,250);$m=query('SELECT id FROM campus_messages WHERE id=?',[$id])->fetch();demand((bool)$m,'Message not found.');
  db()->beginTransaction();try{query('UPDATE campus_messages SET status=? WHERE id=?',[$status,$id]);query('DELETE FROM campus_reports WHERE message_id=?',[$id]);campus_audit($u,'moderate',$id,['status'=>$status,'reason'=>$reason]);db()->commit();}catch(Throwable $e){if(db()->inTransaction())db()->rollBack();throw $e;}reply(['notice'=>'Moderation action saved.']);
 }
 if($action==='room_lock'){$course=campus_course($b['course']??null);demand(is_bool($b['locked']??null)&&is_int($b['version']??null),'Refresh the room settings.');$reason=campus_text($b['reason']??null,250);$q=query('UPDATE campus_rooms SET locked=?,version=version+1 WHERE course_id=? AND version=?',[(int)$b['locked'],$course,$b['version']]);if(!$q->rowCount())reply(['error'=>'Room settings changed. Refresh first.'],409);campus_audit($u,'room_lock',$course,['locked'=>$b['locked'],'reason'=>$reason]);reply(['notice'=>'Course room setting saved.']);}
 reply(['error'=>'Unknown campus action.'],400);
}catch(InvalidArgumentException|JsonException $e){reply(['error'=>$e instanceof InvalidArgumentException?$e->getMessage():'Invalid request.'],400);}
catch(Throwable $e){if(db()->inTransaction())db()->rollBack();error_log('Campus service unavailable: '.get_class($e));reply(['error'=>'The student campus is temporarily unavailable. Please retry.'],503);}
