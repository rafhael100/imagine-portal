<?php

declare(strict_types=1);

/**
 * NO-GO synthetic Portal purchase -> signed Sites CreateProject -> PG18 outbox.
 * Two real but disposable databases, two restricted logins, no serving/VPS.
 */
if (PHP_SAPI !== 'cli' || getenv('APP_ENV') !== 'testing'
    || getenv('GITHUB_ACTIONS') !== 'true'
    || getenv('SITES_P0_AFTER_PURCHASE_LAB') !== '1'
    || getenv('SITES_F360_PG_LAB') !== '1'
    || getenv('SITES_F363_NONCE_LAB') !== '1'
    || getenv('SITES_F364_BRIDGE_LAB') !== '1'
    || getenv('DB_CONNECTION') !== 'pgsql'
    || getenv('DB_HOST') !== '127.0.0.1'
    || getenv('DB_PORT') !== '5432'
    || getenv('DB_DATABASE') !== 'sites_f360_lab'
    || getenv('DB_PASSWORD') !== 'f360-fictional-admin-disposable-only') {
    fwrite(STDERR, "P0 Sites bridge refusing non-disposable environment.\n");
    exit(2);
}
$sitesDir = (string) getenv('SITES_F362_SITES_PATH');
$portalDir = (string) getenv('SITES_F362_PORTAL_PATH');
if (!is_file($sitesDir.'/src/Security/SyntheticSignedAuthorityAdmission.php')
    || !is_file($portalDir.'/apps/portal/app/Domain/SitesIntegration/SitesCreateProjectEncoder.php')
    || !is_file(__DIR__.'/../app/Homologation/RoleBoundTestPurchase.php')) {
    throw new RuntimeException('Pinned fixture sources unavailable.');
}

spl_autoload_register(static function (string $class) use ($sitesDir, $portalDir): void {
    foreach ([
        'Imagine\\Sites\\Persistence\\' => __DIR__.'/../../../_sites_pg_candidate/src/Persistence/',
        'Imagine\\Sites\\Integration\\' => $sitesDir.'/src/Integration/',
        'Imagine\\Sites\\Security\\' => $sitesDir.'/src/Security/',
        'App\\Domain\\SitesIntegration\\' => $portalDir.'/apps/portal/app/Domain/SitesIntegration/',
        'App\\Homologation\\' => __DIR__.'/../app/Homologation/',
    ] as $prefix => $base) {
        if (!str_starts_with($class, $prefix)) continue;
        $name = substr($class, strlen($prefix));
        if (!preg_match('/\A[A-Za-z][A-Za-z0-9]*\z/D', $name)) {
            throw new RuntimeException('Unsafe synthetic class name.');
        }
        require $base.$name.'.php';
        return;
    }
});

use App\Domain\SitesIntegration\PortalSiteContractSource;
use App\Domain\SitesIntegration\SiteContractSnapshot;
use App\Domain\SitesIntegration\SitesCreateProjectEncoder;
use App\Domain\SitesIntegration\SyntheticSitesEnvelopeSigner;
use App\Homologation\RoleBoundTestPurchase;
use Imagine\Sites\Integration\SiteProjectCommand;
use Imagine\Sites\Persistence\LabTransactionalSignedIngressV1;
use Imagine\Sites\Security\SyntheticAuthoritySnapshot;
use Imagine\Sites\Security\SyntheticAuthorityAttestation;

const SAMPLE_PURCHASE_BRIEFING = 'Empresa Fictícia Portal';
$dsn = 'pgsql:host=127.0.0.1;port=5432;connect_timeout=5;dbname=';
$options = [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_EMULATE_PREPARES => false,
    PDO::ATTR_TIMEOUT => 6,
];
$purchase = new PDO(
    $dsn.'portal_purchase_rehearsal',
    'purchase_rehearsal_a', 'ci-synthetic-a-only', $options
);
$sites = new PDO(
    $dsn.'sites_f360_lab',
    'sites_f360_b', 'f360-fictional-tenant-b-only', $options
);
$sitesAdmin = new PDO(
    $dsn.'sites_f360_lab',
    'postgres', 'f360-fictional-admin-disposable-only', $options
);
if ($purchase->query("SELECT current_database()")->fetchColumn() !== 'portal_purchase_rehearsal'
    || $purchase->query('SELECT current_user')->fetchColumn() !== 'purchase_rehearsal_a'
    || $sites->query('SELECT current_user')->fetchColumn() !== 'sites_f360_b'
    || $sitesAdmin->query('SELECT current_database()')->fetchColumn() !== 'sites_f360_lab') {
    throw new RuntimeException('CI-only dual database identity mismatch.');
}

