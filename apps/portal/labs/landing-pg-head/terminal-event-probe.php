<?php
declare(strict_types=1);

// DISPOSABLE PG18 ONLY. All actor identities and approval events are synthetic.
// This specifically guards against a terminal event inserted before a forged
// "approved" event for the same revision/epoch. Not an operational issuer.
require_once __DIR__.'/../../app/Domain/SitesIntegration/ApprovedLandingPageBriefHead.php';
require_once __DIR__.'/../../app/Domain/SitesIntegration/CurrentApprovedLandingPageBriefHeadSource.php';
require_once __DIR__.'/../../app/Domain/SitesIntegration/Pg18LandingPageApprovedBriefHeadSourceCandidate.php';

use App\Domain\SitesIntegration\Pg18LandingPageApprovedBriefHeadSourceCandidate as HeadReader;

$db = new PDO('pgsql:host=127.0.0.1;port=5432;dbname=postgres', 'postgres', 'ci-only', [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
]);
$db->exec(file_get_contents(__DIR__.'/schema.sql'));
$reader = new HeadReader($db); // privileged only for disposable PG18 fixture

$site = '11111111-1111-4111-8111-111111111111';
$order = 'order-A';
$body = '{"headline":"synthetic fixture only"}';
$digest = hash('sha256', $body);
$insertRevision = $db->prepare(<<<'SQL'
INSERT INTO portal_landing_candidate.brief_revisions (
 tenant_ref, order_ref, site_ref, contract_ref, contract_revision,
 source_version, source_sha256, source_bytes, state,
 approval_ref, approval_epoch, approved_actor_ref
) VALUES (
 :tenant, :order_ref, :site_ref, :contract, 7,
 :version, :digest, :body, :state,
 :approval, :epoch, :actor
)
SQL);
$insertEvent = $db->prepare(<<<'SQL'
INSERT INTO portal_landing_candidate.approval_events (
 event_ref, tenant_ref, order_ref, site_ref, source_version,
 approval_epoch, approval_ref, actor_ref, event_type
) VALUES (
 :event_ref, :tenant, :order_ref, :site_ref, :version,
 :epoch, :approval, :actor, :kind
)
SQL);
$revision = static function (string $tenant, int $v, string $state = 'approved') use (
    $insertRevision, $order, $site, $body, $digest
): void {
    $insertRevision->execute([
        'tenant'=>$tenant, 'order_ref'=>$order, 'site_ref'=>$site,
        'contract'=>'contract-A', 'version'=>$v, 'digest'=>$digest,
        'body'=>$body, 'state'=>$state, 'approval'=>"approval-$tenant-$v",
        'epoch'=>$v, 'actor'=>"actor-$tenant",
    ]);
};
$event = static function (string $tenant, int $v, string $kind) use (
    $insertEvent, $order, $site
): void {
    $insertEvent->execute([
        'event_ref'=>"$tenant-$v-$kind", 'tenant'=>$tenant,
        'order_ref'=>$order, 'site_ref'=>$site, 'version'=>$v,
        'epoch'=>$v, 'approval'=>"approval-$tenant-$v",
        'actor'=>"actor-$tenant", 'kind'=>$kind,
    ]);
};
$read = static fn(string $tenant = 'tenant-A', string $o = 'order-A') =>
    $reader->readCurrent($tenant, $o, $site);
$checks = 0;
$ok = static function (bool $condition, string $case) use (&$checks): void {
    if (!$condition) throw new RuntimeException('PG18 TERMINAL EVENT FENCE FAILURE: '.$case);
    ++$checks;
};

$ok($read() === null, 'no approval means denied');
$revision('tenant-A', 1);
$event('tenant-A', 1, 'approved');
$head = $read();
$ok($head !== null && $head->sourceVersion === 1 && $head->sourceBytes() === $body,
    'valid current version remains readable');

$revision('tenant-B', 1);
$event('tenant-B', 1, 'approved');
$ok($read('tenant-B')?->sourceVersion === 1, 'independent tenant head is valid');
$ok($read('tenant-A')?->sourceVersion === 1, 'tenant B did not change tenant A');

// The previous predicate only excluded events with event_sequence > approval.
// That incorrectly accepted approval inserted AFTER a terminal event.
$revision('tenant-A', 2);
$event('tenant-A', 2, 'revoked');
$ok($read() === null, 'revoked before matching approval is denied');
$event('tenant-A', 2, 'approved');
$ok($read() === null, 'later approval cannot resurrect revoked version/epoch');
$ok($read('tenant-B')?->sourceVersion === 1,
    'terminal event does not revoke another tenant');

// A supersession is also irreversible for the same source revision.
$revision('tenant-A', 3);
$event('tenant-A', 3, 'superseded');
$event('tenant-A', 3, 'approved');
$ok($read() === null, 'approval after supersession cannot resurrect revision');

// Standard later revocation is still denied.
$revision('tenant-A', 4);
$event('tenant-A', 4, 'approved');
$ok($read()?->sourceVersion === 4, 'new approved revision works before revocation');
$event('tenant-A', 4, 'revoked');
$ok($read() === null, 'normal approval then revocation remains denied');

// Only a newer distinct revision/epoch may become approved, not an old replay.
$revision('tenant-A', 5);
$event('tenant-A', 5, 'approved');
$ok($read()?->sourceVersion === 5, 'new version and epoch allow fresh approval');
$ok($read('tenant-A', "order-A' OR TRUE --") === null,
    'SQL injection input does not cross order');
$ok(!$db->inTransaction(), 'every read-only snapshot is closed');

// An unapproved newer draft must still suppress the previous approved head.
$revision('tenant-A', 6, 'draft');
$ok($read() === null, 'newer draft cannot fall back to prior approved revision');
$ok($read('tenant-B')?->sourceVersion === 1,
    'no revision state may leak across tenants');

$eventRows = (int)$db->query('SELECT count(*) FROM portal_landing_candidate.approval_events')
    ->fetchColumn();
$ok($eventRows === 9, 'readbacks never wrote any audit event');

// CI owns this isolated database; cleanup permits running the existing probe
// against a fresh schema in the same workflow.
$db->exec('DROP SCHEMA portal_landing_candidate CASCADE');
echo 'PORTAL LANDING PG18 TERMINAL FENCE PASS: '.$checks.
    ' checks; synthetic PG18 events; no real issuer, RLS grant or publication.'."\n";
