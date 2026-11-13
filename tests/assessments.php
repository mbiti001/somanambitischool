<?php
require dirname(__DIR__).'/private/bootstrap.php';require dirname(__DIR__).'/private/assessments.php';
function must($value,$message){if(!$value)throw new RuntimeException($message);}
function correct_responses(array $paper):array{$out=[];foreach($paper['questions'] as $q)$out[$q['id']]=['value'=>$q['type']==='statements'?array_column($q['statements'],'correct'):$q['correct'],'timedOut'=>false];return $out;}
foreach(['college-0','college-1','college-2','college-3','module-0','module-1','module-2','module-3','final'] as $key){
 $paper=assessment_paper($key);$responses=correct_responses($paper);$r=assessment_grade($paper,$responses);must($r['passed']&&$r['percent']===100.0,'Correct responses not 100%');
 foreach($paper['questions'] as $q){$public=json_encode(assessment_question_public($q));foreach(['correct','explanation','sources','lesson_refs'] as $field)must(!str_contains($public,'"'.$field.'":'),'Assessment key leaked');}
 $first=$paper['questions'][0];if($first['type']==='tf'){$responses[$first['id']]['value']=!$first['correct'];$r=assessment_grade($paper,$responses);must(!$r['passed']&&$r['score']===9,'Module accepted <100%');}
}
$paper=assessment_paper('final');must(assessment_maximum($paper)===55,'Final maximum');$answers=correct_responses($paper);
for($i=0;$i<8;$i++){$q=$paper['questions'][$i];$answers[$q['id']]['value']=($q['correct']+1)%4;}$r=assessment_grade($paper,$answers);must($r['score']===47&&$r['passed'],'47/55 should pass');$q=$paper['questions'][8];$answers[$q['id']]['value']=($q['correct']+1)%4;$r=assessment_grade($paper,$answers);must($r['score']===46&&!$r['passed'],'46/55 must fail');
$answers=correct_responses($paper);$q=$paper['questions'][15];$answers[$q['id']]['value'][0]=!$q['statements'][0]['correct'];$r=assessment_grade($paper,$answers);must($r['score']===54&&$r['questions'][15]['earned']===3,'Statement marks are not independent');
$q=assessment_paper('module-0')['questions'][0];must(assessment_answer_valid(false,$q)&&!assessment_answer_valid(0,$q),'Boolean validation');must(!assessment_answer_valid([true,false],$paper['questions'][15]),'Incomplete statement set');
$state=fresh_state();must(!assessment_eligible(assessment_paper('module-0'),$state),'Fresh module eligible');$state['completed']=array_column(course_catalog()['lessons'],'id');must(assessment_eligible(assessment_paper('module-0'),$state),'Module reading eligibility');must(!mastery_available(2,$state),'Later module bypassed checkpoint');must(!assessment_eligible($paper,$state),'Final bypassed module exams');$state['modulePasses']=[0,1,2,3];must(assessment_eligible($paper,$state),'Final not eligible after modules');
echo "PASS: all papers, private payloads, 100% module threshold, 47/55 final threshold, independent statement marks, strict answers and course gates.\n";