final class P0PurchaseContractCandidate implements PortalSiteContractSource
{
    public function __construct(private readonly PDO $purchase) {}

    public function findAuthorizedSiteOrder(string $orderRef): ?SiteContractSnapshot
    {
        // OBSERVATION ONLY of a fictitious paid order. This is NOT a
        // business authorization service or proof of an external payment.
        if (!preg_match('/\ATEST-[a-f0-9]{32}\z/D', $orderRef)) return null;
        $stmt = $this->purchase->prepare(
            "SELECT id,product,payment,briefing FROM purchase_rehearsal.orders
             WHERE id=? AND tenant='test-org-a'"
        );
        $stmt->execute([$orderRef]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row)
            || $row['payment'] !== 'approved'
            || !in_array($row['product'], ['onepage','landing-pages'], true)
            || !is_string($row['briefing'] ?? null)) return null;
        $data = json_decode($row['briefing'], true);
        if (!is_array($data) || ($data['business'] ?? null) !== SAMPLE_PURCHASE_BRIEFING) {
            return null;
        }
        $product = $row['product'] === 'landing-pages' ? 'landing_page' : 'onepage';
        return new SiteContractSnapshot(
            'tenant-f360-b', $orderRef, 'contract-p0-'.substr($orderRef, 5), 1, $product
        );
    }
}

function p0check(bool $valid, string $reason): void
{
    if (!$valid) throw new RuntimeException('P0 purchase-to-Sites CI: '.$reason);
}
function p0denied(callable $operation): bool
{
    try { $operation(); }
    catch (DomainException|InvalidArgumentException|RuntimeException) { return true; }
    return false;
}

$stmt = $purchase->prepare(
    "SELECT id,product FROM purchase_rehearsal.orders
     WHERE tenant='test-org-a' AND payment='approved'
     AND briefing->>'business'=?
     ORDER BY product,id"
);
$stmt->execute([SAMPLE_PURCHASE_BRIEFING]);
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
p0check(count($rows) === 4, 'four real Chromium-checkout synthetic purchases required.');
$counts = array_count_values(array_column($rows, 'product'));
p0check($counts === ['landing-pages'=>1,'onepage'=>1,'performance'=>2],
    'exact commercial purchase product distribution required.');

$now = time();
$commandKey = 'P0-FICTIONAL-SIGNED-PORTAL-SITES-COMMAND-KEY';
$portalKey = 'P0-FICTIONAL-PORTAL-AUTHORITY-PROOF-KEY-ONLY';
$externalKey = 'P0-FICTIONAL-INDEPENDENT-REVOCATION-KEY-ONLY';
$adapter = new LabTransactionalSignedIngressV1($sites);
$encoder = new SitesCreateProjectEncoder(new P0PurchaseContractCandidate($purchase));
$sent = 0;

