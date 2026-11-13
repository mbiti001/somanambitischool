<?php
require __DIR__.'/../private/practice.php';
function verify_test($ok,$name){if(!$ok)throw new RuntimeException($name);echo "PASS: $name\n";}
function rejected($fn){try{$fn();return false;}catch(InvalidArgumentException $e){return true;}}
$s=fresh_state();$s=change_progress($s,['action'=>'trade','symbol'=>'SCOM','q'=>10,'side'=>'buy']);$t=$s['history'][0];
$s=change_progress($s,['action'=>'journal_save','tradeId'=>$t['id'],'reason'=>'Testing an idea','risk'=>'Could fall','outcome'=>'Still observing','learned'=>'','reviewed'=>false]);$e=$s['journal'][0];verify_test($e['trade']===$t&&$s['cash']===99750,'entry uses actual trade snapshot; balance unchanged');
verify_test(rejected(fn()=>change_progress($s,['action'=>'journal_save','tradeId'=>$t['id'],'reason'=>'Duplicate'])),'duplicate reflection rejected');
verify_test(rejected(fn()=>change_progress($s,['action'=>'journal_save','tradeId'=>'foreign','reason'=>'Not mine'])),'foreign trade rejected');
verify_test(rejected(fn()=>change_progress($s,['action'=>'journal_save','entryId'=>$e['id'],'reason'=>'','reviewed'=>false])),'blank reason rejected');
verify_test(rejected(fn()=>change_progress($s,['action'=>'journal_save','entryId'=>$e['id'],'reason'=>'Valid','reviewed'=>true])),'review requires lesson learned');
verify_test(rejected(fn()=>change_progress($s,['action'=>'journal_save','entryId'=>$e['id'],'reason'=>str_repeat('x',2001)])),'oversized text rejected');
$s=change_progress($s,['action'=>'reset']);verify_test(count($s['journal'])===1&&!$s['history']&&$s['cash']===100000,'reset preserves journal and original trade snapshot');
$s=change_progress($s,['action'=>'journal_save','entryId'=>$e['id'],'reason'=>'Testing an idea','learned'=>'Research first','reviewed'=>true]);verify_test($s['journal'][0]['reviewed'],'archived entry can be reviewed');
$import=import_progress(json_decode(json_encode($s),true));verify_test(count($import['journal'])===1&&!$import['history'],'guest journal imports after reset without creating portfolio trades');
$s=change_progress($s,['action'=>'journal_delete','entryId'=>$e['id']]);verify_test(!$s['journal']&&$s['cash']===100000,'delete leaves portfolio unchanged');
$legacy=fresh_state();unset($legacy['journal']);$legacy['history']=[['side'=>'buy','s'=>'SCOM','q'=>1,'total'=>25,'date'=>gmdate('c')]];verify_test(normalize_learning_state($legacy)['history'][0]['id']==='legacy-0','legacy accounts receive stable trade IDs');
