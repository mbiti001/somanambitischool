<?php
// The fixed week-12 sitting window is superseded. Check self-paced completion
// recovery plus the unchanged two-attempt/five-attempt seven-day policies in memory.
require __DIR__.'/term_reconcile.php';require_once dirname(__DIR__).'/private/assessments.php';
query('ALTER TABLE assessment_attempts ADD COLUMN started_at TEXT');query('UPDATE assessment_attempts SET started_at=submitted_at WHERE started_at IS NULL');$base=strtotime('2026-11-16T12:00:00+03:00');
foreach([['f',2],['w01',5]] as [$part,$limit]){
 $key=term_assessment_key('deadbeef',$part);$user='retake-'.$part;
 for($i=0;$i<$limit;$i++)query('INSERT INTO assessment_attempts (id,user_id,assessment_key,passed,started_at,submitted_at,score,maximum) VALUES (?,?,?,?,?,?,?,?)',[$user.'-'.$i,$user,$key,0,gmdate('c',$base+$i*4000),gmdate('c',$base+$i*4000+3000),0,$part==='f'?55:10]);
 $ended=$base+($limit-1)*4000+3000;$policy=assessment_retake_policy($key,$user,$ended+1);ensure_reconciliation($policy['limit']===$limit&&!$policy['canStart']&&$policy['availableAt']===$ended+7*86400,'The failed-attempt limit must open a seven-day study break.');ensure_reconciliation(!assessment_retake_policy($key,$user,$ended+7*86400-1)['canStart'],'Retakes must not open early.');ensure_reconciliation(assessment_retake_policy($key,$user,$ended+7*86400)['canStart'],'Retakes must reopen after the seven-day study break.');
}
ensure_reconciliation(assessment_retake_policy('t-deadbeef-f','learner',$base)['alreadyPassed'],'An existing final pass is preserved without a forced retake.');
echo "PASS: self-paced finals retain two failed attempts, module tests retain five, and both enforce exact seven-day study breaks.\n";
