<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
require __DIR__.'/bootstrap.php';require_once __DIR__.'/terms.php';
try{$result=terms_run_due(time(),100,true);if(!($result['installed']??false))exit(1);echo json_encode($result,JSON_THROW_ON_ERROR).PHP_EOL;}catch(Throwable $e){error_log('Course term scheduler unavailable: '.get_class($e));exit(1);}
