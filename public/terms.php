<?php
declare(strict_types=1);
require dirname(__DIR__).'/private/bootstrap.php';require_once dirname(__DIR__).'/private/students.php';require_once dirname(__DIR__).'/private/certificates.php';require_once dirname(__DIR__).'/private/terms.php';
try{
 session_begin();$u=account_user();$method=$_SERVER['REQUEST_METHOD'];$staff=campus_staff($u);$now=time();$ready=terms_ready();$base=['csrf'=>$_SESSION['csrf'],'installed'=>$ready,'superAdmin'=>$staff,'teacherCleared'=>$staff||($ready&&$u&&$u['verified_at']!==null&&(bool)query('SELECT 1 FROM term_professors WHERE user_id=? LIMIT 1',[$u['id']])->fetchColumn()),'signedIn'=>(bool)($u&&$u['verified_at']!==null)];
 if(!in_array($method,['GET','POST'],true))reply(['error'=>'Method not allowed.'],405);
 if($ready&&$base['signedIn']){$last=query('SELECT last_run_at FROM term_scheduler WHERE id=1')->fetchColumn();if(!$last||strtotime($last)<$now-300)terms_run_due($now,20);}
 if($method==='GET'&&($_GET['view']??'catalogue')==='catalogue')reply($base+term_catalogue($base['signedIn']?$u:null,$now));
 if(!$base['signedIn'])reply($base+['error'=>'Sign in with a verified account to open your enrolled classroom.'],401);
 $b=$method==='POST'?student_request_body(45000):[];
 if($method==='GET'&&($_GET['view']??'')==='teacher_access')reply($base+['teacherCleared'=>$staff||($ready&&(bool)query('SELECT 1 FROM term_professors WHERE user_id=? LIMIT 1',[$u['id']])->fetchColumn()),'name'=>campus_name($u)]);
 if($method==='POST'&&($b['action']??'')==='install'){if(!$staff)reply(['error'=>'Super administrator access required.'],403);$already=terms_ready();terms_install();if(!$already)term_snapshot_legacy($now);campus_audit($u,'term_install','standard-courses');reply(['notice'=>'Standard courses enabled. Earlier study records are preserved.']);}
 if(!$ready)reply($base+['error'=>'Standard courses are awaiting school setup.'],503);
 // Due work is idempotent. A distinct cron heartbeat exposes whether scheduled runs
 // actually happen; ordinary page visits must never masquerade as cron execution.
 $last=query('SELECT last_run_at FROM term_scheduler WHERE id=1')->fetchColumn();if(!$last||strtotime($last)<$now-300)terms_run_due($now,20);
 if($method==='GET'){
  $view=$_GET['view']??'';
  if($view==='preview')reply($base+term_curriculum_preview($u,(string)($_GET['course']??''),(int)($_GET['week']??0)));
  if($view==='class'){demand(ctype_digit((string)($_GET['week']??'0')),'Choose a course week.');reply($base+term_class_payload($u,(string)($_GET['offering']??''),(int)($_GET['week']??0),$now));}
  if($view==='peer')reply($base+['peer'=>term_peer_payload($u,(string)($_GET['id']??''))]);
  if($view==='office')reply($base+term_office($u,isset($_GET['offering'])?(string)$_GET['offering']:null));
  if($view==='paper')reply($base+term_teacher_paper($u,(string)($_GET['id']??'')));
  if($view==='gradebook')reply($base+['grades'=>term_gradebook($u['id'])]);
  reply(['error'=>'Unknown classroom view.'],400);
 }
 $controls=campus_controls($u['id']);if($controls['suspended']||$controls['read_only'])reply(['error'=>'Saving work is restricted for this account. Contact the student office.'],403);limited('term-write',150,3600,$u['id']);$action=$b['action']??'';
 if($action==='enroll')reply($base+term_enroll($u,(string)($b['course']??''),$now,$b));
 if(in_array($action,['draft','submit'],true))reply($base+['record'=>term_save_paper($u,$b,$now,$action==='submit'),'notice'=>$action==='submit'?'Submitted. Ready work is routed when an eligible peer is available; unpaired papers can be routed to the professor.':'Private draft saved. Drafts are not collected for peer review.']);
 if($action==='peer'){term_save_peer($u,$b,$now);reply(['notice'=>'Peer review submitted. The professor can moderate its marks.']);}
 if($action==='practice'){term_save_practice($u,$b,$now);reply(['notice'=>'Practice notes saved to your student account.']);}
 if($action==='discuss'){term_discuss($u,$b,$now);reply(['notice'=>'Discussion submitted for moderation.']);}
 if($action==='moderate'){term_moderate($u,$b);reply(['notice'=>'Moderation recorded in the audit trail.']);}
 if($action==='room_lock'){term_room_lock($u,$b);reply(['notice'=>'Discussion setting saved.']);}
 if($action==='review'){term_teacher_review($u,$b,$now);reply(['notice'=>'Professor review saved with an audit record.']);}
 if($action==='correct_peer'){term_correct_peer($u,$b,$now);reply(['notice'=>'Corrected rubric saved; original marks remain in the audit history.']);}
 if($action==='extension'){term_set_extension($u,$b,$now);reply(['notice'=>'Documented extension saved.']);}
 if($action==='professor'){term_assign_professor($u,$b);reply(['notice'=>'Class professor access updated.']);}
 reply(['error'=>'Unknown classroom action.'],400);
}catch(InvalidArgumentException|JsonException $e){if(db()->inTransaction())db()->rollBack();reply(['error'=>$e instanceof InvalidArgumentException?$e->getMessage():'Invalid request.'],400);}
catch(Throwable $e){if(db()->inTransaction())db()->rollBack();error_log('Standard classroom unavailable: '.get_class($e));reply(['error'=>'The classroom is temporarily unavailable. Keep any unsaved writing open and retry.'],503);}
