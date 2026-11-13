<?php
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
require __DIR__.'/bootstrap.php';
query('DELETE FROM auth_tokens WHERE expires_at < ?',[time()]);
query('DELETE FROM rate_limits WHERE window_start < ?',[time()-86400]);
echo "Expired tokens and rate limits cleared.\n";
