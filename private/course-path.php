<?php
declare(strict_types=1);
function course_path(): string {
    $path=__DIR__.'/legacy-investing-course.json';
    if(is_file($path))return $path;
    throw new RuntimeException('Private lesson catalogue missing.');
}
