<?php
declare(strict_types=1);
require dirname(__DIR__).'/private/bootstrap.php';
require dirname(__DIR__).'/private/tutor.php';
try{
 session_begin();$c=tutor_config();$user=account_user();
 if($_SERVER['REQUEST_METHOD']==='GET')reply(['enabled'=>tutor_ready($c),'eligible'=>$user&&$user['verified_at']!==null,'csrf'=>$_SESSION['csrf']]);
 if($_SERVER['REQUEST_METHOD']!=='POST')reply(['error'=>'Method not allowed.'],405);
 if(($_SERVER['HTTP_ORIGIN']??'')!==rtrim(settings()['site_url'],'/')||($_SERVER['HTTP_SEC_FETCH_SITE']??'')==='cross-site')reply(['error'=>'Open the tutor on this website.'],403);
 if(!str_starts_with($_SERVER['CONTENT_TYPE']??'','application/json'))reply(['error'=>'Expected JSON.'],415);
 if((int)($_SERVER['CONTENT_LENGTH']??0)>5000)reply(['error'=>'Question too long.'],413);
 $raw=file_get_contents('php://input',false,null,0,5001);if(strlen($raw)>5000)reply(['error'=>'Question too long.'],413);
 $b=json_decode($raw,true,16,JSON_THROW_ON_ERROR);if(!is_array($b))reply(['error'=>'Invalid request.'],400);
 if(!hash_equals($_SESSION['csrf'],$_SERVER['HTTP_X_CSRF_TOKEN']??''))reply(['error'=>'Your session changed. Refresh and try again.'],403);
 if(($b['action']??'')==='clear'){unset($_SESSION['tutor']);reply(['cleared'=>true]);}
 limited('tutor-requests',20,60);$question=$b['question']??null;
 if(!is_string($question)||!trim($question)||text_length($question)>600)reply(['error'=>'Ask a question of up to 600 characters.'],400);
 $question=trim($question);if(($b['action']??'')==='guard')reply(['assessment'=>tutor_assessment($question,course_catalog())]);$lesson=$b['lesson']??null;$index=$b['unit']??null;
 if(!is_int($lesson)||!is_int($index))reply(['error'=>'Open a lesson section to use the AI tutor.'],400);
 $course=course_catalog();$context=tutor_context($course,$lesson,$index);$unit=$course['lessons'][$lesson]['units'][$index];
 if(tutor_assessment($question,$course))reply(tutor_hint($unit));
 if(!$user||$user['verified_at']===null)reply(['error'=>'Sign in with a verified learning account for the AI tutor. The built-in guide is still available.'],401);
 $row=query('SELECT state_json FROM learning_accounts WHERE user_id=?',[$user['id']])->fetch();
 $progress=normalize_learning_state(json_decode($row['state_json'],true,512,JSON_THROW_ON_ERROR));
 if(!mastery_available($lesson,$progress)||$index>mastery_count($course['lessons'][$lesson],$progress))reply(['error'=>'Finish the earlier sections before asking about this section.'],403);
 if(!tutor_ready($c))reply(['error'=>'AI explanations are not activated yet. The built-in study guide is available.'],503);
 $conversation=$b['conversation']??'';if(!is_string($conversation)||!preg_match('/^[a-f0-9]{32}$/D',$conversation))reply(['error'=>'Please reopen the conversation and try again.'],400);
 $key=$user['id'].':'.$lesson.':'.$index.':'.$conversation;$saved=$_SESSION['tutor']??[];$history=($saved['context']??'')===$key?($saved['messages']??[]):[];
 limited('tutor-user-minute',5,60,$user['id']);
 if(!tutor_reserve($user['id'],$c))reply(['error'=>'The AI tutor has reached today’s usage limit. Continue with the study guide or try again after the daily reset.'],429);
 $nonce=bin2hex(random_bytes(12));$_SESSION['tutor']=['context'=>$key,'messages'=>$history,'nonce'=>$nonce];$userId=$user['id'];
 $payload=tutor_payload($question,$context,$history,$c);session_write_close();
 $result=tutor_parse(tutor_request($payload,$c),$context,$unit);
 session_begin();$currentUser=account_user();
 if(!$currentUser||$currentUser['id']!==$userId||($_SESSION['tutor']['nonce']??null)!==$nonce)reply(['error'=>'The conversation changed. Please ask again.'],409);
 if($result['mode']==='ai'){$history[]=['role'=>'user','content'=>$question];$history[]=['role'=>'assistant','content'=>$result['text']];$_SESSION['tutor']['messages']=array_slice($history,-6);}
 reply($result);
}catch(InvalidArgumentException|JsonException $e){reply(['error'=>'Please choose a valid section and ask a short question.'],400);}
catch(Throwable $e){error_log('Soma na Mbiti tutor temporarily unavailable: '.get_class($e));reply(['error'=>'AI explanations are temporarily unavailable. Try the study guide below, or ask again later.'],503);}
