<?php

declare(strict_types=1);

/**
 * CI-only: four real synthetic individual customer orders from the Chromium
 * Comercial->Portal checkout -> two qualified Site projects and durable outbox
 * in a SECOND, disposable PostgreSQL18 database.
 *
 * NOT a payment receipt, contract authority, identity or deployment.
 * The order extract is from the CI database administrator, not a live S2S API.
 */
if (PHP_SAPI !== 'cli' || getenv('GITHUB_ACTIONS') !== 'true'
    || getenv('P0_OWNED_TO_SITES_CI') !== '1'
    || getenv('SITES_F360_PG_LAB') !== '1'
    || getenv('SITES_F361_OUTBOX_LAB') !== '1'
    || getenv('SITES_F363_NONCE_LAB') !== '1'
    || getenv('SITES_F364_BRIDGE_LAB') !== '1'
    || getenv('APP_ENV') !== 'testing'
    || getenv('DB_HOST') !== '127.0.0.1'
    || getenv('DB_DATABASE') !== 'sites_f360_lab'
    || getenv('DB_PORT') !== '5432') {
    fwrite(STDERR, "P0 owned checkout/Sites refusing non-disposable CI.\n");
    exit(2);
}
$workspace = dirname(__DIR__, 3);
$sitePg = $workspace.'/_sites_pg_candidate';
$siteSigner = $workspace.'/_sites_signed_candidate';
$portalSigner = $workspace.'/_portal_sites_signer_candidate';
foreach ([
    $sitePg.'/src/Persistence/LabTransactionalSignedIngressV1.php',
    $siteSigner.'/src/Security/SyntheticAuthorityAttestation.php',
    $portalSigner.'/apps/portal/app/Domain/SitesIntegration/SyntheticSitesEnvelopeSigner.php',
] as $path) {
    if (!is_file($path)) throw new RuntimeException('An exact pinned code dependency is missing.');
}
spl_autoload_register(static function (string $class) use ($sitePg, $siteSigner, $portalSigner): void {
    foreach ([
        'Imagine\\Sites\\Persistence\\' => $sitePg.'/src/Persistence/',
        'Imagine\\Sites\\Integration\\' => $siteSigner.'/src/Integration/',
        'Imagine\\Sites\\Security\\' => $siteSigner.'/src/Security/',
        'App\\Domain\\SitesIntegration\\' => $portalSigner.'/apps/portal/app/Domain/SitesIntegration/',
    ] as $prefix => $directory) {
        if (!str_starts_with($class,$prefix)) continue;
        $short = substr($class, strlen($prefix));
        if (!preg_match('/\A[A-Za-z][A-Za-z0-9]*\z/D',$short)) {
            throw new RuntimeException('Unexpected synthetic class name.');
        }
        require $directory.$short.'.php';
        return;
    }
});

use App\Domain\SitesIntegration\PortalSiteContractSource;
use App\Domain\SitesIntegration\SiteContractSnapshot;
use App\Domain\SitesIntegration\SitesCreateProjectEncoder;
use App\Domain\SitesIntegration\SyntheticSitesEnvelopeSigner;
use Imagine\Sites\Integration\SiteProjectCommand;
use Imagine\Sites\Persistence\LabTransactionalSignedIngressV1;
use Imagine\Sites\Security\SyntheticAuthoritySnapshot;
use Imagine\Sites\Security\SyntheticAuthorityAttestation;

