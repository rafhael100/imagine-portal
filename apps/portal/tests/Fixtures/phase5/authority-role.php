<?php
require __DIR__.'/../../../vendor/autoload.php';$app=require __DIR__.'/../../../bootstrap/app.php';$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
set_exception_handler(static function(Throwable $e){fwrite(STDERR,"PHASE5_ROLE_SETUP_FAILED\n");exit(1);});
if(getenv('CI')!=='true'||getenv('ADMIN_PHASE5_CI')!=='1'||getenv('DB_DATABASE')!=='performance_phase4_ci')exit(1);
$db=Illuminate\Support\Facades\DB::connection();$r=$db->selectOne("SELECT current_database() AS db,session_user AS login,(SELECT system_identifier::text FROM pg_control_system()) AS cluster");
if($r->db!=='performance_phase4_ci'||$r->login!=='postgres'||$r->cluster!==getenv('PHASE5_EXPECTED_CLUSTER'))throw new RuntimeException;
$db->statement("CREATE ROLE portal_automation_runtime LOGIN NOSUPERUSER NOBYPASSRLS NOCREATEDB NOCREATEROLE PASSWORD 'SYNTHETIC_P20_CI_ONLY'");
$db->statement('GRANT USAGE ON SCHEMA public TO portal_automation_runtime');$db->statement('GRANT SELECT ON portal_automation_grants TO portal_automation_runtime');$db->statement('GRANT INSERT ON portal_automation_access_audit TO portal_automation_runtime');
echo "PHASE5_DISTINCT_AUTHORITY_ROLE_READY\n";
