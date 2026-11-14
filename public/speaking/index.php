<?php
declare(strict_types=1);
require dirname(__DIR__,2).'/private/bootstrap.php';require_once dirname(__DIR__,2).'/private/material-access.php';
try{session_begin();$u=account_user();header('Cache-Control: private, no-store');header('Vary: Cookie');if(!material_access($u,'speaking')){header('Location: ../#'.(!$u||$u['verified_at']===null?'account':'program/speaking12'),true,303);exit;}header('Content-Type: text/html; charset=utf-8');echo file_get_contents(dirname(__DIR__,2).'/private/legacy-speaking-view.html');}catch(Throwable $e){error_log('Speaking classroom unavailable: '.get_class($e));http_response_code(503);echo 'The classroom is temporarily unavailable. Please return to the school landing page.';}
