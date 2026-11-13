<?php
declare(strict_types=1);
require_once __DIR__.'/students.php';
require_once __DIR__.'/certificates.php';
require_once __DIR__.'/academic.php';
require_once __DIR__.'/terms.php';
require_once __DIR__.'/material-access.php';
const ASSESSMENT_SECONDS=120;
function assessment_bank():array{
 static $bank;if($bank!==null)return $bank;
 $bank=json_decode(file_get_contents(__DIR__.'/assessment-bank.json'),true,64,JSON_THROW_ON_ERROR);
 return $bank;
}
function assessment_paper(string $key,bool $shuffle=false):array{
 if(str_starts_with($key,'t-'))return term_quiz_paper($key,$shuffle);
 $bank=assessment_bank();$modules=['Foundations','Buying & selling','Research & judgement','Trading discipline'];
 if(preg_match('/^college-([0-3])$/D',$key,$m)){$index=(int)$m[1];$module=college_course()['modules'][$index];$questions=$module['questions'];$title=$module['title'].' — College Success checkpoint';$pass=100;$kind='college';foreach($questions as &$q)$q['section']='College Success checkpoint';unset($q);if($shuffle)shuffle($questions);
 }elseif(preg_match('/^module-([0-3])$/D',$key,$m)){
  $index=(int)$m[1];$questions=$bank['modules'][$index]['questions'];$title=$modules[$index].' — module checkpoint';$pass=100;$kind='module';
  if(count($questions)!==10)throw new RuntimeException('Module paper is incomplete.');
  foreach($questions as &$q){$q['section']='Module checkpoint';}unset($q);if($shuffle)shuffle($questions);
 }elseif($key==='final'){
  $a=$bank['final']['a'];$b=$bank['final']['b'];if(count($a)!==15||count($b)!==10)throw new RuntimeException('Final paper is incomplete.');
  foreach($a as &$q){$q['section']='Section A · Single answer';if($shuffle){$old=$q['answers'];$order=range(0,count($old)-1);shuffle($order);$q['answers']=array_map(fn($i)=>$old[$i],$order);$q['correct']=array_search($q['correct'],$order,true);}}unset($q);
  foreach($b as &$q){$q['section']='Section B · Independent statements';if($shuffle)shuffle($q['statements']);}unset($q);
  if($shuffle){shuffle($a);shuffle($b);}$questions=array_merge($a,$b);$title='End-of-course examination';$pass=85;$kind='final';$index=null;
 }else throw new InvalidArgumentException('Choose a valid assessment.');
 return ['key'=>$key,'revision'=>1,'title'=>$title,'kind'=>$kind,'module'=>$index,'pass_percent'=>$pass,'seconds_per_question'=>ASSESSMENT_SECONDS,'questions'=>$questions];
}
function assessment_maximum(array $paper):int{return array_sum(array_map(fn($q)=>$q['type']==='statements'?count($q['statements']):1,$paper['questions']));}
function assessment_eligible(array $paper,array $state,?string $userId=null,?int $now=null):bool{
 if(isset($paper['term']))return $userId!==null&&term_quiz_eligible($paper,$userId,$now??time());
 if($userId!==null&&$paper['kind']!=='college'&&terms_ready()){$u=query('SELECT id,email,verified_at FROM users WHERE id=?',[$userId])->fetch();if(!term_legacy_allowed($u?:null,'investing'))return false;}
 if($paper['kind']==='college')return $userId!==null&&academic_ready()&&academic_previous($userId,'college',$paper['module'])&&academic_both_accepted($userId,'college',$paper['module']);
 if($userId!==null&&academic_ready()){if(!college_complete($userId))return false;if($paper['kind']==='module'&&!in_array($paper['module'],mastery_module_passes($state['modulePasses']??[]),true)&&!academic_both_accepted($userId,'investing',$paper['module']))return false;}
 if($paper['kind']==='module')return mastery_module_ready($paper['module'],$state);
 return count(mastery_module_passes($state['modulePasses']??[]))===4&&count(array_filter(course_catalog()['lessons'],fn($l)=>mastery_count($l,$state)===count(mastery_units($l))))===count(course_catalog()['lessons']);
}
function assessment_state(string $userId):array{$raw=query('SELECT state_json FROM learning_accounts WHERE user_id=?',[$userId])->fetchColumn();if(!$raw)throw new RuntimeException('Learning account missing.');return normalize_learning_state(json_decode($raw,true,512,JSON_THROW_ON_ERROR));}
// This check is read-only: an overview must never enroll a visitor or create a
// student record merely to decide whether assignment references can be returned.
function assessment_reference_access(array $paper,?array $user):bool{
 if(!$user||$user['verified_at']===null)return false;
 if(campus_staff($user))return true;
 if(isset($paper['term'])){
  if(!terms_ready())return false;$offering=$paper['term']['offering'];if(term_professor($user,$offering))return true;
  return (bool)query("SELECT 1 FROM term_enrollments WHERE user_id=? AND offering_id=? AND status IN ('enrolled','finished','completed') LIMIT 1",[$user['id'],$offering])->fetchColumn();
 }
 return material_access($user,$paper['kind']==='college'?'college':'investing');
}
function assessment_metadata(array $paper,bool $includeSources=true):array{
 $max=assessment_maximum($paper);$out=['key'=>$paper['key'],'title'=>$paper['title'],'kind'=>$paper['kind'],'module'=>$paper['module'],'questions'=>count($paper['questions']),'maximum'=>$max,'passPercent'=>$paper['pass_percent'],'requiredMarks'=>(int)ceil($max*$paper['pass_percent']/100),'secondsPerQuestion'=>$paper['seconds_per_question'],'maximumMinutes'=>count($paper['questions'])*2,'term'=>$paper['term']??null];
 if($includeSources)$out['referenceSources']=$paper['reference_sources']??($paper['kind']==='college'?college_course()['sources']:null);return $out;
}

