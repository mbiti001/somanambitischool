<?php
declare(strict_types=1);
function course_path(): string {
    $root=dirname(__DIR__);
    foreach(['public_html','public'] as $directory){
        $path=$root.'/'.$directory.'/course.json';
        if(is_file($path))return $path;
    }
    throw new RuntimeException('Lesson catalogue missing.');
}
