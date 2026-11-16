<?php
declare(strict_types=1);
require __DIR__.'/../../app/Homologation/Commerce/CompositeQuotePreview.php';

use App\Homologation\Commerce\CompositeQuotePreview;

/**
 * F350: real PostgreSQL18 RLS with separate synthetic LOGIN identities.
 * No production schema, customer, remote DB, payment, grant API or HTTP.
 */
if (getenv('PORTAL_F350_DISPOSABLE')!=='LOCAL_127_0_0_1_ONLY'
 || getenv('PGHOST')!=='127.0.0.1'
 || getenv('PGPORT')!=='5432'
 || getenv('PGDATABASE')!=='portal_composite_f350_ci'
 || getenv('PGUSER')!=='postgres'
 || getenv('PGPASSWORD')!=='f350-disposable-admin-only') {
    throw new RuntimeException('F350: refusing non-disposable database');
}
function db(string $role,string $pass): PDO {
    return new PDO(
        'pgsql:host=127.0.0.1;port=5432;dbname=portal_composite_f350_ci;connect_timeout=3;sslmode=disable',
        $role,$pass,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES=>false]
    );
}
function check(bool $condition,string $reason): void {
    if (!$condition)throw new RuntimeException('F350 security invariant: '.$reason);
}
function denied(callable $run,string $reason): void {
    try {$run();}catch(PDOException $e) {
        check(in_array((string)$e->getCode(),['42501','42P01','42809'],true),
            'unexpected denial type: '.$reason);
        return;
    }
    throw new RuntimeException('F350 unexpected authorization: '.$reason);
}
$admin=db('postgres','f350-disposable-admin-only');
$roleA='f350_tenant_a_reader';
$roleB='f350_tenant_b_reader';
$a=db($roleA,'f350-disposable-a-only');
$b=db($roleB,'f350-disposable-b-only');

