<?php
declare(strict_types=1);
function campus_staff(?array $u):bool{
 if(!$u||$u['verified_at']===null)return false;
 $path=getenv('HISA_CAMPUS_CONFIG_PATH')?:__DIR__.'/campus-config.php';if(!is_file($path))$path=getenv('HISA_STUDENT_CONFIG_PATH')?:__DIR__.'/student-config.php';$c=is_file($path)?require $path:[];if(!is_array($c))return false;
 return in_array(strtolower($u['email']),array_map('strtolower',array_filter($c['super_admin_emails']??[],'is_string')),true);
}
function campus_controls(string $id):array{
 try{$r=query('SELECT * FROM campus_controls WHERE user_id=?',[$id])->fetch();}
 catch(PDOException $e){if(in_array((string)$e->getCode(),['42S02','HY000'],true)&&preg_match('/no such table|doesn.t exist/i',$e->getMessage()))return ['suspended'=>false,'read_only'=>false,'muted'=>false,'version'=>0];throw $e;}
 return $r?:['suspended'=>false,'read_only'=>false,'muted'=>false,'version'=>0];
}
function campus_guard(array $u,bool $writing=true):void{
 if(campus_staff($u))return;$c=campus_controls($u['id']);
 if($c['suspended'])reply(['error'=>'This account is suspended. Contact student@somanambiti.com for a review.','restricted'=>true],403);
 if($writing&&$c['read_only']&&($_SERVER['REQUEST_METHOD']??'GET')!=='GET')reply(['error'=>'This account currently has read-only access. Contact the student office to request a review.','restricted'=>true],403);
}
