<?php
require dirname(__DIR__).'/private/session-policy.php';
function expect_session($value,$why){if(!$value)throw new RuntimeException($why);}
$now=100000;$s=['user_id'=>'qa','authenticated_at'=>$now,'last_seen'=>$now];school_session_initialise($s,$now);$initial=$s;
expect_session(school_session_reason($s,$now+3599)===null,'Expired before one hour');expect_session(school_session_reason($s,$now+3600)==='idle','One-hour boundary not enforced');
for($i=1;$i<100;$i++)school_session_metadata($s,$now+$i*20);expect_session($s===$initial,'Passive metadata reads extended the clock');
expect_session(school_session_touch($s,$now+3599),'Active interaction not accepted');expect_session(school_session_reason($s,$now+7198)===null,'Activity did not renew');expect_session(school_session_reason($s,$now+7199)==='idle','Renewed boundary wrong');expect_session(!school_session_touch($s,$now+7199),'Expired session revived');
$s['user_activity_at']=$now+28799;expect_session(school_session_reason($s,$now+28800)==='absolute','Absolute cap bypassed');
$a=$initial;$b=$initial;school_session_touch($a,$now+1000);expect_session($b['user_activity_at']===$now,'Other session activity was changed');
expect_session(school_session_reason([],999999)===null&&school_session_metadata([],999999)===null,'Anonymous user received authenticated session');
echo "PASS: exact one-hour idle boundary, no passive renewal, explicit activity renewal, no resurrection, eight-hour absolute cap and independent browser sessions.\n";
