<?php
declare(strict_types=1);
const SCHOOL_IDLE_SECONDS=3600;
const SCHOOL_ABSOLUTE_SECONDS=28800;
function school_session_reason(array $s,int $now):?string{
 if(empty($s['user_id']))return null;
 if(isset($s['authenticated_at'])&&$now-(int)$s['authenticated_at']>=SCHOOL_ABSOLUTE_SECONDS)return 'absolute';
 $last=(int)($s['user_activity_at']??$s['last_seen']??$s['authenticated_at']??$now);return $now-$last>=SCHOOL_IDLE_SECONDS?'idle':null;
}
function school_session_initialise(array &$s,int $now):void{
 if(empty($s['user_id']))return;
 $s['authenticated_at']??=$now;$s['user_activity_at']??=(int)($s['last_seen']??$s['authenticated_at']);$s['runtime_id']??=bin2hex(random_bytes(16));
}
function school_session_touch(array &$s,int $now):bool{
 if(empty($s['user_id'])||school_session_reason($s,$now)!==null)return false;
 school_session_initialise($s,$now);$s['user_activity_at']=$now;return true;
}
function school_session_metadata(array $s,int $now):?array{
 if(empty($s['user_id'])||school_session_reason($s,$now)!==null)return null;
 $last=(int)($s['user_activity_at']??$s['last_seen']??$now);$absolute=(int)($s['authenticated_at']??$now)+SCHOOL_ABSOLUTE_SECONDS;
 return ['id'=>$s['runtime_id']??'','serverNow'=>$now,'idleSeconds'=>SCHOOL_IDLE_SECONDS,'idleDeadline'=>$last+SCHOOL_IDLE_SECONDS,'absoluteDeadline'=>$absolute,'expiresAt'=>min($last+SCHOOL_IDLE_SECONDS,$absolute)];
}
