<?php
if(PHP_SAPI!=='cli')exit;
require __DIR__.'/../private/bootstrap.php';require __DIR__.'/../private/avatar-processor.php';
function check($v){if(!$v)throw new Exception('Avatar processor assertion failed.');}
$im=imagecreatetruecolor(256,256);imagefill($im,0,0,imagecolorallocate($im,40,90,60));ob_start();imagejpeg($im,null,78);$raw=ob_get_clean();
foreach([0xe0,0xe1,0xe2,0xef,0xfe] as $marker){$payload='PRIVATE GPS metadata';$tag="\xff".chr($marker).pack('n',strlen($payload)+2).$payload;$input=substr($raw,0,2).$tag.substr($raw,2);$out=campus_avatar_encode($input,getimagesizefromstring($input),true);check(!str_contains($out,$payload));$info=getimagesizefromstring($out);check($info[0]===256&&$info[1]===256&&$info[2]===IMAGETYPE_JPEG);check(imagecreatefromstring($out)!==false);}
foreach([$raw.'trailing payload',substr($raw,0,-2),"\xff\xd8\xff\xe1\xff\xffbad",str_repeat('x',80001)] as $bad){try{campus_compact_jpeg($bad);throw new Exception('Malformed image accepted.');}catch(InvalidArgumentException $expected){}}
echo "PASS: compact JPEG decoding after metadata stripping, all APP/comment segments removed, malformed/trailing/oversize payloads rejected.\n";
