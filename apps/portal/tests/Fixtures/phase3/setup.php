<?php
declare(strict_types=1);
if(getenv('CI')!=='true'||getenv('ADMIN_PHASE3_HTTPS_CI')!=='1'||getenv('DB_DATABASE')!=='perf_admin_central_ci')exit(1);
$dsn='pgsql:host=127.0.0.1;port=5432;dbname=perf_admin_central_ci';
$db=new PDO($dsn,'postgres',getenv('DB_PASSWORD'),[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
$db->exec(file_get_contents(getenv('PERFORMANCE_ADMIN_LAB_ENGINE_ROOT').'/contracts/admin/phase2/002-service-inbox.sql'));
$password=bin2hex(random_bytes(32));
$db->exec("CREATE ROLE phase3_inbox LOGIN PASSWORD '$password' NOSUPERUSER NOBYPASSRLS NOINHERIT NOCREATEDB NOCREATEROLE; GRANT USAGE ON SCHEMA performance_admin_inbox TO phase3_inbox; GRANT SELECT,INSERT ON ALL TABLES IN SCHEMA performance_admin_inbox TO phase3_inbox");
umask(0077);
file_put_contents(getenv('ADMIN_PHASE3_STATE'),json_encode(['dsn'=>$dsn,'db_user'=>'phase3_inbox','db_password'=>$password,'secret'=>bin2hex(random_bytes(32)),'active'=>true,'audit'=>getenv('ADMIN_PHASE3_TLS').'/audit.jsonl'],JSON_THROW_ON_ERROR));
