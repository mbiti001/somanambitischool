<?php
declare(strict_types=1);
require dirname(__DIR__).'/private/bootstrap.php';require dirname(__DIR__).'/private/students.php';require dirname(__DIR__).'/private/certificates.php';require dirname(__DIR__).'/private/certificate-pdf.php';require_once dirname(__DIR__).'/private/terms.php';
header('Cache-Control: private, no-store');header('X-Content-Type-Options: nosniff');header('Referrer-Policy: no-referrer');
try{
 session_begin();$user=account_user();if(!$user||$user['verified_at']===null){header('Location: /#account',true,303);exit;}
 $preview=($_GET['preview']??'')==='1';
 if($preview){if(!student_admin($user))reply(['error'=>'Certificate not found.'],404);$record=['certificate_number'=>'SMB-SAMPLE','student_number'=>'SMB-EXAMPLE','student_name'=>'Example Learner','course_title'=>'Investing in Kenyan Shares','score'=>55,'maximum'=>55,'signatories_json'=>json_encode(certificate_signatories(),JSON_THROW_ON_ERROR),'issued_at'=>gmdate('c')];}
 else{$id=$_GET['id']??'';if(!is_string($id)||preg_match('/^[a-f0-9]{32}$/D',$id)!==1)reply(['error'=>'Certificate not found.'],404);$record=query('SELECT * FROM course_certificates WHERE id=?',[$id])->fetch();if(!$record&&terms_ready()){$record=query('SELECT * FROM term_certificates WHERE id=?',[$id])->fetch();if($record)$record['standard_course']=true;}if(!$record||($record['user_id']!==$user['id']&&!student_admin($user)))reply(['error'=>'Certificate not found.'],404);}
 if(($_GET['format']??'')==='html'){header('Content-Type: text/html; charset=utf-8');echo certificate_html($record,$preview);exit;}
 try{$bytes=certificate_pdf($record,$preview);}catch(CertificateTypefaceException $e){header('Content-Type: text/html; charset=utf-8');echo certificate_html($record,$preview);exit;}
 header('Content-Type: application/pdf');header('Content-Disposition: attachment; filename="'.$record['certificate_number'].'.pdf"');header('Content-Length: '.strlen($bytes));echo $bytes;
}catch(Throwable $e){error_log('Certificate download unavailable: '.get_class($e));reply(['error'=>'The certificate download is temporarily unavailable. Your issued certificate remains saved. Please try again.'],503);}
