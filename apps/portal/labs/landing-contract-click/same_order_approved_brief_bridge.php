<?php
declare(strict_types=1);

/**
 * Isolated PG18 contract-click -> approved-brief HEAD composition.
 * Acceptance comes ONLY from a previous actual browser click in the parent
 * lab. Brief and approval rows are disposable fixtures, not official actors.
 */
if (PHP_SAPI !== 'cli' || getenv('APP_ENV') !== 'testing' ||
    getenv('LANDING_EXACT_CLICK_LAB') !== '1' ||
    getenv('GITHUB_ACTIONS') !== 'true') {
    fwrite(STDERR, "LANDING SAME-ORDER LAB DENIED\n");
    exit(78);
}
require_once __DIR__.'/../../app/Domain/SitesIntegration/ApprovedLandingPageBriefHead.php';
require_once __DIR__.'/../../app/Domain/SitesIntegration/CurrentApprovedLandingPageBriefHeadSource.php';
require_once __DIR__.'/../../app/Domain/SitesIntegration/Pg18LandingPageApprovedBriefHeadSourceCandidate.php';

use App\Domain\SitesIntegration\Pg18LandingPageApprovedBriefHeadSourceCandidate;

$dsn='pgsql:host=127.0.0.1;port=5432;dbname=postgres';
$options=[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION, PDO::ATTR_EMULATE_PREPARES=>false];
$admin=new PDO($dsn,'postgres','ci-only',$options);
$checks=0;
$ok=static function(bool $condition, string $label) use (&$checks): void {
    if (!$condition) throw new RuntimeException('LANDING SAME-ORDER BRIDGE DENIED: '.$label);
    ++$checks;
};
// Selectors only: real authority is NOT inferred from the order strings.
$orderRef='landing-order-001';
$buyerRef='buyer-A';
$tenantRef='tenant-A';
$acceptance=static function(string $tenant, string $buyer) use ($admin,$orderRef): ?array {
    $s=$admin->prepare(<<<'SQL'
SELECT o.tenant_ref,o.buyer_ref,o.order_ref,o.site_ref,o.contract_ref,
       o.terms_revision,o.terms_bytes,o.terms_sha256,
       a.challenge_ref,a.terms_revision AS accepted_revision,
       a.terms_sha256 AS accepted_digest, a.contract_ref AS accepted_contract,
       p.terms_sha256 AS presented_digest, p.contract_ref AS presented_contract
  FROM landing_exact_click_lab.orders o
  JOIN landing_exact_click_lab.acceptances a
    ON a.order_ref=o.order_ref AND a.tenant_ref=o.tenant_ref AND a.buyer_ref=o.buyer_ref
   AND a.contract_ref=o.contract_ref AND a.terms_revision=o.terms_revision
   AND a.terms_sha256=o.terms_sha256 AND a.explicit_accept=true
  JOIN landing_exact_click_lab.presentations p
    ON p.challenge_ref=a.challenge_ref AND p.order_ref=o.order_ref
   AND p.tenant_ref=o.tenant_ref AND p.buyer_ref=o.buyer_ref
   AND p.contract_ref=o.contract_ref AND p.terms_revision=o.terms_revision
   AND p.terms_sha256=o.terms_sha256 AND p.consumed_at IS NOT NULL
 WHERE o.order_ref=:order_ref AND o.tenant_ref=:tenant_ref AND o.buyer_ref=:buyer_ref
SQL);
    $s->execute(['order_ref'=>$orderRef,'tenant_ref'=>$tenant,'buyer_ref'=>$buyer]);
    $rows=$s->fetchAll(PDO::FETCH_ASSOC);
    if (count($rows)!==1) return null;
    $row=$rows[0];
    foreach (['terms_sha256','accepted_digest','presented_digest'] as $field) {
        if (!hash_equals(hash('sha256',$row['terms_bytes']),trim($row[$field]))) return null;
    }
    if ($row['contract_ref']!==$row['presented_contract'] ||
        $row['contract_ref']!==$row['accepted_contract']) return null;
    return $row;
};

$clicked=$acceptance($tenantRef,$buyerRef);
$ok(is_array($clicked),'persisted actual browser click must exist first');
$ok((int)$clicked['terms_revision']===2 && (int)$clicked['accepted_revision']===2,
    'click must cover CURRENT revision 2, not obsolete revision 1');
$ok($clicked['contract_ref']==='contract-landing-lab-001',
    'exact contract identity is recorded, never inferred from an offer');
$ok($acceptance('tenant-A','buyer-B')===null,'same tenant different buyer cannot claim click');
$ok($acceptance('tenant-B','buyer-A')===null,'different tenant cannot claim click');
// Mutation of contract identity alone must invalidate any previously clicked receipt.
$mutate=$admin->prepare('UPDATE landing_exact_click_lab.orders SET contract_ref=? WHERE order_ref=?');
$mutate->execute(['contract-forged-after-click',$orderRef]);
$ok($acceptance($tenantRef,$buyerRef)===null,'changed contract identity invalidates old click');
$mutate->execute(['contract-landing-lab-001',$orderRef]);
$ok($acceptance($tenantRef,$buyerRef)!==null,'restoring fixture identity restores exact read');

