<?php
declare(strict_types=1);
require __DIR__.'/ProducerAttestation.php';
$nonce='admission-attest-'.str_repeat('a',32);
$row=['db'=>'portal_onepage_buyer_ci','actor'=>'onepage_fixture_evidence_producer','login'=>'onepage_fixture_evidence_producer',
 'host'=>'127.0.0.1','port'=>55439,'pid'=>123,'client_port'=>234,'started'=>'456','backend_start'=>'fixture-start','unsafe'=>false];
$seen=['pid'=>123,'usename'=>$row['actor'],'datname'=>$row['db'],'client_addr'=>'127.0.0.1','client_port'=>234,
 'backend_start'=>'fixture-start','application_name'=>$nonce];
$cluster=['db'=>$row['db'],'started'=>'456'];
AdmissionProducerAttestation::facts($row,$seen,$cluster,$nonce);$count=1;
foreach(['db'=>'other','actor'=>'onepage_buyer_runtime','login'=>'onepage_lab_bootstrap','host'=>'::1','port'=>5432,
 'pid'=>0,'client_port'=>0,'started'=>'457','backend_start'=>'','unsafe'=>true] as $k=>$v){
 try{AdmissionProducerAttestation::facts(array_replace($row,[$k=>$v]),$seen,$cluster,$nonce);throw new LogicException('NEGATIVE_ACCEPTED');}
 catch(RuntimeException){++$count;}
}
foreach(['pid'=>999,'usename'=>'onepage_buyer_runtime','datname'=>'other','client_addr'=>'192.0.2.1','client_port'=>999,
 'backend_start'=>'stale','application_name'=>'wrong'] as $k=>$v){
 try{AdmissionProducerAttestation::facts($row,array_replace($seen,[$k=>$v]),$cluster,$nonce);throw new LogicException('NEGATIVE_ACCEPTED');}
 catch(RuntimeException){++$count;}
}
echo 'PASS ADMISSION_ATTESTATION_FACTS '.$count."\n";