$runner = getenv('RUNNER_TEMP');
if (!is_string($runner) || $runner === '' || !is_dir($runner) || is_link($runner)) {
    throw new RuntimeException('CI scratch directory unavailable.');
}
$sourceFile = $runner.'/p0-owned-commercial-orders.tsv';
if (!is_file($sourceFile) || is_link($sourceFile) || filesize($sourceFile) > 2048) {
    throw new RuntimeException('Synthetic customer order extract missing or unsafe.');
}
$lines = file($sourceFile, FILE_IGNORE_NEW_LINES);
if (!is_array($lines) || count($lines) !== 4) {
    throw new RuntimeException('Exactly four individual customer purchases required.');
}
$orders = [];
$products = [];
$owners = [];
foreach ($lines as $line) {
    $cells = explode("\t", $line);
    if (count($cells) !== 8) throw new RuntimeException('Invalid individually owned order shape.');
    [$id,$product,$payment,$briefing,$owner,$orderTenant,$ownerTenant,$enabled] = $cells;
    if (!preg_match('/\ATEST-[a-f0-9]{32}\z/D',$id)
        || !in_array($product,['onepage','landing-pages','performance'],true)
        || !in_array($payment,['pending','declined','approved'],true)
        || !in_array($briefing,['0','1'],true)
        || !preg_match('/\Ademo-[a-f0-9]{32}\z/D',$owner)
        || $orderTenant!=='test-org-a' || $ownerTenant!==$orderTenant
        || $enabled!=='t' || isset($owners[$owner])
        || isset($orders[$id])) {
        throw new RuntimeException('Invalid or duplicate customer order.');
    }
    $orders[$id]=['product'=>$product,'payment'=>$payment,'briefing'=>$briefing==='1',
      'owner'=>$owner,'tenant'=>$orderTenant,'enabled'=>true];
    $owners[$owner]=true;
    $products[$product] = ($products[$product] ?? 0)+1;
}
ksort($products);
if ($products !== ['landing-pages'=>1,'onepage'=>1,'performance'=>2]) {
    throw new RuntimeException('Four Comercial CTA products do not match.');
}
foreach ($orders as $row) {
    if ($row['payment'] !== 'approved' || !$row['briefing']) {
        throw new RuntimeException('Chromium must save four fictional approved payments + briefings.');
    }
}

final readonly class OwnedPortalContractFixture implements PortalSiteContractSource
{
    /** @param array<string,array{product:string,payment:string,briefing:bool,owner:string,tenant:string,enabled:bool}> $orders */
    public function __construct(private array $orders) {}

    public function findAuthorizedSiteOrder(string $orderRef): ?SiteContractSnapshot
    {
        $row=$this->orders[$orderRef] ?? null;
        // TEST authority only: no PSP, legal contract, IAM or external witness.
        if (!is_array($row) || $row['payment'] !== 'approved'
            || !$row['briefing'] || !$row['enabled']
            || $row['tenant'] !== 'test-org-a'
            || !preg_match('/\Ademo-[a-f0-9]{32}\z/D',$row['owner'])
            || !in_array($row['product'],['onepage','landing-pages'],true)) {
            return null;
        }
        return new SiteContractSnapshot(
            'tenant-f360-b', $orderRef,
            'contract-ownedp0-'.substr($orderRef,5),
            1, $row['product']==='onepage'?'onepage':'landing_page',
        );
    }
}

$checks=0;
function ownedCheck(bool $ok,string $reason): void
{
    global $checks;
    if (!$ok) throw new RuntimeException('P0 owned checkout→Sites: '.$reason);
    ++$checks;
}
function ownedDenied(callable $operation): bool
{
    try {$operation();}
    catch (DomainException|InvalidArgumentException|RuntimeException) {return true;}
    return false;
}
$dsn='pgsql:host=127.0.0.1;port=5432;dbname=sites_f360_lab;connect_timeout=5';
$opts=[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,
       PDO::ATTR_EMULATE_PREPARES=>false,PDO::ATTR_TIMEOUT=>6];
$db=new PDO($dsn,'sites_f360_b','f360-fictional-tenant-b-only',$opts);
$admin=new PDO($dsn,'postgres','f360-fictional-admin-disposable-only',$opts);
$worker=new PDO($dsn,'sites_f361_worker','f361-fictional-outbox-worker-only',$opts);
ownedCheck($db->query('SELECT session_user')->fetchColumn()==='sites_f360_b',
    'restricted Sites SQL login required');
ownedCheck($admin->query("SELECT current_database()")->fetchColumn()==='sites_f360_lab',
    'isolated Sites database identity required');
ownedCheck((int)$admin->query("SELECT current_setting('server_version_num')")->fetchColumn()>=180000,
    'PostgreSQL18 required');

// Existing F360-F364 fixtures leave their own events in the same disposable DB.
// ACK them with their OWN lease before creating checkout-originated events.
$drained=0;
while (true) {
    $claim=$worker->prepare('SELECT * FROM sites_f360.claim_next_outbox(?)');
    $claim->execute([30]);
    $event=$claim->fetch(PDO::FETCH_ASSOC);
    if ($event===false) break;
    ownedCheck(!str_starts_with($event['operation_ref'],'idem-ownedp0-'),
        'cannot silently consume this integration events during setup');
    $ack=$worker->prepare('SELECT sites_f360.ack_outbox(?,?,?::uuid)');
    $ack->execute([$event['tenant_ref'],$event['operation_ref'],$event['lease_ref']]);
    ownedCheck(in_array($ack->fetchColumn(),[true,'t',1,'1'],true),
        'only actual worker lease can acknowledge earlier fixture');
    if (++$drained>100) throw new RuntimeException('Too many baseline events.');
}

