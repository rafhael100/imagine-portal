<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'||$argc!==1||getenv('GITHUB_ACTIONS')!=='true'
    ||getenv('RUNNER_ENVIRONMENT')!=='github-hosted'
    ||getenv('PORTAL_F373_CI')!=='DISPOSABLE_ONLY')exit(64);

require '/opt/portal-recovery/seed-ci.php';

// Bootstrap the SAME Laravel framework and reviewed migration class, with
// independently random SQL admin secret of this disposable runner.
$secret=file_get_contents('/run/secrets/database-admin');
if(!is_string($secret)||preg_match('/\A[a-f0-9]{64}\z/D',$secret)!==1)throw new RuntimeException();
$app=require '/var/www/html/bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$app['config']->set('logging.default','null');
$app['config']->set('database.default','pgsql');
$app['config']->set('database.connections.pgsql',[
    'driver'=>'pgsql','host'=>'127.0.0.1','port'=>5432,
    'database'=>'portal_identity_dev','username'=>'postgres',
    'password'=>$secret,'charset'=>'utf8','prefix'=>'',
    'search_path'=>'public','sslmode'=>'disable',
]);
\Illuminate\Support\Facades\DB::purge('pgsql');
unset($secret);

$migration=require '/var/www/html/database/migrations/2026_11_13_000018_create_portal_recovery_epoch_candidates.php';
$migration->up();
$checks=0;
$assert=static function(bool $value)use(&$checks):void{
    if(!$value)throw new RuntimeException('F373 PostgreSQL invariant failed');
    $checks++;
};
$denied=static function(callable $action)use($assert):void{
    try{$action();}
    catch(Throwable){$assert(true);return;}
    $assert(false);
};

$assert((int)$db->query("SELECT count(*) FROM pg_class c JOIN pg_namespace n ON n.oid=c.relnamespace
 WHERE n.nspname='public' AND c.relname='portal_recovery_epoch_candidates'
 AND c.relrowsecurity AND c.relforcerowsecurity")->fetchColumn()===1);
$assert((int)$db->query("SELECT count(*) FROM pg_roles
 WHERE rolname='portal_recovery_epoch_reader'
 AND NOT (rolcanlogin OR rolsuper OR rolcreatedb OR rolcreaterole OR rolinherit OR rolbypassrls)")->fetchColumn()===1);
$assert((int)$db->query("SELECT count(*) FROM pg_auth_members a JOIN pg_roles r ON r.oid=a.member
 WHERE r.rolname='portal_recovery_epoch_reader'")->fetchColumn()===0);
$assert(in_array($db->query(
    "SELECT has_table_privilege('portal_recovery_epoch_reader',
       'public.portal_recovery_epoch_candidates','SELECT')"
)->fetchColumn(),[true,1,'1','t'],true));
foreach(['portal_browser_runtime','portal_browser_envelope_runtime']as$role){
    $q=$db->prepare("SELECT has_table_privilege(?,
      'public.portal_recovery_epoch_candidates','SELECT,INSERT,UPDATE,DELETE,TRUNCATE')");
    $q->execute([$role]);$assert(!in_array($q->fetchColumn(),[true,1,'1','t'],true));
}
$assert(!in_array($db->query(
    "SELECT has_table_privilege('portal_recovery_epoch_reader',
       'public.portal_recovery_epoch_candidates','UPDATE,DELETE,TRUNCATE')"
)->fetchColumn(),[true,1,'1','t'],true));

$q=$db->prepare("INSERT INTO public.portal_recovery_epoch_candidates(
 organization_id,instance,generation,session_revision,membership_revision,witnessed_sequence
) VALUES(CAST(? AS uuid),'f373_ci',1,3,7,12)");
$q->execute([$oid]);
$assert($db->query("SELECT state FROM public.portal_recovery_epoch_candidates")->fetchColumn()==='quarantined');
$assert((int)$db->query('SELECT count(*) FROM public.portal_recovery_epoch_candidates')->fetchColumn()===1);
$assert((int)$db->query("SELECT count(*) FROM public.portal_auth_sessions WHERE status='active'")->fetchColumn()===1);

$db->exec('SET ROLE portal_recovery_epoch_reader');
try{
    $read=$db->query("SELECT organization_id,instance,generation,
      session_revision,membership_revision,witnessed_sequence,state
      FROM public.portal_recovery_epoch_candidates")->fetch(PDO::FETCH_ASSOC);
    $assert($read['organization_id']===$oid&&$read['state']==='quarantined'
        &&(int)$read['witnessed_sequence']===12);
    $denied(static fn()=>$db->exec(
        "UPDATE public.portal_recovery_epoch_candidates SET state='active'"
    ));
    $denied(static fn()=>$db->exec(
        'DELETE FROM public.portal_recovery_epoch_candidates'
    ));
}finally{$db->exec('RESET ROLE');}

$denied(static fn()=>$db->exec(
    "UPDATE public.portal_recovery_epoch_candidates SET witnessed_sequence=11"
));
$denied(static fn()=>$db->exec(
    "UPDATE public.portal_recovery_epoch_candidates SET witnessed_sequence=14"
));
$denied(static fn()=>$db->exec(
    "UPDATE public.portal_recovery_epoch_candidates SET session_revision=4"
));
$denied(static fn()=>$db->exec(
    "UPDATE public.portal_recovery_epoch_candidates SET session_revision=2,
      witnessed_sequence=13"
));
$denied(static fn()=>$db->exec(
    "UPDATE public.portal_recovery_epoch_candidates SET instance='other',
      witnessed_sequence=13"
));
$denied(static fn()=>$db->exec(
    "UPDATE public.portal_recovery_epoch_candidates SET state='active',
      witnessed_sequence=13"
));
$denied(static fn()=>$db->exec(
    "UPDATE public.portal_recovery_epoch_candidates
      SET organization_id=gen_random_uuid(),witnessed_sequence=13"
));
$db->exec("UPDATE public.portal_recovery_epoch_candidates
 SET session_revision=4,witnessed_sequence=13,updated_at=clock_timestamp()
 WHERE organization_id='$oid'");
$assert((int)$db->query(
    'SELECT witnessed_sequence FROM public.portal_recovery_epoch_candidates'
)->fetchColumn()===13);
$assert($db->query('SELECT state FROM public.portal_recovery_epoch_candidates')
    ->fetchColumn()==='quarantined');

$denied(static fn()=>$migration->down());
$assert((int)$db->query("SELECT count(*) FROM public.portal_recovery_epoch_candidates")->fetchColumn()===1);
$assert(!$auth->isActiveBusinessSession(str_repeat('0',64)));
echo "PASS F373: $checks quarantined PostgreSQL18 epoch constraints, FORCE RLS, NOLOGIN reader and monotonic trigger checks; NO SERVING.\n";
