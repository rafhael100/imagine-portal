<?php
require __DIR__.'/../../../vendor/autoload.php';$app=require __DIR__.'/../../../bootstrap/app.php';$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
if(getenv('CI')!=='true'||getenv('P20_AUTHORITY_PG_CI')!=='1'||getenv('DB_DATABASE')!=='portal_automation_p20_ci')exit(1);
$db=Illuminate\Support\Facades\DB::connection();
$db->statement("CREATE ROLE portal_automation_runtime LOGIN NOSUPERUSER NOBYPASSRLS NOCREATEDB NOCREATEROLE PASSWORD 'SYNTHETIC_P20_CI_ONLY'");
$db->statement('GRANT USAGE ON SCHEMA public TO portal_automation_runtime');
$db->statement('GRANT SELECT ON portal_automation_grants TO portal_automation_runtime');
$db->statement('GRANT INSERT ON portal_automation_access_audit TO portal_automation_runtime');
echo "P20_DISPOSABLE_AUTHORITY_ROLE_READY no_operational_grants=1\n";