$encoder=new SitesCreateProjectEncoder(new OwnedPortalContractFixture($orders));
$adapter=new LabTransactionalSignedIngressV1($db);
$now=time();
$commandKey='OWNED-P0-FICTIONAL-PORTAL-SITES-COMMAND-KEY';
$portalKey='OWNED-P0-FICTIONAL-PORTAL-AUTHORITY-KEY';
$externalKey='OWNED-P0-FICTIONAL-EXTERNAL-REVOCATION-KEY';
$admitted=0;
$excluded=0;
foreach ($orders as $id=>$row) {
    $suffix=substr($id,5);
    if ($row['product']==='performance') {
        ownedCheck(ownedDenied(static fn()=> $encoder->build(
            $id,'operation-ownedp0-'.$suffix,'idem-ownedp0-'.$suffix
        )), 'Performance must not provision a Sites project');
        ++$excluded;
        continue;
    }
    $wire=$encoder->build(
        $id,'operation-ownedp0-'.$suffix,'idem-ownedp0-'.$suffix
    );
    $command=SiteProjectCommand::fromArray($wire);
    $snapshot=new SyntheticAuthoritySnapshot(
        $command->tenantRef,$command->contractRef,$command->contractRevision,
        true,$now-1,$now+25
    );
    $portalProof=SyntheticAuthorityAttestation::sign(
        'imagine-portal-authority',$snapshot,$portalKey);
    $externalProof=SyntheticAuthorityAttestation::sign(
        'imagine-independent-revocation',$snapshot,$externalKey);
    $nonce='nonce-ownedp0-'.$suffix.'-first';
    $signed=SyntheticSitesEnvelopeSigner::sign(
        $wire,$commandKey,$nonce,$now,$now+60);
    $site='site-ownedp0-'.$suffix;
    $first=$adapter->admit(
        $signed,$portalProof,$externalProof,
        $commandKey,$portalKey,$externalKey,$site,$now);
    ownedCheck($first['status']==='queued' && $first['publishable']===false,
        'one fictional owned order becomes non-publishable Sites admission');
    ownedCheck(ownedDenied(static fn()=> $adapter->admit(
        $signed,$portalProof,$externalProof,
        $commandKey,$portalKey,$externalKey,$site,$now
    )), 'persistent signed nonce prevents cross-request replay');
    $retry=SyntheticSitesEnvelopeSigner::sign(
        $wire,$commandKey,'nonce-ownedp0-'.$suffix.'-retry',$now,$now+60);
    $dedup=$adapter->admit(
        $retry,$portalProof,$externalProof,
        $commandKey,$portalKey,$externalKey,$site,$now);
    ownedCheck($dedup['status']==='duplicate_ignored',
        'new nonce cannot duplicate the same owned project');
    ++$admitted;
}
ownedCheck($admitted===2 && $excluded===2,
    'only Onepage/Landing eligible; both Performance purchases excluded');

// A synthetically "paid" order without a stored briefing must still DENY.
// No order row is modified: test only a stripped copy of source metadata.
$unbriefed=$orders;
foreach ($unbriefed as &$row) {if ($row['product']==='onepage') {$row['briefing']=false;break;}}
unset($row);
$blockedEncoder=new SitesCreateProjectEncoder(new OwnedPortalContractFixture($unbriefed));
$onepage=array_key_first(array_filter($orders,static fn($r)=>$r['product']==='onepage'));
ownedCheck(is_string($onepage) && ownedDenied(
    static fn()=> $blockedEncoder->build($onepage,'operation-ownedp0-unbriefed','idem-ownedp0-unbriefed')
), 'approved fictional status without briefing does not authorize Sites');

