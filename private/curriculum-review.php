<?php
declare(strict_types=1);
require_once __DIR__.'/presence.php';require_once __DIR__.'/terms.php';require_once __DIR__.'/material-access.php';
function curriculum_review_guard(?array $u):void{if(!$u||$u['verified_at']===null)reply(['error'=>'Sign in to continue.'],401);if(!campus_staff($u))reply(['error'=>'This page is unavailable.'],403);}
function curriculum_course(string $id):array{
 if($id!=='college')return term_course($id);
 $c=college_course();return ['id'=>'college','code'=>$c['code'],'title'=>$c['title'],'summary'=>'College Success foundation','outcomes'=>[],'weeks'=>$c['modules'],'sources'=>$c['sources']];
}
function curriculum_review_ready():bool{try{query('SELECT id FROM curriculum_review_notes LIMIT 1');return true;}catch(PDOException $e){if(preg_match('/no such table|doesn.t exist/i',$e->getMessage()))return false;throw $e;}}
function curriculum_review_install():void{
 if(curriculum_review_ready())return;$sql=file_get_contents(dirname(__DIR__).'/database/course-review.sql');
 if(db()->getAttribute(PDO::ATTR_DRIVER_NAME)==='sqlite')$sql=str_replace(' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci','',$sql);
 else{$collation=query("SELECT COLLATION_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='users' AND COLUMN_NAME='id'")->fetchColumn();demand(is_string($collation)&&preg_match('/^utf8mb4_[a-zA-Z0-9_]+$/D',$collation),'Check the database encoding.');$sql=str_replace('utf8mb4_unicode_ci',$collation,$sql);}db()->exec($sql);
}
function curriculum_context(string $course,string $module):array{
 $c=curriculum_course($course);if($module==='final'){demand(isset($c['final']),'This course has no final paper.');$content=['title'=>'Final examination review','goal'=>'Review the complete paper and marking guidance without starting a learner attempt.','outcomes'=>[],'sections'=>[],'readings'=>[],'video'=>null,'questions'=>array_merge($c['final']['a'],$c['final']['b'])];$index=-1;}
 else{demand(ctype_digit($module)&&isset($c['weeks'][(int)$module]),'Choose a course module.');$index=(int)$module;$content=$c['weeks'][$index];}
 $keys=['overview','readings','video','practice','assignments'];foreach($content['sections'] as $i=>$s)$keys[]='guide-'.$i;foreach($content['questions']??[] as $q)$keys[]='question-'.$q['id'];
 return ['course'=>$c,'content'=>$content,'index'=>$index,'keys'=>$keys];
}
function curriculum_notes(string $user,string $course,int $module):array{
 if(!curriculum_review_ready())return [];
 $rows=query('SELECT n.page_key,n.note,n.version,n.created_at FROM curriculum_review_notes n WHERE n.reviewer_id=? AND n.course_id=? AND n.module_index=? AND NOT EXISTS (SELECT 1 FROM curriculum_review_notes x WHERE x.reviewer_id=n.reviewer_id AND x.course_id=n.course_id AND x.module_index=n.module_index AND x.page_key=n.page_key AND x.version>n.version)',[$user,$course,$module])->fetchAll();$out=[];foreach($rows as $r)$out[$r['page_key']]=['text'=>$r['note'],'version'=>(int)$r['version'],'savedAt'=>$r['created_at']];return $out;
}
function curriculum_review_payload(array $u,string $course,string $module):array{
 $context=curriculum_context($course,$module);$c=$context['course'];$catalogue=[['id'=>'college','title'=>college_course()['title']]];foreach(term_courses() as $id=>$subject)$catalogue[]=['id'=>$id,'title'=>$subject['title']];return ['catalogue'=>$catalogue,'course'=>['id'=>$course,'code'=>$c['code'],'title'=>$c['title'],'modules'=>array_map(fn($w)=>['title'=>$w['title']],$c['weeks']),'hasFinal'=>isset($c['final'])],'module'=>$module,'content'=>$context['content'],'sources'=>$c['sources'],'reviewReady'=>curriculum_review_ready(),'presenceReady'=>school_presence_ready(),'notes'=>curriculum_notes($u['id'],$course,$context['index'])];
}
function curriculum_save_note(array $u,array $b):array{
 $course=$b['course']??null;$module=$b['module']??null;$page=$b['page']??null;$note=$b['note']??null;$version=$b['version']??null;
 demand(is_string($course)&&is_string($module)&&is_string($page)&&is_string($note)&&is_int($version)&&$version>=0,'Refresh this review page.');$context=curriculum_context($course,$module);demand(in_array($page,$context['keys'],true),'Choose a page in this course.');demand(preg_match('//u',$note)===1&&text_length($note)<=12000&&!preg_match('/[\x00-\x08\x0b\x0c\x0e-\x1f\x7f]/',$note),'Use a plain-text suggestion of at most 12,000 characters.');curriculum_review_install();$now=gmdate('c');db()->beginTransaction();
 try{if(db()->getAttribute(PDO::ATTR_DRIVER_NAME)==='mysql')query('SELECT id FROM users WHERE id=? FOR UPDATE',[$u['id']])->fetch();else query('UPDATE users SET id=id WHERE id=?',[$u['id']]);$latest=(int)(query('SELECT MAX(version) FROM curriculum_review_notes WHERE reviewer_id=? AND course_id=? AND module_index=? AND page_key=?',[$u['id'],$course,$context['index'],$page])->fetchColumn()?:0);if($latest!==$version){db()->rollBack();reply(['error'=>'This suggestion changed in another view. Your text is kept here. Reload the review before replacing the saved version.','conflict'=>true],409);}query('INSERT INTO curriculum_review_notes (id,reviewer_id,course_id,module_index,page_key,note,version,created_at) VALUES (?,?,?,?,?,?,?,?)',[bin2hex(random_bytes(16)),$u['id'],$course,$context['index'],$page,$note,$latest+1,$now]);campus_audit($u,'curriculum_suggestion',$course,['module'=>$module,'page'=>$page,'revision'=>$latest+1]);db()->commit();return ['text'=>$note,'version'=>$latest+1,'savedAt'=>$now];}catch(Throwable $e){if(db()->inTransaction())db()->rollBack();throw $e;}
}
