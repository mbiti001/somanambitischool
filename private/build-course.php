<?php
// Run after editing public/course.json. It does not change user records.
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
require_once __DIR__.'/course-path.php';
$path=course_path();
$course=json_decode(file_get_contents($path),true,512,JSON_THROW_ON_ERROR);
$checkSources=function(array $keys)use($course){
    if(!$keys)throw new RuntimeException('Teaching references are required.');
    foreach($keys as $key)if(!isset($course['sources'][$key]))throw new RuntimeException('Teaching source missing: '.$key);
};
foreach($course['lessons'] as $index=>$lesson){
    if(($lesson['id']??null)!==$index||!isset($lesson['answers'][$lesson['correct']])||!isset($course['videos'][$lesson['video']]))throw new RuntimeException('Invalid class catalogue. Keep IDs stable and append new classes.');
    $checkSources($lesson['sources']);
    $body=implode(' ',array_column($lesson['sections'],'text')).' '.$lesson['example']['text'];
    $words=count(preg_split('/\s+/u',trim($body)));
    if($words<1000||$words!==$lesson['wordCount']||$lesson['readingMinutes']!==(int)ceil($words/200))throw new RuntimeException('Class reading length or estimate is invalid.');
    if(count($lesson['questions'])<5)throw new RuntimeException('At least five questions are required.');
    foreach($lesson['questions'] as $question){
        if(!is_int($question['correct'])||!isset($question['answers'][$question['correct']])||empty($question['explanation']))throw new RuntimeException('Question or explanation missing.');
        $checkSources($question['sources']);
    }
    foreach($lesson['sections'] as $section)$checkSources($section['sources']);
    $checkSources($lesson['example']['sources']);$checkSources($lesson['taskSources']);
    if(count($lesson['units']??[])!==3)throw new RuntimeException('Each chapter needs three learning sections.');
    $covered=[];$questionCount=0;
    foreach($lesson['units'] as $unit){
        if(empty($unit['objective'])||empty($unit['activity'])||count($unit['questions'])<2)throw new RuntimeException('Learning guide is incomplete.');
        $covered=array_merge($covered,$unit['sections']);$questionCount+=count($unit['questions']);$checkSources($unit['sources']);$checkSources($unit['readings']);
        foreach($unit['questions'] as $question){if(!isset($question['answers'][$question['correct']])||empty($question['explanation']))throw new RuntimeException('Section question is incomplete.');$checkSources($question['sources']);}
    }
    sort($covered);if($covered!==range(0,count($lesson['sections'])-1)||$questionCount!==$lesson['questionCount'])throw new RuntimeException('Learning sections omit or duplicate teaching.');
}
foreach($course['sources'] as $source)foreach(['author','title','publisher','access','license','checked'] as $field)if(empty($source[$field]))throw new RuntimeException('Source credit missing: '.$field);
$js="// Generated from course.json. Keep lesson IDs stable when editing the curriculum.\nconst learningCourse = ".json_encode($course,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR).";\n";
file_put_contents(dirname($path).'/course.js',$js,LOCK_EX);
echo count($course['lessons'])." classes generated.\n";
