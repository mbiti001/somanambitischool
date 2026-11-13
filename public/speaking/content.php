<?php
declare(strict_types=1);
require dirname(__DIR__,2).'/private/bootstrap.php';require_once dirname(__DIR__,2).'/private/material-access.php';
try{session_begin();header('Cache-Control: private, no-store');header('Vary: Cookie');$u=account_user();material_require($u,'speaking');header('Content-Type: application/javascript; charset=utf-8');header('X-Content-Type-Options: nosniff');echo 'const speakingCourse='.json_encode(legacy_speaking_catalogue()['modules'],JSON_UNESCAPED_UNICODE|JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT|JSON_THROW_ON_ERROR).';';}catch(Throwable $e){error_log('Speaking materials unavailable: '.get_class($e));http_response_code(503);header('Content-Type: application/javascript; charset=utf-8');echo "location.replace('../#account');";}
