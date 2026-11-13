<?php
declare(strict_types=1);
function text_length(string $value): int {
    // PCRE is bundled with PHP; an optional mbstring module is not required.
    $length=preg_match_all('/./us',$value);
    if($length===false)throw new InvalidArgumentException('Use valid text.');
    return $length;
}