foreach ($rows as $row) {
    if ($row['product'] === 'performance') {
        p0check(p0denied(static fn()=> $encoder->build(
            $row['id'], 'operation-p0-performance', 'idem-p0-performance'
        )), 'Performance must never be admitted into Imagine Sites.');
        continue;
    }
    $suffix = substr($row['id'], 5);
    $command = $encoder->build(
        $row['id'], 'operation-p0-'.$suffix, 'idem-p0-'.$suffix
    );
    $obj = SiteProjectCommand::fromArray($command);
    $snapshot = new SyntheticAuthoritySnapshot(
        $obj->tenantRef, $obj->contractRef, $obj->contractRevision,
        true, $now-1, $now+20
    );
    $portalProof = SyntheticAuthorityAttestation::sign(
        'imagine-portal-authority', $snapshot, $portalKey
    );
    $externalProof = SyntheticAuthorityAttestation::sign(
        'imagine-independent-revocation', $snapshot, $externalKey
    );
    $signed = SyntheticSitesEnvelopeSigner::sign(
        $command, $commandKey, 'nonce-p0-'.$suffix.'-first', $now, $now+60
    );
    $result = $adapter->admit(
        $signed, $portalProof, $externalProof,
        $commandKey, $portalKey, $externalKey,
        'site-p0-'.$suffix, $now
    );
    p0check($result['status'] === 'queued' && $result['publishable'] === false
        && $result['operation_ref'] === 'idem-p0-'.$suffix,
        'signed sample Site project must be queued nonpublishable.');
    p0check(p0denied(static fn()=> $adapter->admit(
        $signed, $portalProof, $externalProof,
        $commandKey, $portalKey, $externalKey,
        'site-p0-'.$suffix, $now
    )), 'a persisted nonce must refuse replay.');
    $retry = SyntheticSitesEnvelopeSigner::sign(
        $command, $commandKey, 'nonce-p0-'.$suffix.'-retry', $now, $now+60
    );
    $duplicate = $adapter->admit(
        $retry, $portalProof, $externalProof,
        $commandKey, $portalKey, $externalKey,
        'site-p0-'.$suffix, $now
    );
    p0check($duplicate['status'] === 'duplicate_ignored',
        'fresh nonce + same operation must be idempotent.');
    ++$sent;
}
p0check($sent === 2, 'only Onepage and Landing are eligible.');

$unpaid = (new RoleBoundTestPurchase($purchase))->create(
    'TEST-p0-unpaid-'.bin2hex(random_bytes(8)), 'onepage'
);
p0check(p0denied(static fn()=> $encoder->build(
    $unpaid['id'], 'operation-p0-unpaid', 'idem-p0-unpaid'
)), 'unapproved order must not become a signed Site contract.');

$forged = $encoder->build($rows[0]['id'],
    'operation-p0-forged-tenant', 'idem-p0-forged-tenant');
$forged['tenantRef']='tenant-f360-a';
$forgedObj=SiteProjectCommand::fromArray($forged);
$foreignSnapshot=new SyntheticAuthoritySnapshot(
    $forgedObj->tenantRef, $forgedObj->contractRef, $forgedObj->contractRevision,
    true, $now-1, $now+20
);
$portalForeign=SyntheticAuthorityAttestation::sign(
    'imagine-portal-authority', $foreignSnapshot, $portalKey
);
$externalForeign=SyntheticAuthorityAttestation::sign(
    'imagine-independent-revocation', $foreignSnapshot, $externalKey
);
$foreignWire=SyntheticSitesEnvelopeSigner::sign(
    $forged, $commandKey, 'nonce-p0-forged-tenant', $now, $now+60
);
$foreignDenied=false;
try {
    $adapter->admit(
        $foreignWire, $portalForeign, $externalForeign,
        $commandKey, $portalKey, $externalKey,
        'site-p0-forged-tenant', $now
    );
} catch (PDOException $error) {
    $foreignDenied = $error->getCode() === '42501';
}
p0check($foreignDenied, 'even a valid fake signature may not bypass SQL login mapping.');

$summary = $sitesAdmin->query(
    "SELECT
       (SELECT count(*) FROM sites_f360.admissions WHERE operation_ref LIKE 'idem-p0-%') AS projects,
       (SELECT count(*) FROM sites_f360.outbox WHERE operation_ref LIKE 'idem-p0-%') AS outbox,
       (SELECT count(*) FROM sites_f360.replay_nonces WHERE nonce LIKE 'nonce-p0-%') AS nonces"
)->fetch(PDO::FETCH_ASSOC);
p0check((int)$summary['projects'] === 2
    && (int)$summary['outbox'] === 2
    && (int)$summary['nonces'] === 4,
    'exactly two signed projects and outbox events with four committed nonces.');
p0check(!$sites->inTransaction(), 'no open transactions after denials.');
echo "PASS P0: 4 HTTP checkout rows -> 2 signed Sites admissions/outbox, 2 Performance denied.\n";
echo "NO-GO: fictitious product mapping and proof keys, not real Portal IAM or Site serving.\n";
