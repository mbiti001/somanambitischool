<?php
declare(strict_types=1);
const STUDENT_PROFILE_OPTIONS=[
 'experience'=>['beginner','some_experience','regular_practice'],
 'learning_goal'=>['basics','buying_selling','long_term','business_research','risk_discipline'],
 'preferred_language'=>['English','Kiswahili','Either'],
 'occupation'=>['student','employed','self_employed','seeking_work','retired','prefer_not_to_say']
];
function student_number(int $sequence):string{return sprintf('SMB-%06d',$sequence);}
function student_admin(?array $user):bool{
 if(campus_staff($user))return true;
 if(!$user||$user['verified_at']===null)return false;
 $path=getenv('HISA_STUDENT_CONFIG_PATH')?:__DIR__.'/student-config.php';
 $config=is_file($path)?require $path:[];$emails=is_array($config)?($config['admin_emails']??[]):[];
 return is_array($emails)&&in_array(strtolower($user['email']),array_map('strtolower',array_filter($emails,'is_string')),true);
}
function student_record(array $user):array{
 demand($user['verified_at']!==null,'Verify your email to receive a student number.');
 $row=query('SELECT * FROM course_students WHERE user_id=?',[$user['id']])->fetch();if($row)return $row;
 $pdo=db();$pdo->beginTransaction();
 try{
  query('UPDATE student_number_counter SET next_number=next_number+1 WHERE counter_key=?',['student']);
  $sequence=(int)query('SELECT next_number FROM student_number_counter WHERE counter_key=?',['student'])->fetchColumn();
  if($sequence<1)throw new RuntimeException('Student counter is not installed.');
  query('INSERT INTO course_students (user_id,student_sequence,enrolled_at) VALUES (?,?,?)',[$user['id'],$sequence,gmdate('c')]);
  $pdo->commit();
 }catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();$row=query('SELECT * FROM course_students WHERE user_id=?',[$user['id']])->fetch();if($row)return $row;throw $e;}
 return query('SELECT * FROM course_students WHERE user_id=?',[$user['id']])->fetch();
}
function student_public(array $row):array{
 $out=['number'=>student_number((int)$row['student_sequence']),'enrolledAt'=>$row['enrolled_at'],'profileVersion'=>(int)$row['profile_version'],'profileComplete'=>$row['consented_at']!==null];
 $out['profile']=[];foreach(['full_name','country','region','experience','learning_goal','preferred_language','occupation','consented_at','profile_updated_at'] as $key)$out['profile'][$key]=$row[$key];return $out;
}
function student_profile_validate($raw):array{
 demand(is_array($raw),'Complete your learner profile.');$p=[];
 foreach(['full_name'=>120,'country'=>80,'region'=>80] as $key=>$max){$v=$raw[$key]??'';demand(is_string($v)&&text_length(trim($v))<=$max,'Check the length of your '.$key.'.');$v=trim($v);demand(!preg_match('/[\x00-\x1F\x7F]/u',$v),'Use plain text without control characters.');$p[$key]=$v;}
 demand($p['full_name']!==''&&$p['country']!=='','Enter your name and country.');
 foreach(STUDENT_PROFILE_OPTIONS as $key=>$allowed){$v=$raw[$key]??'';demand(is_string($v)&&in_array($v,$allowed,true),'Choose a valid '.$key.'.');$p[$key]=$v;}
 demand(($raw['consent']??null)===true,'Please confirm how we may use this profile before saving.');return $p;
}
function student_save_profile(array $user,array $record,array $body):array{
 demand(is_int($body['version']??null),'Refresh your profile before saving.');$p=student_profile_validate($body['profile']??null);$now=gmdate('c');
 $q=query('UPDATE course_students SET full_name=?,country=?,region=?,experience=?,learning_goal=?,preferred_language=?,occupation=?,consented_at=?,profile_updated_at=?,profile_version=profile_version+1 WHERE user_id=? AND profile_version=?',[$p['full_name'],$p['country'],$p['region']?:null,$p['experience'],$p['learning_goal'],$p['preferred_language'],$p['occupation'],$now,$now,$user['id'],$body['version']]);
 if(!$q->rowCount())reply(['error'=>'Your profile changed on another device. The latest version is shown; review it before saving.','conflict'=>true,'student'=>student_public(student_record($user))],409);
 return student_public(student_record($user));
}
function student_clear_profile(array $user,array $body):array{
 demand(is_int($body['version']??null),'Refresh your profile before changing it.');
 $q=query('UPDATE course_students SET full_name=NULL,country=NULL,region=NULL,experience=NULL,learning_goal=NULL,preferred_language=NULL,occupation=NULL,consented_at=NULL,profile_updated_at=?,profile_version=profile_version+1 WHERE user_id=? AND profile_version=?',[gmdate('c'),$user['id'],$body['version']]);
 if(!$q->rowCount())reply(['error'=>'Your profile changed. Review the latest version.','conflict'=>true,'student'=>student_public(student_record($user))],409);return student_public(student_record($user));
}
function student_backfill():int{
 $count=0;$users=query('SELECT u.id,u.email,u.verified_at FROM users u LEFT JOIN course_students s ON s.user_id=u.id WHERE u.verified_at IS NOT NULL AND s.user_id IS NULL ORDER BY u.created_at,u.id')->fetchAll();
 foreach($users as $u){student_record($u);$count++;}return $count;
}
function student_admin_overview(string $search='',int $page=1):array{
 student_backfill();$search=trim($search);demand(text_length($search)<=100,'Use a shorter student search.');$page=max(1,min(100000,$page));$limit=25;$offset=($page-1)*$limit;
 $where='u.verified_at IS NOT NULL';$params=[];
 if($search!==''){$needle='%'.str_replace(['!','%','_'],['!!','!%','!_'],$search).'%';$where.=" AND (u.email LIKE ? ESCAPE '!' OR u.display_name LIKE ? ESCAPE '!' OR s.full_name LIKE ? ESCAPE '!' OR s.region LIKE ? ESCAPE '!'";$params=[$needle,$needle,$needle,$needle];if(preg_match('/^(?:SMB-)?0*([1-9][0-9]*)$/i',$search,$m)){$where.=' OR s.student_sequence=?';$params[]=(int)$m[1];}$where.=')';}
 $count=(int)query('SELECT COUNT(*) FROM users u LEFT JOIN course_students s ON s.user_id=u.id WHERE '.$where,$params)->fetchColumn();
 $rows=query('SELECT u.email,u.display_name,s.* FROM users u LEFT JOIN course_students s ON s.user_id=u.id WHERE '.$where.' ORDER BY u.created_at DESC,u.id LIMIT '.$limit.' OFFSET '.$offset,$params)->fetchAll();
 $students=[];foreach($rows as $r){$v=['number'=>$r['student_sequence']?student_number((int)$r['student_sequence']):'Pending assignment','name'=>$r['full_name']?:$r['display_name'],'email'=>$r['email'],'enrolledAt'=>$r['enrolled_at'],'profileComplete'=>$r['consented_at']!==null];foreach(['country','region','experience','learning_goal','preferred_language','occupation'] as $key)$v[$key]=$r[$key];$students[]=$v;}
 $total=(int)query('SELECT COUNT(*) FROM users WHERE verified_at IS NOT NULL')->fetchColumn();$unverified=(int)query('SELECT COUNT(*) FROM users WHERE verified_at IS NULL')->fetchColumn();$profiles=(int)query('SELECT COUNT(*) FROM course_students s JOIN users u ON u.id=s.user_id WHERE u.verified_at IS NOT NULL AND s.consented_at IS NOT NULL')->fetchColumn();
 $groups=[];foreach(['experience','learning_goal','country','preferred_language'] as $column){$groups[$column]=query('SELECT s.'.$column.' AS category,COUNT(*) AS count FROM course_students s JOIN users u ON u.id=s.user_id WHERE u.verified_at IS NOT NULL GROUP BY s.'.$column.' ORDER BY count DESC')->fetchAll();}
 return ['enrolled'=>$total,'pendingVerification'=>$unverified,'profilesCompleted'=>$profiles,'students'=>$students,'matches'=>$count,'page'=>$page,'pageSize'=>$limit,'groups'=>$groups];
}
function student_assessment_summary(string $userId):object{
 $rows=query('SELECT assessment_key,COUNT(*) AS attempts,MAX(passed) AS passed,MAX(100.0*score/maximum) AS best_percent FROM assessment_attempts WHERE user_id=? AND submitted_at IS NOT NULL GROUP BY assessment_key',[$userId])->fetchAll();$out=[];foreach($rows as $r)$out[$r['assessment_key']]=['attempts'=>(int)$r['attempts'],'passed'=>(bool)$r['passed'],'bestPercent'=>round((float)$r['best_percent'],2)];return (object)$out;
}
function student_request_body(int $max=20000):array{
 if(($_SERVER['HTTP_ORIGIN']??'')!==rtrim(settings()['site_url'],'/')||($_SERVER['HTTP_SEC_FETCH_SITE']??'')==='cross-site')reply(['error'=>'Use this form on Soma na Mbiti.'],403);
 if(!str_starts_with($_SERVER['CONTENT_TYPE']??'','application/json'))reply(['error'=>'Expected JSON.'],415);
 if((int)($_SERVER['CONTENT_LENGTH']??0)>$max)reply(['error'=>'Request too large.'],413);
 $raw=file_get_contents('php://input',false,null,0,$max+1);if(strlen($raw)>$max)reply(['error'=>'Request too large.'],413);$body=json_decode($raw,true,32,JSON_THROW_ON_ERROR);
 if(!is_array($body))reply(['error'=>'Invalid request.'],400);
 if(!hash_equals($_SESSION['csrf'],$_SERVER['HTTP_X_CSRF_TOKEN']??''))reply(['error'=>'Your session changed. Refresh and try again.'],403);return $body;
}
