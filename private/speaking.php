<?php
declare(strict_types=1);
function speaking_course():array{static $c;return $c??=json_decode(file_get_contents(__DIR__.'/legacy-speaking-course.json'),true,64,JSON_THROW_ON_ERROR)['modules'];}
function speaking_empty():array{return ['version'=>1,'modules'=>(object)[]];}
function speaking_module():array{return ['notes'=>'','reflection'=>'','practised'=>false,'passed'=>false,'rubric'=>(object)[],'attempts'=>0];}
function speaking_row(string $id):array{
 $row=query('SELECT * FROM speaking_accounts WHERE user_id=?',[$id])->fetch();if($row)return $row;
 try{query('INSERT INTO speaking_accounts (user_id,state_json,version,updated_at) VALUES (?,?,1,?)',[$id,json_encode(speaking_empty()),gmdate('c')]);}catch(PDOException $e){if(!in_array((string)$e->getCode(),['23000','23505'],true))throw $e;}
 return query('SELECT * FROM speaking_accounts WHERE user_id=?',[$id])->fetch();
}
function speaking_payload(array $row):array{$state=json_decode($row['state_json'],true,64,JSON_THROW_ON_ERROR);$state['modules']=(object)$state['modules'];return ['progress'=>$state,'version'=>(int)$row['version'],'updatedAt'=>$row['updated_at']];}
function speaking_value(string $field,$value){
 if(in_array($field,['notes','reflection'],true)){demand(is_string($value)&&strlen($value)<=20000&&preg_match('//u',$value)===1&&text_length($value)<=5000,'Use up to 5,000 characters of valid text.');return $value;}
 if($field==='practised'){demand(is_bool($value),'Choose whether the practice is complete.');return $value;}
 if($field==='rubric'){demand(is_array($value)&&count($value)<=4,'Choose valid rubric scores.');$out=[];foreach($value as $k=>$v){demand(in_array((string)$k,['0','1','2','3'],true)&&is_int($v)&&$v>=0&&$v<=4,'Choose rubric scores from zero to four.');$out[(string)$k]=$v;}return (object)$out;}
 throw new InvalidArgumentException('This field cannot be changed.');
}
function speaking_result(array $row):array{return ['id'=>$row['id'],'module'=>(int)$row['module_index'],'answers'=>json_decode($row['answers_json'],true,16,JSON_THROW_ON_ERROR),'score'=>(int)$row['score'],'maximum'=>10,'submittedAt'=>$row['submitted_at']];}
function speaking_write(array $user,array $body):array{
 $id=$user['id'];$action=$body['action']??'';if($action==='quiz'&&function_exists('academic_guard_learning')&&is_int($body['module']??null))academic_guard_learning($user,'speaking',$body['module'],true);demand(in_array($action,['field','quiz','import'],true),'Choose a valid speaking action.');speaking_row($id);
 db()->beginTransaction();try{
  $lock=db()->getAttribute(PDO::ATTR_DRIVER_NAME)==='mysql'?' FOR UPDATE':'';
  $row=query('SELECT * FROM speaking_accounts WHERE user_id=?'.$lock,[$id])->fetch();
  if($action==='quiz'){
   $request=$body['requestId']??'';demand(is_string($request)&&preg_match('/^[a-f0-9]{32}$/D',$request)===1,'Choose a valid submission.');
   $previous=query('SELECT * FROM speaking_quiz_results WHERE id=?',[$request])->fetch();
   if($previous){demand($previous['user_id']===$id&&(int)$previous['module_index']===($body['module']??null)&&json_decode($previous['answers_json'],true)===($body['answers']??null),'This submission identifier was already used.');db()->commit();return ['status'=>200,'data'=>['record'=>speaking_payload($row),'result'=>speaking_result($previous)]];}
  }
  demand(is_int($body['version']??null),'Refresh your saved speaking progress.');
  if($body['version']!==(int)$row['version']){db()->rollBack();return ['status'=>409,'data'=>['error'=>'Another device saved a newer version.','conflict'=>true,'record'=>speaking_payload($row)]];}
  $state=json_decode($row['state_json'],true,64,JSON_THROW_ON_ERROR);$modules=$state['modules'];$result=null;
  if($action==='import'){
   demand((int)$row['version']===1,'Your online speaking course already has work. Browser import is available only before the first online save.');
   $raw=$body['progress']??null;demand(is_array($raw)&&($raw['version']??null)===1&&is_array($raw['modules']??null),'Choose valid browser speaking progress.');$prefix=true;
   for($i=0;$i<6;$i++){if(!isset($raw['modules'][$i])){$prefix=false;continue;}$r=$raw['modules'][$i];demand(is_array($r),'Choose valid browser speaking progress.');$m=speaking_module();foreach(['notes','reflection','practised','rubric'] as $f)$m[$f]=speaking_value($f,$r[$f]??($f==='practised'?false:($f==='rubric'?[]:'')));
    $imported=$prefix&&($r['passed']??false)===true&&$m['practised']&&text_length(trim($m['reflection']))>=20;$m['passed']=$imported&&!(function_exists('academic_ready')&&academic_ready());$m['importedCompletion']=$imported;if(!$imported)$prefix=false;$m['importedAt']=gmdate('c');$modules[$i]=$m;
   }
  }else{
   $i=$body['module']??null;demand(is_int($i)&&$i>=0&&$i<6,'Choose a valid module.');$m=$modules[$i]??speaking_module();
   if($action==='field'){$f=$body['field']??'';demand(is_string($f),'Choose a valid field.');$m[$f]=speaking_value($f,$body['value']??null);}
   else{
    demand($i===0||($modules[$i-1]['passed']??false),'Complete the previous module before submitting this checkpoint.');$answers=$body['answers']??null;demand(is_array($answers)&&array_is_list($answers)&&count($answers)===10,'Answer all ten questions before submitting.');foreach($answers as $answer)demand(is_bool($answer),'Answer every question True or False.');
    $score=0;foreach(speaking_course()[$i]['questions'] as $n=>$q)if($answers[$n]===$q['correct'])$score++;
    $time=gmdate('c');query('INSERT INTO speaking_quiz_results (id,user_id,module_index,answers_json,score,submitted_at) VALUES (?,?,?,?,?,?)',[$request,$id,$i,json_encode($answers),$score,$time]);
    $result=['id'=>$request,'module'=>$i,'answers'=>$answers,'score'=>$score,'maximum'=>10,'submittedAt'=>$time];$m['lastResult']=$result;$m['attempts']=(int)$m['attempts']+1;
    if($score===10&&$m['practised']&&text_length(trim($m['reflection']))>=20){$m['passed']=true;$m['completedAt']=$time;$m['importedCompletion']=false;}
   }$modules[$i]=$m;
  }
  $state['modules']=(object)$modules;$time=gmdate('c');query('UPDATE speaking_accounts SET state_json=?,version=version+1,updated_at=? WHERE user_id=? AND version=?',[json_encode($state,JSON_THROW_ON_ERROR),$time,$id,$row['version']]);$row=query('SELECT * FROM speaking_accounts WHERE user_id=?',[$id])->fetch();db()->commit();$out=['record'=>speaking_payload($row)];if($result)$out['result']=$result;return ['status'=>200,'data'=>$out];
 }catch(Throwable $e){if(db()->inTransaction())db()->rollBack();throw $e;}
}
