<?php
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
require __DIR__.'/bootstrap.php';require __DIR__.'/students.php';
$sql=file_get_contents(dirname(__DIR__).'/database/student-services.sql');
if(db()->getAttribute(PDO::ATTR_DRIVER_NAME)==='sqlite')$sql=str_replace(' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci','',$sql);
if(db()->getAttribute(PDO::ATTR_DRIVER_NAME)==='mysql'){$collation=query("SELECT COLLATION_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='users' AND COLUMN_NAME='id'")->fetchColumn();if(!is_string($collation)||!preg_match('/^utf8mb4_[a-zA-Z0-9_]+$/D',$collation))throw new RuntimeException('Check users.id character encoding before migration.');$sql=str_replace('utf8mb4_unicode_ci',$collation,$sql);}
foreach(explode(';',$sql) as $statement)if(trim($statement)!=='')db()->exec($statement);
$count=student_backfill();echo 'Student tables installed. Verified accounts assigned: '.$count."\n";
