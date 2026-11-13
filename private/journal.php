<?php
declare(strict_types=1);
require_once __DIR__.'/text.php';
function normalize_learning_state(array $state): array {
    $state['journal']=$state['journal']??[];$state['modulePasses']=mastery_module_passes($state['modulePasses']??[]);$state['unitPasses']=(object)mastery_passes($state['unitPasses']??[]);
    foreach($state['history'] as $i=>&$trade)if(!isset($trade['id']))$trade['id']='legacy-'.$i;
    unset($trade);return $state;
}
function journal_text($value,string $label,bool $required=false): string {
    demand(is_string($value),$label.' must be text.');$value=trim($value);
    demand(text_length($value)<=2000,$label.' must be at most 2,000 characters.');
    demand(!$required||$value!=='','Explain your reason for the trade.');return $value;
}
function journal_action(array $state,array $action):array {
    $state=normalize_learning_state($state);$entries=$state['journal'];$id=$action['entryId']??null;$index=null;
    if($id!==null){demand(is_string($id),'Choose a valid entry.');foreach($entries as $i=>$e)if($e['id']===$id)$index=$i;demand($index!==null,'This journal entry was not found in your account.');}
    if($action['action']==='journal_delete'){demand($index!==null,'Choose an entry to delete.');array_splice($entries,$index,1);$state['journal']=$entries;return $state;}
    $reason=journal_text($action['reason']??'','Reason',true);$risk=journal_text($action['risk']??'','Risk');$outcome=journal_text($action['outcome']??'','Outcome');$learned=journal_text($action['learned']??'','Lesson');$reviewed=$action['reviewed']??false;
    demand(is_bool($reviewed),'Choose a valid review status.');demand(!$reviewed||$learned!=='','Add what you learned before marking this entry reviewed.');
    $now=gmdate('c');
    if($index!==null){$entry=$entries[$index];}
    else{
        demand(count($entries)<200,'Your journal has reached 200 entries. Export a copy before removing older reflections.');
        $tradeId=$action['tradeId']??null;demand(is_string($tradeId),'Choose a practice trade.');$trade=null;
        foreach($entries as $e)demand($e['trade']['id']!==$tradeId,'This trade already has a journal entry. Edit its existing reflection.');
        foreach($state['history'] as $t)if($t['id']===$tradeId)$trade=$t;
        demand($trade!==null,'Choose a trade from your own practice history.');
        $entry=['id'=>bin2hex(random_bytes(16)),'trade'=>$trade,'createdAt'=>$now];
    }
    $entry=array_merge($entry,['reason'=>$reason,'risk'=>$risk,'outcome'=>$outcome,'learned'=>$learned,'reviewed'=>$reviewed,'updatedAt'=>$now]);
    if($index!==null)$entries[$index]=$entry;else $entries[]=$entry;
    $state['journal']=$entries;return $state;
}
