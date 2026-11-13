<?php
declare(strict_types=1);
require dirname(__DIR__).'/private/bootstrap.php';require dirname(__DIR__).'/private/academic.php';require_once dirname(__DIR__).'/private/terms.php';
try{
 session_begin();$u=account_user();$staff=campus_staff($u);$method=$_SERVER['REQUEST_METHOD'];$base=['csrf'=>$_SESSION['csrf'],'superAdmin'=>$staff,'installed'=>academic_ready()];
 if($method==='GET'&&($_GET['view']??'')==='college'){
  $course=college_course();foreach($course['modules'] as &$m)unset($m['questions']);unset($m);$passes=$u&&$base['installed']?query('SELECT module_index FROM academic_college_passes WHERE user_id=? ORDER BY module_index',[$u['id']])->fetchAll(PDO::FETCH_COLUMN):[];
  reply($base+['course'=>$course,'signedIn'=>(bool)($u&&$u['verified_at']!==null),'passes'=>array_map('intval',$passes)]);
 }
 if(!$u||$u['verified_at']===null)reply($base+['error'=>'Sign in with a verified student account to save academic work.'],401);
 $b=$method==='POST'?student_request_body(45000):[];
 if($method==='POST'&&($b['action']??'')==='install'){if(!$staff)reply(['error'=>'Super administrator access required.'],403);academic_install();campus_audit($u,'academic_install','college');reply(['notice'=>'Academic pathway enabled. Existing course credit remains saved.']);}
 if(!$base['installed'])reply($base+['error'=>'The academic pathway is awaiting school setup.'],503);
 if($method==='GET'){
  $view=$_GET['view']??'task';
  if($view==='task'){$course=$_GET['course']??'';$index=filter_var($_GET['module']??null,FILTER_VALIDATE_INT);demand($index!==false,'Choose a module.');reply($base+academic_payload($u,$course,$index));}
  if($view==='submission'){$r=query('SELECT * FROM academic_submissions WHERE id=?',[(string)($_GET['id']??'')])->fetch();if(!$r||(!$staff&&$r['user_id']!==$u['id']))reply(['error'=>'Submission not found.'],404);$student=query('SELECT id,email,display_name,verified_at FROM users WHERE id=?',[$r['user_id']])->fetch();reply($base+['submission'=>academic_public($r),'context'=>academic_context($r['course_id'],(int)$r['module_index']),'task'=>$r['task_kind'],'student'=>['name'=>campus_name($student),'number'=>student_public(student_record($student))['number']]]);}
  if($view==='review'){
   if(!$staff)reply(['error'=>'Super administrator access required.'],403);
   $queue=query("SELECT a.id,a.course_id,a.module_index,a.task_kind,a.revision,a.word_count,a.status,a.submitted_at,u.display_name,s.full_name,s.student_sequence FROM academic_submissions a JOIN users u ON u.id=a.user_id LEFT JOIN course_students s ON s.user_id=u.id WHERE a.status IN ('pending','integrity_review') AND NOT EXISTS(SELECT 1 FROM academic_submissions n WHERE n.user_id=a.user_id AND n.course_id=a.course_id AND n.module_index=a.module_index AND n.task_kind=a.task_kind AND n.revision>a.revision) ORDER BY a.submitted_at,a.id LIMIT 50")->fetchAll();reply($base+['queue'=>$queue]);
  }
  reply(['error'=>'Unknown academic view.'],400);
 }
 if($method!=='POST')reply(['error'=>'Method not allowed.'],405);
 $action=$b['action']??'';limited('academic-write',120,3600,$u['id']);
 if(in_array($action,['draft','submit'],true))reply($base+['record'=>academic_save($u,$b,$action==='submit'),'notice'=>$action==='submit'?'Submitted for teacher review.':'Draft saved to your student account.']);
 if($action==='review'){
  if(!$staff)reply(['error'=>'Super administrator access required.'],403);$status=$b['status']??'';demand(in_array($status,['accepted','revision','integrity_review'],true),'Choose a review decision.');$feedback=campus_text($b['feedback']??null,2500);demand(text_length($feedback)>=10,'Give clear, specific review feedback.');$rubric=$b['rubric']??null;demand(is_array($rubric),'Complete the review checklist.');foreach(['originality','reasoning','structure','evidence','apa'] as $key){demand(is_bool($rubric[$key]??null),'Review each rubric criterion.');if($status==='accepted')demand($rubric[$key],'All rubric criteria must be met before accepting the paper.');}
  db()->beginTransaction();try{$lock=db()->getAttribute(PDO::ATTR_DRIVER_NAME)==='mysql'?' FOR UPDATE':'';$r=query('SELECT * FROM academic_submissions WHERE id=?'.$lock,[(string)($b['id']??'')])->fetch();demand((bool)$r,'Submission not found.');demand($r['user_id']!==$u['id'],'A reviewer cannot accept their own academic work.');demand(in_array($r['status'],['pending','integrity_review'],true),'This submission has already received a review.');$latest=academic_latest($r['user_id'],$r['course_id'],(int)$r['module_index'],$r['task_kind']);demand($latest['id']===$r['id'],'Review the latest submitted revision.');
   query('UPDATE academic_submissions SET status=?,feedback=?,rubric_json=?,reviewed_by=?,reviewed_at=? WHERE id=?',[$status,$feedback,json_encode($rubric),$u['id'],gmdate('c'),$r['id']]);campus_audit($u,'academic_review',$r['id'],['status'=>$status,'feedback'=>$feedback,'rubric'=>$rubric]);db()->commit();reply(['notice'=>'Review saved. No quiz marks were altered.']);
  }catch(Throwable $e){if(db()->inTransaction())db()->rollBack();throw $e;}
 }
 reply(['error'=>'Unknown academic action.'],400);
}catch(InvalidArgumentException|JsonException $e){if(db()->inTransaction())db()->rollBack();reply(['error'=>$e instanceof InvalidArgumentException?$e->getMessage():'Invalid request.'],400);}
catch(Throwable $e){if(db()->inTransaction())db()->rollBack();error_log('Academic service unavailable: '.get_class($e));reply(['error'=>'Academic work is temporarily unavailable. Keep your draft open and retry.'],503);}
