<?php
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
require __DIR__.'/bootstrap.php';
$schema=file_get_contents(dirname(__DIR__).'/database/schema.sql');
if(db()->getAttribute(PDO::ATTR_DRIVER_NAME)==='sqlite'){
    // SQLite is used only for disposable local development databases.
    $schema=str_replace(' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci','',$schema);
}
foreach(explode(';',$schema) as $statement)if(trim($statement)!=='')db()->exec($statement);
echo "Database schema installed.\n";
