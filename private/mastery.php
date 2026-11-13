<?php
function mastery_units(array $lesson):array{return $lesson['units']??[['id'=>'review','questions'=>$lesson['questions']]];}
function mastery_passes($raw):array{
 $result=[];if(is_object($raw))$raw=(array)$raw;if(!is_array($raw))return $result;
 foreach(course_catalog()['lessons'] as $lesson){$values=$raw[$lesson['id']]??null;if(!is_array($values))continue;$prefix=[];foreach(mastery_units($lesson) as $unit){if(($values[count($prefix)]??null)!==$unit['id'])break;$prefix[]=$unit['id'];}if($prefix)$result[$lesson['id']]=$prefix;}
 return $result;
}
function mastery_count(array $lesson,array $state):int{return in_array($lesson['id'],$state['completed'],true)?count(mastery_units($lesson)):count(mastery_passes($state['unitPasses']??[])[$lesson['id']]??[]);}
function mastery_module_passes($raw):array{
 if(!is_array($raw))return [];$out=[];for($i=0;$i<4;$i++){if(($raw[$i]??null)!==$i)break;$out[]=$i;}return $out;
}
function mastery_module_index(int $id):int{
 $lessons=course_catalog()['lessons'];$names=array_values(array_unique(array_column($lessons,'module')));return array_search($lessons[$id]['module'],$names,true);
}
function mastery_module_ready(int $module,array $state):bool{
 if($module<0||$module>3||count(mastery_module_passes($state['modulePasses']??[]))<$module)return false;
 foreach(course_catalog()['lessons'] as $lesson)if(mastery_module_index($lesson['id'])===$module&&mastery_count($lesson,$state)!==count(mastery_units($lesson)))return false;
 return true;
}
function mastery_available(int $id,array $state):bool{
 $lessons=course_catalog()['lessons'];if(!isset($lessons[$id]))return false;
 $module=mastery_module_index($id);if(count(mastery_module_passes($state['modulePasses']??[]))<$module)return false;
 if(in_array($id,$state['completed'],true))return true;
 $modules=array_values(array_unique(array_column($lessons,'module')));
 foreach($modules as $name)foreach($lessons as $lesson)if($lesson['module']===$name){if($lesson['id']===$id)return true;if(!in_array($lesson['id'],$state['completed'],true))return false;}
 return false;
}
function mastery_action(array $state,array $action):array{
 $id=$action['lesson']??null;demand(is_int($id)&&isset(course_catalog()['lessons'][$id]),'Choose a valid chapter.');
 demand(mastery_available($id,$state),'Complete the earlier chapters before starting this chapter.');$lesson=course_catalog()['lessons'][$id];$units=mastery_units($lesson);$index=array_search($action['unit']??null,array_column($units,'id'),true);
 demand($index!==false&&$index<=mastery_count($lesson,$state),'Earn 100% in the previous section to unlock this one.');
 $questions=$units[$index]['questions'];$answers=$action['answers']??null;
 demand(is_array($answers)&&array_keys($answers)===range(0,count($questions)-1),'Answer every question before submitting this section.');
 foreach($questions as $i=>$question)demand(is_int($answers[$i])&&$answers[$i]===$question['correct'],'Answer every question correctly to earn 100% and unlock the next section.');
 $count=max(mastery_count($lesson,$state),$index+1);$passes=mastery_passes($state['unitPasses']??[]);$passes[$id]=array_slice(array_column($units,'id'),0,$count);$state['unitPasses']=(object)$passes;
 if($count===count($units)&&!in_array($id,$state['completed'],true))$state['completed'][]=$id;
 return $state;
}