// A revocation or tenant mismatch must block future provisioning even if
// payment=approved and briefing was already saved in the test database.
$inactive=$orders;
$inactive[$onepage]['enabled']=false;
$inactiveEncoder=new SitesCreateProjectEncoder(new OwnedPortalContractFixture($inactive));
ownedCheck(ownedDenied(static fn()=> $inactiveEncoder->build(
    $onepage,'operation-ownedp0-disabled','idem-ownedp0-disabled'
)), 'disabled owner must be refused despite fake paid order');

$foreign=$orders;
$foreign[$onepage]['tenant']='test-org-b';
$foreignEncoder=new SitesCreateProjectEncoder(new OwnedPortalContractFixture($foreign));
ownedCheck(ownedDenied(static fn()=> $foreignEncoder->build(
    $onepage,'operation-ownedp0-foreign','idem-ownedp0-foreign'
)), 'forged tenant metadata must not authorize a Site contract');

$rows=$admin->query(
    "SELECT
       (SELECT count(*) FROM sites_f360.admissions WHERE operation_ref LIKE 'idem-ownedp0-%') AS projects,
       (SELECT count(*) FROM sites_f360.outbox WHERE operation_ref LIKE 'idem-ownedp0-%') AS events,
       (SELECT count(*) FROM sites_f360.replay_nonces WHERE nonce LIKE 'nonce-ownedp0-%') AS nonces"
)->fetch(PDO::FETCH_ASSOC);
ownedCheck((int)$rows['projects']===2 && (int)$rows['events']===2 &&
    (int)$rows['nonces']===4, 'two durable projects/outbox and four accepted nonces');

// Independent restricted worker consumes EXACTLY the two queued projects.
$seen=[];
for ($i=0;$i<2;++$i) {
    $q=$worker->prepare('SELECT * FROM sites_f360.claim_next_outbox(?)');
    $q->execute([30]);
    $event=$q->fetch(PDO::FETCH_ASSOC);
    ownedCheck(is_array($event) &&
        str_starts_with($event['operation_ref'],'idem-ownedp0-') &&
        !isset($seen[$event['operation_ref']]), 'two distinct owned events required');
    $seen[$event['operation_ref']]=true;
    $ack=$worker->prepare('SELECT sites_f360.ack_outbox(?,?,?::uuid)');
    $ack->execute([$event['tenant_ref'],$event['operation_ref'],$event['lease_ref']]);
    ownedCheck(in_array($ack->fetchColumn(),[true,'t',1,'1'],true),
        'worker must ACK by its real PG lease');
}
$remaining=$worker->prepare('SELECT * FROM sites_f360.claim_next_outbox(?)');
$remaining->execute([30]);
ownedCheck($remaining->fetch(PDO::FETCH_ASSOC)===false,
    'no pending owned Site events remain after two ACKs');

$evidence=[
    'schema'=>'imagine.portal-p0.owned-customer-to-sites.pg18.v1',
    'source'=>'four_individual_synthetic_postgresql_customer_orders',
    'customers_from_real_chromium_checkout'=>4,
    'unique_synthetic_customer_owners_verified'=>4,
    'disabled_and_foreign_owners_refused'=>true,
    'sites_projects_admitted_nonpublishable'=>2,
    'sites_outbox_events_acknowledged'=>2,
    'performance_offers_excluded'=>2,
    'replay_and_duplicate_checks_passed'=>true,
    'sql_databases_independent'=>true,
    'customers_real'=>false,
    'portal_identity_real'=>false,
    'legal_contract_verified'=>false,
    'psp_payment_verified'=>false,
    'external_revocation_real'=>false,
    'sites_public_endpoint_enabled'=>false,
    'site_publishing_authorized'=>false,
    'vps_changed'=>false,
    'production_release'=>false,
    'release_gate'=>'NO_GO',
];
$canonical=json_encode($evidence,JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES);
$report=json_encode([
    'claims'=>$evidence,
    'sha256_integrity_only'=>hash('sha256',$canonical),
],JSON_THROW_ON_ERROR|JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES);
$destination=$runner.'/p0-owned-customer-to-sites-evidence.json';
if (file_exists($destination) || is_link($destination) ||
    file_put_contents($destination,$report."\n",LOCK_EX)===false) {
    throw new RuntimeException('Refusing to overwrite or lose CI evidence.');
}
echo "PASS P0 owned: 4 individual Chromium purchases -> 2 Sites PG18 projects/outbox + 2 worker ACK, 2 Performance blocked; NO-GO real publishing.\n";
