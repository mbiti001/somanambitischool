<?php
declare(strict_types=1);
function certificate_signatories():array{
 $path=getenv('HISA_CERTIFICATE_CONFIG_PATH')?:__DIR__.'/certificate-config.php';$config=is_file($path)?require $path:[];
 $raw=is_array($config)?($config['signatories']??null):null;$raw??=[['name'=>'Edwin Mbiti','title'=>'Director & CEO','organisation'=>'Soma na Mbiti']];
 if(!is_array($raw)||count($raw)<1||count($raw)>4)throw new RuntimeException('Certificate signatories are not configured.');$out=[];
 foreach($raw as $signer){foreach(['name','title','organisation'] as $key)if(!is_string($signer[$key]??null)||trim($signer[$key])===''||text_length($signer[$key])>100||preg_match('/[\x00-\x1F\x7F]/u',$signer[$key]))throw new RuntimeException('Certificate signatory is invalid.');$out[]=['name'=>trim($signer['name']),'title'=>trim($signer['title']),'organisation'=>trim($signer['organisation'])];}
 return $out;
}
function certificate_issue(array $paper,array $attempt,array $result):?array{
 if($paper['kind']!=='final'||!$result['passed'])return null;
 $existing=query('SELECT * FROM course_certificates WHERE user_id=?',[$attempt['user_id']])->fetch();if($existing)return $existing;
 $user=query('SELECT id,email,display_name,verified_at FROM users WHERE id=?',[$attempt['user_id']])->fetch();if(!$user||$user['verified_at']===null)throw new RuntimeException('Certificate account must be verified.');
 $student=student_record($user);$name=$paper['candidate_name']??($student['full_name']?:$user['display_name']);$name=preg_replace('/\s+/u',' ',trim($name));if($name===''||text_length($name)>120)throw new RuntimeException('Check the certificate name.');
 $state=assessment_state($user['id']);if(count(mastery_module_passes($state['modulePasses']??[]))!==4||!assessment_eligible($paper,$state))throw new RuntimeException('Course completion requirements are missing.');
 $id=bin2hex(random_bytes(16));$issued=gmdate('c');$year=(new DateTimeImmutable($issued))->setTimezone(new DateTimeZone('Africa/Nairobi'))->format('Y');$number='SMB-'.$year.'-C'.sprintf('%06d',(int)$student['student_sequence']);
 query('INSERT INTO course_certificates (id,user_id,certificate_number,student_number,student_name,course_title,attempt_id,score,maximum,signatories_json,issued_at) VALUES (?,?,?,?,?,?,?,?,?,?,?)',[$id,$user['id'],$number,student_number((int)$student['student_sequence']),$name,'Investing in Kenyan Shares',$attempt['id'],$result['score'],$result['maximum'],json_encode(certificate_signatories(),JSON_THROW_ON_ERROR),$issued]);
 return query('SELECT * FROM course_certificates WHERE id=?',[$id])->fetch();
}
function certificate_public(array $row):array{
 return ['id'=>$row['id'],'number'=>$row['certificate_number'],'studentNumber'=>$row['student_number'],'name'=>$row['student_name'],'course'=>$row['course_title'],'score'=>(int)$row['score'],'maximum'=>(int)$row['maximum'],'percent'=>round((int)$row['score']/(int)$row['maximum']*100,2),'signatories'=>json_decode($row['signatories_json'],true,16,JSON_THROW_ON_ERROR),'issuedAt'=>$row['issued_at'],'download'=>'certificate.php?id='.rawurlencode($row['id'])];
}
