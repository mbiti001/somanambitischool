<?php
declare(strict_types=1);
require_once __DIR__.'/journal.php';
require_once __DIR__.'/course-path.php';
require_once __DIR__.'/mastery.php';
function course_catalog(): array { static $course; return $course ??= json_decode(file_get_contents(course_path()),true,512,JSON_THROW_ON_ERROR); }
const PRICES = ['SCOM'=>25, 'EQTY'=>50, 'KCB'=>40, 'EABL'=>180];
function fresh_state(): array { return ['cash'=>100000,'holdings'=>(object)[],'history'=>[],'completed'=>[],'journal'=>[],'unitPasses'=>(object)[],'modulePasses'=>[]]; }
function demand(bool $ok, string $message): void { if (!$ok) throw new InvalidArgumentException($message); }
function whole($value): bool { return is_int($value) && $value >= 0 && $value <= 100000; }
function import_progress($v): array {
    demand(is_array($v) && whole($v['cash'] ?? null), 'Invalid browser practice balance.');
    $holdings = $v['holdings'] ?? null;
    demand(is_array($holdings), 'Invalid browser holdings.');
    $clean = [];
    foreach ($holdings as $symbol=>$q) {
        demand(isset(PRICES[$symbol]) && whole($q) && $q * PRICES[$symbol] <= 100000, 'Invalid browser holdings.');
        if ($q > 0) $clean[$symbol] = $q;
    }
    $completed = $v['completed'] ?? null;
    demand(is_array($completed) && count($completed)<=100, 'Invalid lesson progress.');
    foreach ($completed as $i) demand(is_int($i) && $i>=0 && $i<count(course_catalog()['lessons']), 'Invalid lesson progress.');
    $history = $v['history'] ?? null;
    demand(is_array($history) && count($history)<=2000, 'The browser history is too large to import.');
    $cash=100000; $ledger=[]; $safeHistory=[];
    foreach ($history as $h) {
        demand(is_array($h), 'Invalid browser trade history.');
        $s=$h['s']??''; $q=$h['q']??null; $side=$h['side']??''; $date=$h['date']??null;
        demand(is_string($s)&&isset(PRICES[$s])&&whole($q)&&$q>0&&in_array($side,['buy','sell'],true)&&($h['total']??null)===$q*PRICES[$s]&&is_string($date)&&strlen($date)<=40&&strtotime($date)!==false, 'Invalid browser trade history.');
        $delta=$side==='buy'?$q:-$q; $cash-=$delta*PRICES[$s]; $ledger[$s]=($ledger[$s]??0)+$delta;
        demand($cash>=0&&$ledger[$s]>=0, 'Invalid browser trade history.');
        $tradeId=$h['id']??('legacy-'.count($safeHistory));demand(is_string($tradeId)&&preg_match('/^(?:[a-f0-9]{32}|legacy-[0-9]{1,5})$/D',$tradeId)===1,'Invalid trade identifier.');
        demand(!in_array($tradeId,array_column($safeHistory,'id'),true),'Duplicate trade identifier.');
        $safeHistory[]=['id'=>$tradeId,'side'=>$side,'q'=>$q,'s'=>$s,'total'=>$q*PRICES[$s],'date'=>gmdate('Y-m-d\TH:i:s\Z',strtotime($date))];
    }
    demand($cash===$v['cash'], 'Browser balance and history do not match.');
    foreach(PRICES as $s=>$p) demand(($ledger[$s]??0)===($clean[$s]??0), 'Browser holdings and history do not match.');
    $imported=['cash'=>$cash,'holdings'=>(object)$clean,'history'=>$safeHistory,'completed'=>array_values(array_unique($completed)),'journal'=>[]];
    $notes=$v['journal']??[];demand(is_array($notes)&&count($notes)<=200,'Invalid journal data.');
    foreach($notes as $note){
        demand(is_array($note)&&is_array($note['trade']??null),'Invalid journal entry.');$t=$note['trade'];$tid=$t['id']??null;
        demand(is_string($tid)&&preg_match('/^(?:[a-f0-9]{32}|legacy-[0-9]{1,5})$/D',$tid)===1,'Invalid journal trade.');
        $archived=!in_array($tid,array_column($imported['history'],'id'),true);
        if($archived){$s=$t['s']??'';$q=$t['q']??null;demand(is_string($s)&&isset(PRICES[$s])&&whole($q)&&$q>0&&in_array($t['side']??null,['buy','sell'],true)&&($t['total']??null)===$q*PRICES[$s]&&is_string($t['date']??null)&&strtotime($t['date'])!==false,'Invalid archived journal trade.');$imported['history'][]=['id'=>$tid,'s'=>$s,'q'=>$q,'side'=>$t['side'],'total'=>$q*PRICES[$s],'date'=>gmdate('c',strtotime($t['date']))];}
        $imported=journal_action($imported,['action'=>'journal_save','tradeId'=>$tid,'reason'=>$note['reason']??'','risk'=>$note['risk']??'','outcome'=>$note['outcome']??'','learned'=>$note['learned']??'','reviewed'=>$note['reviewed']??false]);if($archived)array_pop($imported['history']);
    }
    $imported['unitPasses']=(object)mastery_passes($v['unitPasses']??[]);$imported['history']=array_slice($safeHistory,-500);return $imported;
}
function change_progress(array $state, array $action): array {
    $state=normalize_learning_state($state);$kind=$action['action']??'';
    if($kind==='section')return mastery_action($state,$action);
    if(in_array($kind,['journal_save','journal_delete'],true))return journal_action($state,$action);
    if ($kind==='lesson') {
        $i=$action['lesson']??null;
        demand(is_int($i)&&$i>=0&&$i<count(course_catalog()['lessons']), 'Choose a valid lesson.');
        $lesson=course_catalog()['lessons'][$i];
        demand(!isset($lesson['units']),'This chapter now uses section checkpoints. Refresh the page and work through each section.');
        if(isset($lesson['questions'])){
            $answers=$action['answers']??null;
            demand(is_array($answers)&&array_keys($answers)===range(0,count($lesson['questions'])-1),'This class now has more questions. Refresh the page and answer every question.');
            foreach($lesson['questions'] as $index=>$question)demand(is_int($answers[$index])&&$answers[$index]===$question['correct'],'Answer every question correctly to complete this class. Review the explanations and try again.');
        }else demand(($action['answer']??null)===$lesson['correct'], 'That answer is not correct. Try again.');
        if(!in_array($i,$state['completed'],true))$state['completed'][]=$i;
    } elseif ($kind==='trade') {
        $s=$action['symbol']??''; $q=$action['q']??null; $side=$action['side']??'';
        demand(is_string($s)&&isset(PRICES[$s])&&in_array($side,['buy','sell'],true), 'Choose a valid stock and order side.');
        demand(whole($q)&&$q>0, 'Enter a positive whole number of shares.');
        $holdings=(array)$state['holdings']; $total=$q*PRICES[$s];
        demand($side!=='buy'||$total<=$state['cash'], 'Not enough virtual cash. Try fewer shares.');
        demand($side!=='sell'||$q<=($holdings[$s]??0), 'You can only sell shares held in your practice portfolio.');
        $state['cash']+=$side==='buy'?-$total:$total;
        $holdings[$s]=($holdings[$s]??0)+($side==='buy'?$q:-$q);
        if($holdings[$s]===0)unset($holdings[$s]);
        $state['holdings']=(object)$holdings;
        $state['history'][]=['side'=>$side,'q'=>$q,'s'=>$s,'total'=>$total,'date'=>gmdate('Y-m-d\TH:i:s\Z'),'id'=>bin2hex(random_bytes(16))];
        $state['history']=array_slice($state['history'],-500);
    } elseif($kind==='reset') {
        $state=array_merge(fresh_state(),['completed'=>$state['completed'],'journal'=>$state['journal'],'unitPasses'=>$state['unitPasses']??(object)[],'modulePasses'=>$state['modulePasses']??[]]);
    } else throw new InvalidArgumentException('Unknown practice action.');
    return $state;
}