foreach ([$admin,$a,$b] as $connection) {
    $who=$connection->query("SELECT current_user AS role, current_database() AS db,
       inet_server_addr()::text AS host, inet_server_port() AS port")->fetch();
    check($who['db']==='portal_composite_f350_ci' &&
        $who['host']==='127.0.0.1' && (int)$who['port']===5432,
        'SQL connection must really be the isolated disposable database');
}
foreach ([$roleA,$roleB] as $role){
    $query=$admin->prepare("SELECT rolcanlogin,rolsuper,rolbypassrls,rolinherit,rolcreaterole,rolcreatedb
       FROM pg_roles WHERE rolname=:role");
    $query->execute([':role'=>$role]);
    $props=$query->fetch();
    check($props!==false,'synthetic reader role exists');
    foreach (['rolsuper','rolbypassrls','rolinherit','rolcreaterole','rolcreatedb'] as $flag)
        check(in_array($props[$flag],[false,'f','0',0],true),
            'role must have least privilege: '.$flag);
}
$policy=$admin->query("SELECT relrowsecurity,relforcerowsecurity
 FROM pg_class WHERE oid='composite_cart_f350.draft_quote'::regclass")->fetch();
check(in_array($policy['relrowsecurity'],[true,'t','1',1],true)
 && in_array($policy['relforcerowsecurity'],[true,'t','1',1],true),
 'FORCE RLS must be effective on real table');
check((int)$admin->query("SELECT count(*) FROM pg_policies
 WHERE schemaname='composite_cart_f350' AND tablename='draft_quote'
 AND policyname='f350_tenant_self_read'")->fetchColumn()===1,
 'exact dedicated read-only policy');

$engine=new CompositeQuotePreview();
$quoteA=$engine->quote(['site'=>'onepage','channels'=>['google_ads','meta_ads'],
    'tier'=>'recommended']);
$quoteB=$engine->quote(['site'=>'landing-pages','channels'=>['meta_ads'],
    'tier'=>'minimum']);
check($quoteA['setup_activation_total_cents']===197000,
 'A priced by real PHP V5');
check($quoteB['setup_activation_total_cents']===178000,
 'B priced by real PHP V5');
$quotes=[$roleA=>$quoteA,$roleB=>$quoteB];
$ids=[
    $roleA=>'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa',
    $roleB=>'bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbbb'
];
$insert=$admin->prepare('INSERT INTO composite_cart_f350.draft_quote
 (id,tenant_role,catalog_version,selection_hash,server_quote,expires_at)
 VALUES (:id,:role,:catalog,:hash,CAST(:quote AS jsonb),now()+interval \'20 minutes\')');
foreach ($quotes as $role=>$quote){
    $selectionHash=hash('sha256',json_encode(
        [$quote['site'],$quote['channels'],$quote['media_tier']],
        JSON_THROW_ON_ERROR
    ));
    $insert->execute([
        ':id'=>$ids[$role],':role'=>$role,
        ':catalog'=>$quote['catalog_version'],':hash'=>$selectionHash,
        ':quote'=>json_encode($quote,JSON_THROW_ON_ERROR)
    ]);
}
$readSql='SELECT id,tenant_role,catalog_version,selection_hash,server_quote,
 quote_revision FROM composite_cart_f350.draft_quote ORDER BY id';
function ownRow(PDO $db,string $role,string $id,string $sql): array {
    $rows=$db->query($sql)->fetchAll();
    check(count($rows)===1,'role '.$role.' must see exactly one row');
    check($rows[0]['id']===$id && $rows[0]['tenant_role']===$role,
        'cross-tenant RLS read forbidden');
    return $rows[0];
}
$ra=ownRow($a,$roleA,$ids[$roleA],$readSql);
$rb=ownRow($b,$roleB,$ids[$roleB],$readSql);
check((int)$ra['quote_revision']===1,'immutable revision A');
check((int)$rb['quote_revision']===1,'immutable revision B');
check((json_decode($ra['server_quote'],true,512,JSON_THROW_ON_ERROR)
 ['setup_activation_total_cents']??null)===197000,'A server-owned amount');
check((json_decode($rb['server_quote'],true,512,JSON_THROW_ON_ERROR)
 ['setup_activation_total_cents']??null)===178000,'B server-owned amount');
$selectId='SELECT count(*) FROM composite_cart_f350.draft_quote WHERE id=:id';
foreach ([[$a,$ids[$roleB]],[$b,$ids[$roleA]]] as [$db,$foreign]){
    $q=$db->prepare($selectId);
    $q->execute([':id'=>$foreign]);
    check((int)$q->fetchColumn()===0,'direct guessed ID must not cross tenants');
}
// A malicious per-tenant GUC has NO AUTHORITY; current_user derives from
// the separate PostgreSQL LOGIN role, not a browser-supplied organization id.
foreach ([[$a,$roleB,$roleA,$ids[$roleA]],[$b,$roleA,$roleB,$ids[$roleB]]]
 as [$db,$otherRole,$ownRole,$ownId]){
    $db->exec("SELECT set_config('app.organization_id','". $otherRole ."',false)");
    ownRow($db,$ownRole,$ownId,$readSql);
    denied(fn()=>$db->exec('SET ROLE '.$otherRole),'role takeover');
}
denied(fn()=>$a->exec("INSERT INTO composite_cart_f350.draft_quote
(id,tenant_role,catalog_version,selection_hash,server_quote,expires_at)
VALUES (gen_random_uuid(),'".$roleA."',repeat('a',64),repeat('b',64),'{}',now())"),
 'A cannot fabricate a server quote');
denied(fn()=>$a->exec("UPDATE composite_cart_f350.draft_quote
SET server_quote='{}'"),'no amount edit A');
denied(fn()=>$b->exec("DELETE FROM composite_cart_f350.draft_quote"),
 'no delete B');
denied(fn()=>$a->exec("ALTER TABLE composite_cart_f350.draft_quote DISABLE ROW LEVEL SECURITY"),
 'no RLS bypass DDL');
// A server-side revocation is immediately visible to an existing login.
$revoke=$admin->prepare("UPDATE composite_cart_f350.draft_quote
 SET revoked_at=now() WHERE id=:id");
$revoke->execute([':id'=>$ids[$roleA]]);
check((int)$a->query('SELECT count(*) FROM composite_cart_f350.draft_quote')->fetchColumn()===0,
 'revoked A must disappear immediately');
ownRow($b,$roleB,$ids[$roleB],$readSql);
$expire=$admin->prepare("UPDATE composite_cart_f350.draft_quote
 SET expires_at=now()-interval '1 second' WHERE id=:id");
$expire->execute([':id'=>$ids[$roleB]]);
check((int)$b->query('SELECT count(*) FROM composite_cart_f350.draft_quote')->fetchColumn()===0,
 'expired B must disappear immediately');
$adminCount=(int)$admin->query('SELECT count(*) FROM composite_cart_f350.draft_quote')->fetchColumn();
check($adminCount===2,'administrator retained exact two synthetic rows');
echo json_encode([
 'contractVersion'=>'portal-f350-disposable-pg18-composite-cart-rls-lab-v1',
 'actualPostgresConnectionVerified'=>true,'separateTenantLoginsVerified'=>2,
 'forceRlsVerified'=>true,'crossTenantReadsDenied'=>true,
 'sessionVariableSpoofingDenied'=>true,'crossTenantRoleSwitchDenied'=>true,
 'tenantInsertsUpdatesDeletesDenied'=>true,
 'revocationEffectiveImmediately'=>true,'expiryEffectiveImmediately'=>true,
 'serverQuoteCentsVerified'=>true,'syntheticRows'=>2,
 'customerDataUsed'=>false,'publicCheckoutAuthorized'=>false,
 'realChargeEnabled'=>false,'releaseStatus'=>'HOLD_AUTHN_MULTI_ITEM_PERSISTENCE_PSP_HUMAN_GO'
],JSON_THROW_ON_ERROR)."\n";