// Reuse #1019 source/schema with non-owner RLS. The SQL below writes
// disposable test data, NOT an application writer or approval service.
$schema=file_get_contents(__DIR__.'/../landing-pg-head/schema.sql');
if (!is_string($schema)) throw new RuntimeException('Missing reader fixture schema');
$admin->exec($schema);
$admin->exec("CREATE ROLE landing_bridge_ci_reader LOGIN PASSWORD 'synthetic-bridge-only'");
$admin->exec('GRANT USAGE ON SCHEMA portal_landing_candidate TO landing_bridge_ci_reader');
$admin->exec('GRANT SELECT ON ALL TABLES IN SCHEMA portal_landing_candidate TO landing_bridge_ci_reader');
foreach (['brief_revisions','approval_events'] as $table) {
    $admin->exec("CREATE POLICY landing_bridge_ci_select ON portal_landing_candidate.$table FOR SELECT TO landing_bridge_ci_reader USING (tenant_ref='tenant-A')");
}
$restricted=new PDO($dsn,'landing_bridge_ci_reader','synthetic-bridge-only',$options);
$reader=new Pg18LandingPageApprovedBriefHeadSourceCandidate($restricted);
$site=trim($clicked['site_ref']);
$ok($reader->readCurrent($tenantRef,$orderRef,$site)===null,
    'no approved brief: correlation denied even with accepted terms');
$bytes='{"headline":"Landing ficticia para testar leitura conjunta","description":"Sem cliente real"}';
$briefDigest=hash('sha256',$bytes);
$insert=$admin->prepare(<<<'SQL'
INSERT INTO portal_landing_candidate.brief_revisions
(tenant_ref,order_ref,site_ref,contract_ref,contract_revision,source_version,
 source_sha256,source_bytes,state,approval_ref,approval_epoch,approved_actor_ref)
VALUES (?,?,?,?,?,1,?,?,'approved','fixture-approval-1',1,'fixture-reviewer')
SQL);
$insert->execute([$tenantRef,$orderRef,$site,$clicked['contract_ref'],
    (int)$clicked['terms_revision'],$briefDigest,$bytes]);
$ok($reader->readCurrent($tenantRef,$orderRef,$site)===null,
    'brief approved flag alone never creates approval authority');
$event=$admin->prepare(<<<'SQL'
INSERT INTO portal_landing_candidate.approval_events
(event_ref,tenant_ref,order_ref,site_ref,source_version,approval_epoch,
 approval_ref,actor_ref,event_type)
VALUES (?,?,?,?,1,1,'fixture-approval-1','fixture-reviewer',?)
SQL);
$event->execute(['fixture-event-approved-1',$tenantRef,$orderRef,$site,'approved']);
$head=$reader->readCurrent($tenantRef,$orderRef,$site);
$ok($head!==null && $head->sourceVersion===1 && $head->approvalEpoch===1,
    'restricted RLS reader resolves latest synthetic approved brief');
$ok($head->contractRef===$clicked['contract_ref'] &&
    $head->contractRevision===(int)$clicked['terms_revision'] &&
    $head->orderRef===$orderRef && $head->tenantRef===$tenantRef &&
    $head->siteRef===$site && hash_equals($briefDigest,$head->sourceSha256),
    'same persisted tenant/order/site/contract revision and exact brief digest');
$ok($reader->readCurrent('tenant-B',$orderRef,$site)===null,
    'tenant B cannot see approved head via tenant A DB role');
$ok($reader->readCurrent($tenantRef,'landing-order-foreign',$site)===null,
    'other order cannot borrow approved head');
$ok($reader->readCurrent($tenantRef,$orderRef,
    '22222222-2222-4222-8222-222222222222')===null,
    'other site cannot borrow approved head');

// A contract mutation invalidates the earlier acceptance even though
// the synthetic brief approval event still exists independently.
$change=$admin->prepare('UPDATE landing_exact_click_lab.orders SET terms_revision=3,terms_bytes=?,terms_sha256=? WHERE order_ref=?');
$changedBytes="CONTRATO FICTÍCIO LANDING PAGE — REVISÃO 3\nContrato mudou depois do aceite.";
$change->execute([$changedBytes,hash('sha256',$changedBytes),$orderRef]);
$ok($acceptance($tenantRef,$buyerRef)===null,
    'contract revision 3 invalidates clicked revision 2');
$ok($head->contractRevision!==3,'old brief head does not match changed contract');

// Revocation must invalidate even the same synthetic approved brief.
$event->execute(['fixture-event-revoked-1',$tenantRef,$orderRef,$site,'revoked']);
$ok($reader->readCurrent($tenantRef,$orderRef,$site)===null,
    'terminal revocation hides previously approved head');
$ok(!$restricted->inTransaction(),'restricted read-only transactions closed');
$ok($restricted->query('SELECT current_user')->fetchColumn()==='landing_bridge_ci_reader',
    'actual non-owner PG18 role, not a fake role claim');
$ok(in_array($restricted->query('SELECT rolbypassrls FROM pg_roles WHERE rolname=current_user')->fetchColumn(),
    ['f',false,'0'],true),'no BYPASSRLS on bridge reader');

// No order data, contract bytes, private sessions or tokens in CI logs.
echo "LANDING SAME ORDER CONTRACT-AND-BRIEF BRIDGE PASS: $checks checks; fixture approval only.\n";
echo 'BRIEF_VERSION=1; BRIEF_SHA256='.$briefDigest."\n";
echo "AUTH=FIXTURE_ONLY; OFFICIAL_TERMS_WRITER=NOT_PRESENT; OFFICIAL_BRIEF_APPROVER=NOT_PRESENT; ";
echo "BUYER_PREVIEW_GET=NOT_TESTED; PSP=NOT_CALLED; RELEASE=NO_GO\n";
