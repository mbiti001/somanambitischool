<?php
require __DIR__.'/../private/bootstrap.php';
function check_text($ok){if(!$ok)throw new RuntimeException('Text validation regression');}
check_text(text_length("Mbiti\n")===6);
check_text(text_length('Hisa 📖')===6);
check_text(text_length(str_repeat('é',80))===80);
check_text(password_input(str_repeat('é',12))===str_repeat('é',12));
foreach(['short',str_repeat('a',73),"\xff"] as $invalid){
    try{password_input($invalid);throw new RuntimeException('Invalid text accepted');}
    catch(InvalidArgumentException $expected){}
}
check_text(text_length(journal_text(str_repeat('é',2000),'Reflection'))===2000);
try{journal_text(str_repeat('é',2001),'Reflection');throw new RuntimeException('Oversized note accepted');}
catch(InvalidArgumentException $expected){}
echo "PASS: Unicode characters, line breaks, invalid UTF-8, password byte limits and journal boundaries without mbstring.\n";