function assessment_question_public(array $q):array{
 $out=['id'=>$q['id'],'type'=>$q['type'],'section'=>$q['section'],'question'=>$q['question']];
 if($q['type']==='mcq')$out['answers']=$q['answers'];
 if($q['type']==='statements')$out['statements']=array_map(fn($s)=>['text'=>$s['text']],$q['statements']);
 return $out;
}
function assessment_answer_valid($value,array $question):bool{
 if($question['type']==='tf')return is_bool($value);
 if($question['type']==='mcq')return is_int($value)&&$value>=0&&$value<count($question['answers']);
 return is_array($value)&&array_keys($value)===range(0,count($question['statements'])-1)&&count($value)===count($question['statements'])&&count(array_filter($value,'is_bool'))===count($value);
}
function assessment_grade(array $paper,array $answers):array{
 $score=0;$rows=[];$answered=0;$timedOut=0;
 foreach($paper['questions'] as $q){$entry=$answers[$q['id']]??['value'=>null,'timedOut'=>true];$value=$entry['value'];$timeout=(bool)($entry['timedOut']??false);$points=0;$max=$q['type']==='statements'?count($q['statements']):1;
  if($timeout)$timedOut++;else $answered++;
  $row=['id'=>$q['id'],'type'=>$q['type'],'section'=>$q['section'],'question'=>$q['question'],'response'=>$value,'timedOut'=>$timeout,'sources'=>$q['sources']];
  if($q['type']==='statements'){
   $items=[];foreach($q['statements'] as $i=>$statement){$right=is_array($value)&&is_bool($value[$i]??null)&&$value[$i]===$statement['correct'];if($right)$points++;$items[]=['text'=>$statement['text'],'correct'=>$statement['correct'],'response'=>is_array($value)?($value[$i]??null):null,'earned'=>$right?1:0,'explanation'=>$statement['explanation'],'sources'=>$statement['sources']??$q['sources']];}$row['statements']=$items;
  }else{
   $right=$value!==null&&$value===$q['correct'];$points=$right?1:0;$row['correct']=$q['correct'];$row['explanation']=$q['explanation'];if($q['type']==='mcq')$row['answers']=$q['answers'];
  }
  $score+=$points;$row['earned']=$points;$row['maximum']=$max;$rows[]=$row;
 }
 $max=assessment_maximum($paper);return ['score'=>$score,'maximum'=>$max,'percent'=>round($score/$max*100,2),'passed'=>$score*100>=$paper['pass_percent']*$max,'passPercent'=>$paper['pass_percent'],'requiredMarks'=>(int)ceil($max*$paper['pass_percent']/100),'answeredQuestions'=>$answered,'timedOutQuestions'=>$timedOut,'questions'=>$rows];
}
function assessment_retake_policy(string $key,string $userId,int $now):array{
 $limit=($key==='final'||preg_match('/^t-[a-f0-9]{8}-f$/D',$key))?2:5;$used=0;$blockedUntil=null;$passed=false;
 $rows=query('SELECT passed,started_at,submitted_at FROM assessment_attempts WHERE user_id=? AND assessment_key=? AND submitted_at IS NOT NULL ORDER BY submitted_at,id',[$userId,$key])->fetchAll();
 foreach($rows as $row){
  $started=strtotime($row['started_at']);$ended=strtotime($row['submitted_at']);
  if($blockedUntil!==null&&$started>=$blockedUntil){$used=0;$blockedUntil=null;}
  if((int)$row['passed']===1){$passed=true;continue;}
  $used++;if($used>=$limit)$blockedUntil=$ended+7*86400;
 }
 if($blockedUntil!==null&&$now>=$blockedUntil){$used=0;$blockedUntil=null;}
 return ['limit'=>$limit,'used'=>$used,'remaining'=>$passed?0:max(0,$limit-$used),'waitDays'=>7,'availableAt'=>$blockedUntil,'alreadyPassed'=>$passed,'canStart'=>!$passed&&$blockedUntil===null&&$used<$limit];
}
function assessment_policy_message(array $policy):string{
 if($policy['alreadyPassed'])return 'This assessment is already passed. Your result remains available for review.';
 return 'The allowed failed attempts have been used. Study the module material and return after the seven-day waiting period.';
}
function assessment_current_payload(array $row,int $now):array{
 $paper=json_decode($row['paper_json'],true,64,JSON_THROW_ON_ERROR);$answers=json_decode($row['answers_json'],true,64,JSON_THROW_ON_ERROR);$done=$row['submitted_at']!==null;
 $out=['id'=>$row['id'],'key'=>$row['assessment_key'],'version'=>(int)$row['version'],'metadata'=>assessment_metadata($paper,true),'status'=>$done?'submitted':'active','currentIndex'=>(int)$row['current_question'],'deadline'=>(int)$row['deadline_at'],'serverNow'=>$now,'startedAt'=>$row['started_at'],'submittedAt'=>$row['submitted_at'],'recordedQuestions'=>count(array_filter($answers,fn($a)=>!$a['timedOut'])),'timedOutQuestions'=>count(array_filter($answers,fn($a)=>$a['timedOut']))];
 if($done){$out['result']=json_decode($row['result_json'],true,64,JSON_THROW_ON_ERROR);$out['certificate']=isset($paper['term'])?term_certificate($row['user_id'],$paper['term']['offering']):(($certificate=query('SELECT * FROM course_certificates WHERE user_id=?',[$row['user_id']])->fetch())?certificate_public($certificate):null);$out['retakePolicy']=assessment_retake_policy($row['assessment_key'],$row['user_id'],$now);}
 else $out['question']=assessment_question_public($paper['questions'][(int)$row['current_question']]);
 return $out;
}
function assessment_credit_module(array $paper,array $result,string $userId):void{
 if($paper['kind']==='college'&&$result['passed']){if(!college_passed($userId,$paper['module']))query('INSERT INTO academic_college_passes (user_id,module_index,passed_at) VALUES (?,?,?)',[$userId,$paper['module'],gmdate('c')]);return;}
 if($paper['kind']!=='module'||!$result['passed'])return;
 $lock=db()->getAttribute(PDO::ATTR_DRIVER_NAME)==='mysql'?' FOR UPDATE':'';$account=query('SELECT state_json,version FROM learning_accounts WHERE user_id=?'.$lock,[$userId])->fetch();$state=normalize_learning_state(json_decode($account['state_json'],true,512,JSON_THROW_ON_ERROR));$passes=mastery_module_passes($state['modulePasses']??[]);$module=$paper['module'];
 if(in_array($module,$passes,true))return;
 demand(mastery_module_ready($module,$state),'Complete the module readings and earlier checkpoints before this pass can unlock the course.');
 demand(count($passes)===$module,'Pass the modules in order.');$passes[]=$module;$state['modulePasses']=$passes;
 query('UPDATE learning_accounts SET state_json=?,version=version+1,updated_at=? WHERE user_id=? AND version=?',[json_encode($state,JSON_THROW_ON_ERROR),gmdate('c'),$userId,(int)$account['version']]);
}
function assessment_finalize(array &$row,array $paper,array $answers,int $now):void{
 $result=assessment_grade($paper,$answers);if(isset($paper['term']))term_credit($paper,$result,$row['user_id'],$row['id'],$now);assessment_credit_module($paper,$result,$row['user_id']);$row['result_json']=json_encode($result,JSON_THROW_ON_ERROR);$row['score']=$result['score'];$row['maximum']=$result['maximum'];$row['passed']=$result['passed']?1:0;$row['submitted_at']=gmdate('c',$now);$row['active_key']=null;certificate_issue($paper,$row,$result);
}
function assessment_advance(array &$row,int $now):bool{
 if($row['submitted_at']!==null)return false;$paper=json_decode($row['paper_json'],true,64,JSON_THROW_ON_ERROR);$answers=json_decode($row['answers_json'],true,64,JSON_THROW_ON_ERROR);$changed=false;$total=count($paper['questions']);
 while((int)$row['current_question']<$total&&$now>=(int)$row['deadline_at']){
  $q=$paper['questions'][(int)$row['current_question']];$answers[$q['id']]=['value'=>null,'timedOut'=>true,'recordedAt'=>gmdate('c',(int)$row['deadline_at'])];$row['current_question']++;$row['deadline_at']+=(int)$paper['seconds_per_question'];$changed=true;
 }
 if($changed){$row['answers_json']=json_encode($answers,JSON_THROW_ON_ERROR);if((int)$row['current_question']===$total)assessment_finalize($row,$paper,$answers,$now);}
 return $changed;
}
function assessment_write(array &$row,int $now):void{
 $q=query('UPDATE assessment_attempts SET active_key=?,answers_json=?,result_json=?,current_question=?,deadline_at=?,updated_at=?,submitted_at=?,score=?,maximum=?,passed=?,version=version+1 WHERE id=? AND version=?',[$row['active_key'],$row['answers_json'],$row['result_json'],(int)$row['current_question'],(int)$row['deadline_at'],gmdate('c',$now),$row['submitted_at'],$row['score'],$row['maximum'],$row['passed'],$row['id'],(int)$row['version']]);
 if($q->rowCount()!==1)throw new RuntimeException('Concurrent assessment write.');$row['version']++;
}
function assessment_lock(string $id,string $userId):?array{
 // Use the same enrollment→attempt order as course starts and peer routing.
 $key=query('SELECT assessment_key FROM assessment_attempts WHERE id=? AND user_id=?',[$id,$userId])->fetchColumn();$enrollment=null;
 if(is_string($key)&&preg_match('/^t-([a-f0-9]{8})-/', $key,$match)){$enrollment=query('SELECT id FROM term_enrollments WHERE offering_id=? AND user_id=?',[$match[1],$userId])->fetchColumn();if(!$enrollment)throw new RuntimeException('Assessment enrollment is missing.');}
 db()->beginTransaction();if($enrollment)term_lock_enrollment($enrollment);
 if(db()->getAttribute(PDO::ATTR_DRIVER_NAME)==='sqlite')query('UPDATE assessment_attempts SET version=version WHERE id=? AND user_id=?',[$id,$userId]);
 $lock=db()->getAttribute(PDO::ATTR_DRIVER_NAME)==='mysql'?' FOR UPDATE':'';
 return query('SELECT * FROM assessment_attempts WHERE id=? AND user_id=?'.$lock,[$id,$userId])->fetch()?:null;
}
function assessment_resume(string $id,string $userId,int $now):?array{
 $row=assessment_lock($id,$userId);if(!$row){db()->rollBack();return null;}
 try{if(assessment_advance($row,$now))assessment_write($row,$now);db()->commit();return $row;}catch(Throwable $e){if(db()->inTransaction())db()->rollBack();throw $e;}
}
function assessment_overview(string $key,?array $user,int $now):array{
 $paper=assessment_paper($key);$materialAccess=assessment_reference_access($paper,$user);$out=['csrf'=>$_SESSION['csrf'],'metadata'=>assessment_metadata($paper,$materialAccess),'eligible'=>false,'student'=>null,'active'=>null,'attempts'=>[]];
 if(!$user||$user['verified_at']===null){$out['reason']='Sign in with a verified learning account to take this assessment.';return $out;}
 if(!$materialAccess){$out['reason']='Enroll in the appropriate course before opening its assignment references or starting this assessment.';return $out;}
 $student=student_record($user);$out['student']=['number'=>student_number((int)$student['student_sequence']),'certificateName'=>$student['full_name']?:$user['display_name']];$out['eligible']=assessment_eligible($paper,assessment_state($user['id']),$user['id'],$now);
 if(!$out['eligible'])$out['reason']=$paper['kind']==='final'?'Pass all four module tests with 100% and complete all learning sections before the final exam.':'Complete this module’s learning sections and pass the earlier module tests with 100%.';
 if(!$out['eligible']&&academic_ready())$out['reason']='Complete College Success, the preceding modules, both teacher-accepted written tasks and any required learning sections before starting this checkpoint. Earlier recorded credit is preserved.';
 if(isset($paper['term'])&&!$out['eligible'])$out['reason']='Pass the preceding module checkpoints, obtain professor acceptance and a recorded peer or professor-only route for both written tasks, and complete your assigned outgoing peer reviews. Study materials remain available at your own pace.';
 $active=query('SELECT id FROM assessment_attempts WHERE active_key=?',[$user['id'].':'.$key])->fetchColumn();
 if($active){$row=assessment_resume($active,$user['id'],$now);if($row)$out['active']=assessment_current_payload($row,$now);}
 $out['retakePolicy']=assessment_retake_policy($key,$user['id'],$now);if(!$out['retakePolicy']['canStart']){$out['eligible']=false;$out['reason']=assessment_policy_message($out['retakePolicy']);}
 $out['certificate']=isset($paper['term'])?term_certificate($user['id'],$paper['term']['offering']):(($certificate=query('SELECT * FROM course_certificates WHERE user_id=?',[$user['id']])->fetch())?certificate_public($certificate):null);
 $out['attempts']=query('SELECT id,score,maximum,passed,submitted_at FROM assessment_attempts WHERE user_id=? AND assessment_key=? AND submitted_at IS NOT NULL ORDER BY submitted_at DESC,id DESC LIMIT 10',[$user['id'],$key])->fetchAll();
 return $out;
}
function assessment_start(string $key,array $user,int $now):array{
 if(str_starts_with($key,'t-'))return assessment_start_term($key,$user,$now);
 $existing=query('SELECT id FROM assessment_attempts WHERE active_key=?',[$user['id'].':'.$key])->fetchColumn();if($existing){$row=assessment_resume($existing,$user['id'],$now);if($row&&$row['submitted_at']===null)return $row;}
 $policy=assessment_retake_policy($key,$user['id'],$now);demand($policy['canStart'],assessment_policy_message($policy));
 $paper=assessment_paper($key,true);demand(assessment_eligible($paper,assessment_state($user['id']),$user['id'],$now),'Complete the required learning and module checkpoints first.');$student=student_record($user);$paper['candidate_name']=$student['full_name']?:$user['display_name'];$paper['candidate_student_number']=student_number((int)$student['student_sequence']);$id=bin2hex(random_bytes(16));
 try{query('INSERT INTO assessment_attempts (id,user_id,assessment_key,active_key,paper_json,answers_json,current_question,deadline_at,started_at,updated_at) VALUES (?,?,?,?,?,?,0,?,?,?)',[$id,$user['id'],$key,$user['id'].':'.$key,json_encode($paper,JSON_THROW_ON_ERROR),'{}',$now+ASSESSMENT_SECONDS,gmdate('c',$now),gmdate('c',$now)]);}
 catch(PDOException $e){$existing=query('SELECT id FROM assessment_attempts WHERE active_key=?',[$user['id'].':'.$key])->fetchColumn();if($existing)return assessment_resume($existing,$user['id'],$now);throw $e;}
 return query('SELECT * FROM assessment_attempts WHERE id=?',[$id])->fetch();
}
function assessment_record_answer(string $id,array $user,array $body,int $now):array{
 demand(preg_match('/^[a-f0-9]{32}$/D',$id)===1,'Choose a valid attempt.');demand(is_int($body['version']??null)&&is_string($body['questionId']??null),'Refresh this assessment before answering.');
 $row=assessment_lock($id,$user['id']);if(!$row){db()->rollBack();return ['status'=>404,'data'=>['error'=>'This assessment attempt was not found.']];}
 try{
  $changed=assessment_advance($row,$now);
  if($row['submitted_at']!==null||$changed||(int)$row['version']!==$body['version']){
   if($changed)assessment_write($row,$now);db()->commit();return ['status'=>409,'data'=>['error'=>$changed?'Time expired for the previous question. The current saved position is shown.':'This attempt changed on another device. Review its saved position.','attempt'=>assessment_current_payload($row,$now)]];
  }
  $paper=json_decode($row['paper_json'],true,64,JSON_THROW_ON_ERROR);$q=$paper['questions'][(int)$row['current_question']];
  demand($body['questionId']===$q['id'],'Answer the current question. Previous answers are locked.');$value=$body['value']??null;demand(assessment_answer_valid($value,$q),'Answer every choice in this question before continuing.');
  $answers=json_decode($row['answers_json'],true,64,JSON_THROW_ON_ERROR);$answers[$q['id']]=['value'=>$value,'timedOut'=>false,'recordedAt'=>gmdate('c',$now)];$row['answers_json']=json_encode($answers,JSON_THROW_ON_ERROR);$row['current_question']++;$row['deadline_at']=$now+ASSESSMENT_SECONDS;
  if((int)$row['current_question']===count($paper['questions']))assessment_finalize($row,$paper,$answers,$now);
  assessment_write($row,$now);db()->commit();return ['status'=>200,'data'=>['attempt'=>assessment_current_payload($row,$now)]];
 }catch(Throwable $e){if(db()->inTransaction())db()->rollBack();throw $e;}
}

