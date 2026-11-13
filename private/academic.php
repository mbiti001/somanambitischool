<?php
declare(strict_types=1);
require_once __DIR__.'/community.php';
const ACADEMIC_POLICY_VERSION=1;
function college_course():array{static $c;return $c??=json_decode(file_get_contents(__DIR__.'/college-course.json'),true,64,JSON_THROW_ON_ERROR);}
function academic_ready():bool{try{query('SELECT user_id FROM academic_integrity_acceptance LIMIT 1');return true;}catch(PDOException $e){if(preg_match('/no such table|doesn.t exist/i',$e->getMessage()))return false;throw $e;}}
function academic_install():void{
 campus_install();$sql=file_get_contents(dirname(__DIR__).'/database/academic-work.sql');
 if(db()->getAttribute(PDO::ATTR_DRIVER_NAME)==='sqlite')$sql=str_replace(' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci','',$sql);
 else{$c=query("SELECT COLLATION_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='users' AND COLUMN_NAME='id'")->fetchColumn();demand(is_string($c)&&preg_match('/^utf8mb4_[a-zA-Z0-9_]+$/D',$c),'Check the account database encoding.');$sql=str_replace('utf8mb4_unicode_ci',$c,$sql);}
 foreach(explode(';',$sql) as $q)if(trim($q)!=='')db()->exec($q);
}
function academic_modules(string $course):array{
 if($course==='college')return array_column(college_course()['modules'],'title');
 if($course==='investing')return ['Foundations','Buying & selling','Research & judgement','Trading discipline'];
 if($course==='speaking')return array_column(json_decode(file_get_contents(__DIR__.'/legacy-speaking-course.json'),true)['modules'],'title');
 throw new InvalidArgumentException('Choose a valid course.');
}
function academic_context($course,$index):array{demand(is_string($course)&&in_array($course,['college','investing','speaking'],true)&&is_int($index),'Choose a valid course module.');$modules=academic_modules($course);demand(isset($modules[$index]),'Choose a valid module.');return ['course'=>$course,'index'=>$index,'title'=>$modules[$index]];}
function college_passed(string $id,int $module):bool{return (bool)query('SELECT 1 FROM academic_college_passes WHERE user_id=? AND module_index=?',[$id,$module])->fetchColumn();}
function college_complete(string $id):bool{return (int)query('SELECT COUNT(*) FROM academic_college_passes WHERE user_id=?',[$id])->fetchColumn()===4;}
function academic_previous(string $id,string $course,int $module):bool{
 if($course==='college')return $module===0||college_passed($id,$module-1);
 if(!college_complete($id))return false;if($module===0)return true;
 if($course==='investing'){$s=normalize_learning_state(json_decode(query('SELECT state_json FROM learning_accounts WHERE user_id=?',[$id])->fetchColumn(),true));return in_array($module-1,mastery_module_passes($s['modulePasses']??[]),true);}
 $s=json_decode(query('SELECT state_json FROM speaking_accounts WHERE user_id=?',[$id])->fetchColumn()?:'{}',true);return (bool)($s['modules'][$module-1]['passed']??false);
}
function academic_latest(string $id,string $course,int $module,string $task):?array{return query('SELECT * FROM academic_submissions WHERE user_id=? AND course_id=? AND module_index=? AND task_kind=? ORDER BY revision DESC LIMIT 1',[$id,$course,$module,$task])->fetch()?:null;}
function academic_both_accepted(string $id,string $course,int $module):bool{foreach(['opening','closing'] as $task)if((academic_latest($id,$course,$module,$task)['status']??'')!=='accepted')return false;return true;}
function academic_guard_learning(array $u,string $course,int $module,bool $assessment=false):void{
 if(function_exists('term_legacy_allowed')&&$course!=='college'&&!term_legacy_allowed($u,$course))reply(['error'=>'Enroll in the standard course through Courses before recording subject work.','enrollmentRequired'=>true],403);
 if(!academic_ready())return;
 if(!academic_previous($u['id'],$course,$module))reply(['error'=>'Complete College Success and the preceding module before recording new progress. Open College Success from My learning.','academicRequired'=>true],403);
 $first=academic_latest($u['id'],$course,$module,'opening');if(!$first)reply(['error'=>'Submit this module’s opening discussion before recording lesson progress.','academicRequired'=>true],403);
 if($assessment&&!academic_both_accepted($u['id'],$course,$module))reply(['error'=>'Both written tasks need teacher acceptance before this module checkpoint. Review your feedback in Module writing.','academicRequired'=>true],403);
}
function academic_prompts(string $course,int $module):array{
 $c=academic_context($course,$module);if($course==='college'){$m=college_course()['modules'][$module];return ['opening'=>$m['opening'],'closing'=>$m['closing']];}
 $open=$course==='investing'?[
 'What does owning a share mean, and what responsibilities or risks do you currently associate with investing? Discuss your initial understanding, identify a question you want to resolve and support your view with an assigned source.',
 'How would you decide whether an order instruction, transaction cost or advertised return deserves your confidence? Discuss your initial approach and one assumption you need to test.',
 'What evidence would make you consider a business worth researching? Discuss the difference between an appealing story and a justified judgement, using an assigned source.',
 'What does disciplined trading mean to you? Discuss how a written plan and an honest review might help when a practice decision goes against your expectations.'
 ]:[
 'What makes an introduction useful for a particular audience? Discuss your current speaking habits and one question you want this module to help you answer.',
 'How do you currently organise a message so a listener can follow it? Discuss one strength and one difficulty in your approach.',
 'When does a story help an audience understand an idea, and when might it mislead? Discuss your initial position with an assigned source.',
 'What do pace, pauses, emphasis and physical presence contribute to understanding? Discuss your initial view and one practice goal.',
 'How do you respond when you do not know the answer to a question? Discuss an honest and useful approach to speaking under uncertainty.',
 'What would demonstrate that a speech improved after feedback? Discuss a realistic standard for comparing a first and revised performance.'
 ];
 $close=$course==='investing'?[
 'Explain how a learner should distinguish ownership, a quoted price and a possible return. Use an original example, acknowledge uncertainty and show how an assigned source informed your reasoning.',
 'Evaluate a fictional practice trade by discussing its order instructions, costs and execution assumptions. Explain what information a real investor would still need to verify.',
 'Assess a claim about an imaginary business using evidence, assumptions and a competing explanation. Explain what the available information can and cannot establish.',
 'Review a practice decision with a clear plan, a risk assumption and evidence of what happened. Explain one justified improvement without treating a good outcome as proof of a good process.'
 ]:[
 'Describe a revised introduction for a defined audience. Explain why you changed it and how you would check whether the audience understood the main point.',
 'Explain the structure of a short talk and analyse how its beginning, main points and ending serve a defined purpose.',
 'Analyse an original short story you could use in a speech. Explain its relevance, factual limits and ethical responsibilities.',
 'Evaluate a rehearsal using pace, pauses, emphasis and physical presence. Explain a specific change and a way to assess its effect.',
 'Respond to an unfamiliar audience question and explain how your response balances usefulness, relevance and honesty.',
 'Compare a first and revised capstone talk. Use feedback and a clear criterion to explain what improved, what remains difficult and your next practice step.'
 ];return ['opening'=>$open[$module],'closing'=>$close[$module]];
}
function academic_words(string $text):int{preg_match_all("/[\\p{L}\\p{N}]+(?:[’'\\-][\\p{L}\\p{N}]+)*/u",$text,$m);return count($m[0]);}
function academic_document($raw,string $task,bool $submit):array{
 demand(is_array($raw),'Complete your paper.');$out=[];foreach(['title'=>180,'introduction'=>6000,'body'=>12000,'conclusion'=>6000,'references'=>5000,'assistance'=>1000,'response'=>1500] as $key=>$max){$v=$raw[$key]??'';demand(is_string($v)&&preg_match('//u',$v)&&text_length($v)<=$max&&!preg_match('/[\x00-\x08\x0b\x0c\x0e-\x1f\x7f]/u',$v),'Check the length and plain-text content of '.$key.'.');$out[$key]=trim($v);}
 $count=academic_words($out['introduction'].' '.$out['body'].' '.$out['conclusion']);demand($count<=(!$submit||$task==='opening'?2000:500),'The '.($task==='opening'?'opening discussion has a 2,000-word storage cap.':'closing response must contain no more than 500 words.').' Titles and references are excluded.');
 if($submit){foreach(['title','introduction','body','conclusion','references'] as $key)demand($out[$key]!=='','Complete the title, introduction, body, conclusion and references.');demand($task!=='opening'||$count>=300,'The opening discussion requires at least 300 words, excluding its title and references.');$text=$out['introduction'].' '.$out['body'].' '.$out['conclusion'];demand((bool)preg_match('/(?:\([^\n)]{1,100},\s*(?:[12][0-9]{3}|n\.d\.)[^)]*\)|\b[\p{L}][\p{L}\p{M}& .’\-]{1,80}\s*\((?:[12][0-9]{3}|n\.d\.)\))/u',$text),'Include an APA author–date citation in your discussion. Your teacher will check accuracy.');demand((bool)preg_match('/\((?:[12][0-9]{3}|n\.d\.)\)/',$out['references']),'Include a reference entry with its year or n.d. The teacher checks the full APA details.');}
 return ['document'=>$out,'words'=>$count];
}
function academic_public(?array $r):?array{if(!$r)return null;return ['id'=>$r['id'],'revision'=>(int)$r['revision'],'document'=>json_decode($r['document_json'],true),'words'=>(int)$r['word_count'],'status'=>$r['status'],'feedback'=>$r['feedback'],'rubric'=>$r['rubric_json']?json_decode($r['rubric_json'],true):null,'submittedAt'=>$r['submitted_at'],'reviewedAt'=>$r['reviewed_at']];}
function academic_payload(array $u,string $course,int $module):array{
 if(function_exists('term_legacy_allowed')&&$course!=='college'&&!term_legacy_allowed($u,$course))reply(['error'=>'Enroll through Courses to open the current subject edition.','enrollmentRequired'=>true],403);
 $context=academic_context($course,$module);$tasks=[];foreach(['opening','closing'] as $task){$draft=query('SELECT document_json,version,updated_at FROM academic_drafts WHERE user_id=? AND course_id=? AND module_index=? AND task_kind=?',[$u['id'],$course,$module,$task])->fetch();$tasks[$task]=['submission'=>academic_public(academic_latest($u['id'],$course,$module,$task)),'draft'=>$draft?['document'=>json_decode($draft['document_json'],true),'version'=>(int)$draft['version'],'updatedAt'=>$draft['updated_at']]:['document'=>null,'version'=>0,'updatedAt'=>null]];}
 $student=student_public(student_record($u));return ['context'=>$context,'prompts'=>academic_prompts($course,$module),'tasks'=>$tasks,'available'=>academic_previous($u['id'],$course,$module),'collegeComplete'=>college_complete($u['id']),'studentName'=>$student['profile']['full_name']?:$u['display_name'],'studentNumber'=>$student['number'],'policyVersion'=>ACADEMIC_POLICY_VERSION,'instructor'=>'Edwin Mbiti'];
}
function academic_save(array $u,array $b,bool $submit):array{
 if(function_exists('term_legacy_allowed')&&($b['course']??'')!=='college'&&!term_legacy_allowed($u,(string)($b['course']??'')))reply(['error'=>'Enroll through Courses before saving subject work.','enrollmentRequired'=>true],403);
 $c=academic_context($b['course']??null,$b['module']??null);$task=$b['task']??'';demand(in_array($task,['opening','closing'],true),'Choose an opening or closing task.');demand(academic_previous($u['id'],$c['course'],$c['index']),'Complete College Success and the preceding module first.');
 $latest=academic_latest($u['id'],$c['course'],$c['index'],$task);demand(!in_array($latest['status']??'',['accepted','pending'],true),'Submitted work is preserved while accepted or awaiting review.');
 if($task==='closing')demand(academic_latest($u['id'],$c['course'],$c['index'],'opening')!==null,'Submit your opening discussion before the closing response.');
 $valid=academic_document($b['document']??null,$task,$submit);$key=[$u['id'],$c['course'],$c['index'],$task];$version=$b['draftVersion']??null;demand(is_int($version),'Refresh the saved draft.');
 db()->beginTransaction();try{$lock=db()->getAttribute(PDO::ATTR_DRIVER_NAME)==='mysql'?' FOR UPDATE':'';$draft=query('SELECT * FROM academic_drafts WHERE user_id=? AND course_id=? AND module_index=? AND task_kind=?'.$lock,$key)->fetch();
  if((int)($draft['version']??0)!==$version){db()->rollBack();reply(['error'=>'A newer draft was saved on another device. Your text remains here; reload the saved draft before replacing it.','conflict'=>true],409);}
  if($submit){demand(($b['original']??false)===true&&($b['policyVersion']??0)===ACADEMIC_POLICY_VERSION,'Confirm the current integrity policy and that this work is your own.');$again=academic_latest($u['id'],$c['course'],$c['index'],$task);demand(($again['status']??'')!=='pending','Your submitted work is already awaiting review.');demand(($again['status']??'')!=='accepted','This accepted submission is preserved.');$id=bin2hex(random_bytes(16));$rev=(int)($again['revision']??0)+1;
   query('INSERT INTO academic_submissions (id,user_id,course_id,module_index,task_kind,revision,document_json,word_count,status,submitted_at) VALUES (?,?,?,?,?,?,?,?,?,?)',[$id,...$key,$rev,json_encode($valid['document'],JSON_THROW_ON_ERROR),$valid['words'],'pending',gmdate('c')]);
   query('DELETE FROM academic_drafts WHERE user_id=? AND course_id=? AND module_index=? AND task_kind=?',$key);query('DELETE FROM academic_integrity_acceptance WHERE user_id=?',[$u['id']]);query('INSERT INTO academic_integrity_acceptance (user_id,policy_version,accepted_at) VALUES (?,?,?)',[$u['id'],ACADEMIC_POLICY_VERSION,gmdate('c')]);
  }elseif($draft)query('UPDATE academic_drafts SET document_json=?,version=version+1,updated_at=? WHERE user_id=? AND course_id=? AND module_index=? AND task_kind=? AND version=?',[json_encode($valid['document'],JSON_THROW_ON_ERROR),gmdate('c'),...$key,$version]);
  else query('INSERT INTO academic_drafts (user_id,course_id,module_index,task_kind,document_json,version,updated_at) VALUES (?,?,?,?,?,1,?)',[...$key,json_encode($valid['document'],JSON_THROW_ON_ERROR),gmdate('c')]);
  db()->commit();return academic_payload($u,$c['course'],$c['index']);
 }catch(Throwable $e){if(db()->inTransaction())db()->rollBack();throw $e;}
}
function academic_gradebook(string $id):?array{
 if(!academic_ready())return null;$summary=query("SELECT assessment_key,COUNT(*) AS attempts,MAX(score) AS best_score,MAX(passed) AS passed FROM assessment_attempts WHERE user_id=? AND assessment_key LIKE 'college-%' AND submitted_at IS NOT NULL GROUP BY assessment_key",[$id])->fetchAll();
 $writing=query('SELECT a.id,a.course_id,a.module_index,a.task_kind,a.status,a.word_count,a.submitted_at,a.reviewed_at FROM academic_submissions a WHERE a.user_id=? AND NOT EXISTS(SELECT 1 FROM academic_submissions n WHERE n.user_id=a.user_id AND n.course_id=a.course_id AND n.module_index=a.module_index AND n.task_kind=a.task_kind AND n.revision>a.revision) ORDER BY a.course_id,a.module_index,a.task_kind',[$id])->fetchAll();
 return ['collegeComplete'=>college_complete($id),'summary'=>$summary,'writing'=>$writing];
}
