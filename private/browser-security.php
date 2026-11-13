<?php
declare(strict_types=1);
// The parent classroom runs only school scripts. External publishers run inside
// their own HTTPS frames; certificates and avatars replace this policy locally.
const SCHOOL_BROWSER_CSP="default-src 'self'; script-src 'self'; style-src 'self' 'unsafe-inline'; img-src 'self' data: blob:; font-src 'self'; connect-src 'self'; frame-src https:; media-src 'self' blob:; object-src 'none'; base-uri 'none'; form-action 'self'; frame-ancestors 'none'";
function school_browser_security():void{
 if(PHP_SAPI==='cli'||headers_sent())return;
 header('Content-Security-Policy: '.SCHOOL_BROWSER_CSP);
 header('Permissions-Policy: camera=(), geolocation=(), payment=(), microphone=(self)');
 header('X-Frame-Options: DENY');
 header('X-Content-Type-Options: nosniff');
 header('Referrer-Policy: no-referrer');
 header_remove('X-Powered-By');
}
school_browser_security();
