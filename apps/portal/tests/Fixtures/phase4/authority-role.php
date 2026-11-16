<?php
require __DIR__.'/../../../vendor/autoload.php';$app=require __DIR__.'/../../../bootstrap/app.php';$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
if(getenv('CI')!=='true'||getenv('ADMIN_PHASE4_PG_CI')!=='1'||getenv('DB_DATABASE')!=='performance_phase4_ci')exit(1);
$db=Illuminate\Support\Facades\DB::connection();
$db->statement("CREATE ROLE portal_performance_runtime LOGIN NOSUPERUSER NOBYPASSRLS NOCREATEDB NOCREATEROLE PASSWORD 'SYNTHETIC_PHASE4_CI_ONLY'");
$db->statement('GRANT USAGE ON SCHEMA public TO portal_performance_runtime');
$db->statement('GRANT SELECT ON portal_performance_services,portal_performance_grants TO portal_performance_runtime');
$db->statement('GRANT INSERT ON portal_performance_access_audit TO portal_performance_runtime');
echo "PHASE4_DISPOSABLE_AUTHORITY_ROLE_READY\n";
