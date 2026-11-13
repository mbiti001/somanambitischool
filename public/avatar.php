<?php
declare(strict_types=1);
require dirname(__DIR__).'/private/bootstrap.php';require dirname(__DIR__).'/private/community.php';require dirname(__DIR__).'/private/avatar-processor.php';
try{
 session_begin();$u=account_user();if(!$u||$u['verified_at']===null)reply(['error'=>'Sign in to view profile pictures.'],401);if(!campus_installed())reply(['error'=>'Profile pictures are not yet enabled.'],503);
 if($_SERVER['REQUEST_METHOD']==='POST'){
  if(($_SERVER['HTTP_ORIGIN']??'')!==rtrim(settings()['site_url'],'/')||($_SERVER['HTTP_SEC_FETCH_SITE']??'')==='cross-site'||!hash_equals($_SESSION['csrf'],$_SERVER['HTTP_X_CSRF_TOKEN']??''))reply(['error'=>'Refresh your profile and try again.'],403);
  limited('avatar-upload',10,3600,$u['id']);$f=$_FILES['avatar']??null;demand(count($_FILES)===1&&is_array($f)&&!is_array($f['error']??null)&&($f['error']??-1)===UPLOAD_ERR_OK,'Choose one JPEG, PNG or WebP profile picture, no larger than 1 MB.');
  demand($f['size']>0&&$f['size']<=1048576&&is_uploaded_file($f['tmp_name']),'Profile pictures must be no larger than 1 MB.');$raw=file_get_contents($f['tmp_name']);demand(strlen($raw)<=1048576,'Profile pictures must be no larger than 1 MB.');$info=@getimagesizefromstring($raw);demand($info&&in_array($info[2],[IMAGETYPE_JPEG,IMAGETYPE_PNG,IMAGETYPE_WEBP],true)&&$info[0]<=4096&&$info[1]<=4096&&$info[0]*$info[1]<=8000000,'Use a JPEG, PNG or WebP up to 4096 pixels and eight megapixels. SVG, GIF and video are not accepted.');
  $encoded=campus_avatar_encode($raw,$info);
  $stamp=bin2hex(random_bytes(8));db()->beginTransaction();try{query('DELETE FROM campus_avatars WHERE user_id=?',[$u['id']]);query('INSERT INTO campus_avatars (user_id,image_data,updated_at) VALUES (?,?,?)',[$u['id'],$encoded,$stamp]);db()->commit();}catch(Throwable $e){if(db()->inTransaction())db()->rollBack();throw $e;}
  reply(['notice'=>'Profile picture saved. A single compact picture replaces the previous one.','avatar'=>campus_avatar_url($u['id'],$stamp),'bytes'=>strlen($encoded)]);
 }
 if($_SERVER['REQUEST_METHOD']!=='GET')reply(['error'=>'Method not allowed.'],405);
 $id=(string)($_GET['student']??$u['id']);demand(preg_match('/^[a-f0-9]{32}$/D',$id)===1,'Picture unavailable.');
 $allowed=$id===$u['id']||campus_staff($u)||query('SELECT 1 FROM campus_enrollments a JOIN campus_enrollments b ON a.course_id=b.course_id WHERE a.user_id=? AND b.user_id=? LIMIT 1',[$id,$u['id']])->fetchColumn();
 if(!$allowed)reply(['error'=>'Picture unavailable.'],404);$row=query('SELECT image_data FROM campus_avatars WHERE user_id=?',[$id])->fetch();if(!$row)reply(['error'=>'Picture unavailable.'],404);
 header('Content-Type: image/jpeg');header("Content-Security-Policy: default-src 'none'; sandbox");header('Content-Disposition: inline; filename="profile.jpg"');header('Cache-Control: private, no-store');header('X-Content-Type-Options: nosniff');header('Cross-Origin-Resource-Policy: same-origin');echo $row['image_data'];
}catch(InvalidArgumentException $e){reply(['error'=>$e->getMessage()],400);}catch(Throwable $e){if(db()->inTransaction())db()->rollBack();error_log('Avatar service unavailable: '.get_class($e));reply(['error'=>'Profile picture service is temporarily unavailable.'],503);}
