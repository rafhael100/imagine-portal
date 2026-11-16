<?php
if(getenv('CI')!=='true'||getenv('ADMIN_PHASE4_PG_CI')!=='1')exit(1);
$root=getenv('PERFORMANCE_ADMIN_LAB_ENGINE_ROOT');$owner=new PDO(getenv('PHASE4_RECEIVER_OWNER_DSN')?:'pgsql:host=127.0.0.1;dbname=postgres',getenv('PHASE4_RECEIVER_OWNER_USER')?:'postgres',getenv('PHASE4_RECEIVER_OWNER_PASSWORD')?:getenv('DB_PASSWORD'),[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
$owner->exec('CREATE DATABASE performance_receiver_phase4_ci');
$db=new PDO(getenv('PHASE4_RECEIVER_ADMIN_DSN')?:'pgsql:host=127.0.0.1;dbname=performance_receiver_phase4_ci',getenv('PHASE4_RECEIVER_OWNER_USER')?:'postgres',getenv('PHASE4_RECEIVER_OWNER_PASSWORD')?:getenv('DB_PASSWORD'),[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
foreach(['002-service-inbox.sql','003-service-inbox-native-rls.sql','004-private-service-delegations.sql'] as $sql)$db->exec(file_get_contents($root.'/contracts/admin/phase2/'.$sql));
$pw=bin2hex(random_bytes(32));$secret=bin2hex(random_bytes(32));$tls=getenv('ADMIN_PHASE4_TLS');$company='aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa';
$fp=openssl_x509_fingerprint(file_get_contents($tls.'/client.pem'),'sha256');
$db->exec("CREATE ROLE phase4_receiver LOGIN PASSWORD '$pw' NOSUPERUSER NOBYPASSRLS NOCREATEDB NOCREATEROLE; GRANT USAGE ON SCHEMA performance_admin_inbox TO phase4_receiver; GRANT SELECT,INSERT ON performance_admin_inbox.receipts,performance_admin_inbox.nonces TO phase4_receiver; GRANT INSERT ON performance_admin_inbox.request_audit TO phase4_receiver; GRANT USAGE ON SEQUENCE performance_admin_inbox.request_audit_id_seq TO phase4_receiver; GRANT EXECUTE ON FUNCTION performance_admin_inbox.can_scope(text,text),performance_admin_inbox.can_nonce(text),performance_admin_inbox.resolve_delegation(text,text,text,text,text) TO phase4_receiver;
 INSERT INTO performance_admin_inbox.principals VALUES('phase4_receiver','portal_service','$company',true,now()+interval '1 hour',NULL);
 INSERT INTO performance_admin_inbox.service_delegations VALUES('phase4_receiver','portal_service','phase4_key_01','$fp','$company','dashboard.read',1,true,now()+interval '1 hour',NULL)");
umask(0077);file_put_contents($tls.'/receiver.json',json_encode(['password'=>$pw]));
file_put_contents($tls.'/keyring.json',json_encode(['portal_service'=>['phase4_key_01'=>['active'=>true,'secret'=>$secret,'expires_at'=>time()+600]]]));
file_put_contents($tls.'/runtime-password',$pw);
file_put_contents($tls.'/identity.json',json_encode(['issuer'=>'portal_service','key_id'=>'phase4_key_01','active'=>true,'secret'=>$secret,'expires_at'=>time()+600,'companies'=>[$company],'operations'=>['dashboard.read']]));
