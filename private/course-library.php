<?php
declare(strict_types=1);
require_once __DIR__.'/material-access.php';
function course_library_allowed(array $u,string $course):bool{
 if($u['verified_at']===null)return false;if(campus_staff($u))return true;if($course==='college')return material_access($u,'college');if(!terms_ready())return false;
 return (bool)query("SELECT 1 FROM term_enrollments e JOIN term_offerings o ON o.id=e.offering_id WHERE e.user_id=? AND o.course_id=? AND e.status IN ('enrolled','finished','completed') LIMIT 1",[$u['id'],$course])->fetchColumn()||(bool)query('SELECT 1 FROM term_professors p JOIN term_offerings o ON o.id=p.offering_id WHERE p.user_id=? AND o.course_id=? LIMIT 1',[$u['id'],$course])->fetchColumn();
}
function course_library(array $u,?string $selected):array{
 $all=['college'=>['id'=>'college','title'=>college_course()['title']]];foreach(term_courses() as $id=>$c)$all[$id]=['id'=>$id,'title'=>$c['title']];$available=[];foreach($all as $id=>$c)if(course_library_allowed($u,$id))$available[]=$c;
 if($selected===null||$selected==='')return ['courses'=>$available,'selected'=>null];demand(isset($all[$selected]),'Choose a course library.');if(!course_library_allowed($u,$selected))reply(['error'=>'Enroll in this course to open its library.'],403);
 $c=$selected==='college'?college_course():term_course($selected);$modules=$selected==='college'?$c['modules']:$c['weeks'];$papers=[];$path=__DIR__.'/study-papers.json';if(is_file($path))foreach(json_decode(file_get_contents($path),true,128,JSON_THROW_ON_ERROR)['papers']??[] as $p)if($p['course']===$selected)$papers[]=$p;
 return ['courses'=>$available,'selected'=>$selected,'title'=>$all[$selected]['title'],'sources'=>$c['sources'],'modules'=>array_map(fn($m)=>['title'=>$m['title'],'readings'=>$m['readings']??[]],$modules),'papers'=>$papers];
}
