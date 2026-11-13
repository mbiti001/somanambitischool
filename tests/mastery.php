<?php
require __DIR__.'/../private/practice.php';
function ensure($condition,$message){if(!$condition)throw new RuntimeException($message);}
function rejected($state,$action){try{change_progress($state,$action);throw new RuntimeException('Bypass accepted');}catch(InvalidArgumentException $e){}}
$state=fresh_state();$catalog=course_catalog()['lessons'];$path=[];foreach(array_unique(array_column($catalog,'module')) as $module)foreach($catalog as $l)if($l['module']===$module)$path[]=$l;
rejected($state,['action'=>'section','lesson'=>1,'unit'=>'understand','answers'=>[1,0]]);
$total=0;
foreach($path as $position=>$lesson){
 $id=$lesson['id'];ensure(mastery_available($id,$state),'Expected next chapter unavailable');
 rejected($state,['action'=>'lesson','lesson'=>$id,'answers'=>array_column($lesson['questions'],'correct')]);
 foreach($lesson['units'] as $index=>$unit){
  $answers=array_column($unit['questions'],'correct');
  if($index<2)rejected($state,['action'=>'section','lesson'=>$id,'unit'=>$lesson['units'][$index+1]['id'],'answers'=>array_column($lesson['units'][$index+1]['questions'],'correct')]);
  foreach($unit['questions'] as $qi=>$question)foreach($question['answers'] as $choice=>$text)if($choice!==$question['correct']){$bad=$answers;$bad[$qi]=$choice;rejected($state,['action'=>'section','lesson'=>$id,'unit'=>$unit['id'],'answers'=>$bad]);$total++;}
  rejected($state,['action'=>'section','lesson'=>$id,'unit'=>$unit['id'],'answers'=>array_slice($answers,0,-1)]);
  rejected($state,['action'=>'section','lesson'=>$id,'unit'=>$unit['id'],'answers'=>array_map('strval',$answers)]);
  $state=change_progress($state,['action'=>'section','lesson'=>$id,'unit'=>$unit['id'],'answers'=>$answers]);
  ensure(mastery_count($lesson,$state)===$index+1,'Wrong section progress');ensure(in_array($id,$state['completed'],true)===($index===2),'Chapter completed early');
 }
 $module=mastery_module_index($id);$following=$path[$position+1]??null;if(!$following||$following['module']!==$lesson['module']){ensure(mastery_module_ready($module,$state),'Module not ready after sections');if($following)ensure(!mastery_available($following['id'],$state),'Module test bypassed');$state['modulePasses'][]=$module;}
}
ensure(count($state['completed'])===18&&$state['cash']===100000,'Course progress or portfolio changed');
$copy=import_progress(json_decode(json_encode($state),true));ensure(count($copy['completed'])===18&&count((array)$copy['unitPasses'])===18,'Import dropped section progress');
ensure(empty($copy['modulePasses']),'Imported browser state forged module exam passes');
$reset=change_progress($state,['action'=>'reset']);ensure($reset['unitPasses']==$state['unitPasses'],'Reset dropped section progress');
$legacy=fresh_state();$legacy['completed']=[0,1,2];ensure(mastery_count($catalog[2],$legacy)===3,'Old credit lost');ensure(mastery_available(3,$legacy),'Legacy next chapter not available');
ensure(mastery_passes(['0'=>['review','understand']])===[],'Reordered units accepted');
echo "PASS: 54 section gates, 162 questions, $total wrong choices rejected, chapter ordering, missing/malformed attempts, legacy credit, reset and import.\n";
