<?php
declare(strict_types=1);
/** Strip all JPEG application/comment metadata without needing a host GD extension.
 * The browser has already decoded/resized the original. This path accepts only
 * bounded 256px JPEGs and rejects trailing bytes, unknown markers and bad framing.
 */
function campus_compact_jpeg(string $raw):string{
 demand(strlen($raw)<=80000&&str_starts_with($raw,"\xff\xd8"),'Use the profile form to prepare a compact JPEG.');$info=@getimagesizefromstring($raw);demand($info&&$info[2]===IMAGETYPE_JPEG&&$info[0]===256&&$info[1]===256,'Use the profile form to resize the picture to 256 pixels.');
 $length=strlen($raw);$p=2;$out="\xff\xd8";$frames=0;$scans=0;$markers=0;
 while($p<$length){demand(++$markers<=1024&&ord($raw[$p])===255,'Invalid JPEG framing.');while($p<$length&&ord($raw[$p])===255)$p++;demand($p<$length,'Incomplete JPEG.');$m=ord($raw[$p++]);
  if($m===0xd9){demand($p===$length&&$frames===1&&$scans>0,'Invalid JPEG end marker.');return $out."\xff\xd9";}
  demand(in_array($m,[0xc0,0xc1,0xc2,0xc4,0xdb,0xdd,0xda,0xfe],true)||($m>=0xe0&&$m<=0xef),'Unsupported JPEG marker.');
  demand($p+2<=$length,'Incomplete JPEG segment.');$n=unpack('n',substr($raw,$p,2))[1];demand($n>=2&&$p+$n<=$length,'Invalid JPEG segment length.');
  if(in_array($m,[0xc0,0xc1,0xc2],true))$frames++;
  if(!($m===0xfe||($m>=0xe0&&$m<=0xef)))$out.="\xff".chr($m).substr($raw,$p,$n);$p+=$n;
  if($m===0xda){$scans++;$start=$p;
   while($p<$length){if(ord($raw[$p])!==255){$p++;continue;}$marker=$p;while($p<$length&&ord($raw[$p])===255)$p++;demand($p<$length,'Incomplete JPEG scan.');$next=ord($raw[$p]);if($next===0||($next>=0xd0&&$next<=0xd7)){$p++;continue;}$p=$marker;break;}
   $out.=substr($raw,$start,$p-$start);
  }
 }
 throw new InvalidArgumentException('Incomplete JPEG image.');
}
function campus_avatar_encode(string $raw,array $info,bool $portable=false):string{
 if($portable||!function_exists('imagecreatefromstring'))return campus_compact_jpeg($raw);
 $image=@imagecreatefromstring($raw);demand($image!==false,'The picture could not be read.');$thumb=imagecreatetruecolor(256,256);imagefill($thumb,0,0,imagecolorallocate($thumb,248,241,225));$side=min($info[0],$info[1]);imagecopyresampled($thumb,$image,0,0,(int)(($info[0]-$side)/2),(int)(($info[1]-$side)/2),256,256,$side,$side);ob_start();imagejpeg($thumb,null,78);$encoded=ob_get_clean();imagedestroy($thumb);imagedestroy($image);demand(strlen($encoded)<=80000,'Choose a simpler profile picture.');return $encoded;
}