// Freeze this student's peer duties atomically with starting a module attempt.
// Allocators use the same enrollment lock before deciding a new review duty.
function assessment_start_term(string $key,array $user,int $now):array{
 $paper=assessment_paper($key,true);$t=$paper['term'];$student=student_record($user);
 $en=query('SELECT id FROM term_enrollments WHERE user_id=? AND offering_id=?',[$user['id'],$t['offering']])->fetchColumn();demand((bool)$en,'Enroll in this course before starting an assessment.');
 $paper['candidate_name']=$student['full_name']?:$user['display_name'];$paper['candidate_student_number']=student_number((int)$student['student_sequence']);
 for($retry=0;$retry<2;$retry++){
  db()->beginTransaction();try{
   term_lock_enrollment($en);
   $existing=query('SELECT id FROM assessment_attempts WHERE active_key=?',[$user['id'].':'.$key])->fetchColumn();
   if($existing){db()->commit();$row=assessment_resume($existing,$user['id'],$now);if($row&&$row['submitted_at']===null)return $row;continue;}
   $policy=assessment_retake_policy($key,$user['id'],$now);demand($policy['canStart'],assessment_policy_message($policy));demand(term_quiz_eligible($paper,$user['id'],$now),'Complete this module’s writing, peer-review and preceding mastery requirements first.');
   $id=bin2hex(random_bytes(16));query('INSERT INTO assessment_attempts (id,user_id,assessment_key,active_key,paper_json,answers_json,current_question,deadline_at,started_at,updated_at) VALUES (?,?,?,?,?,?,0,?,?,?)',[$id,$user['id'],$key,$user['id'].':'.$key,json_encode($paper,JSON_THROW_ON_ERROR),'{}',$now+ASSESSMENT_SECONDS,gmdate('c',$now),gmdate('c',$now)]);db()->commit();return query('SELECT * FROM assessment_attempts WHERE id=?',[$id])->fetch();
  }catch(Throwable $e){if(db()->inTransaction())db()->rollBack();throw $e;}
 }
 throw new RuntimeException('Refresh the saved assessment before starting.');
}
