<?php
declare(strict_types=1);
/**
 * CI-only, same-order immutable HTML fixture. This is NOT a Site renderer,
 * private-preview service, official approval writer or operational source.
 */
if (PHP_SAPI !== 'cli' || getenv('APP_ENV') !== 'testing' ||
    getenv('LANDING_EXACT_CLICK_LAB') !== '1' ||
    getenv('LANDING_PREVIEW_GET_LAB') !== '1' ||
    getenv('GITHUB_ACTIONS') !== 'true') {
    fwrite(STDERR, "LANDING PRIVATE GET LAB DENIED\n"); exit(78);
}
require_once __DIR__.'/../../app/Domain/SitesIntegration/ApprovedLandingPageBriefHead.php';
require_once __DIR__.'/../../app/Domain/SitesIntegration/CurrentApprovedLandingPageBriefHeadSource.php';
require_once __DIR__.'/../../app/Domain/SitesIntegration/Pg18LandingPageApprovedBriefHeadSourceCandidate.php';

use App\Domain\SitesIntegration\Pg18LandingPageApprovedBriefHeadSourceCandidate;

$dsn='pgsql:host=127.0.0.1;port=5432;dbname=postgres';
$options=[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_EMULATE_PREPARES=>false];
$db=new PDO($dsn,'postgres','ci-only',$options);
$mode=$argv[1]??'';
$order='landing-order-001'; $tenant='tenant-A'; $buyer='buyer-A';
if ($mode==='seed') {
    $s=$db->prepare(<<<'SQL'
SELECT o.tenant_ref,o.buyer_ref,o.order_ref,o.site_ref,o.contract_ref,
       o.terms_revision,o.terms_sha256,o.terms_bytes
  FROM landing_exact_click_lab.orders o
 WHERE o.order_ref=? AND o.tenant_ref=? AND o.buyer_ref=?
   AND EXISTS (
       SELECT 1 FROM landing_exact_click_lab.acceptances a
       JOIN landing_exact_click_lab.presentations p ON p.challenge_ref=a.challenge_ref
       WHERE a.order_ref=o.order_ref AND a.tenant_ref=o.tenant_ref
         AND a.buyer_ref=o.buyer_ref AND a.contract_ref=o.contract_ref
         AND p.contract_ref=o.contract_ref
         AND a.terms_revision=o.terms_revision AND p.terms_revision=o.terms_revision
         AND a.terms_sha256=o.terms_sha256 AND p.terms_sha256=o.terms_sha256
         AND p.consumed_at IS NOT NULL AND a.explicit_accept=true
   )
SQL);
    $s->execute([$order,$tenant,$buyer]); $row=$s->fetch(PDO::FETCH_ASSOC);
    if (!$row || (int)$row['terms_revision']!==2 ||
        !hash_equals(hash('sha256',$row['terms_bytes']),trim($row['terms_sha256']))) {
        throw new RuntimeException('Same-order clicked contract not current');
    }
    $restricted=new PDO($dsn,'landing_bridge_ci_reader','synthetic-bridge-only',$options);
    $head=(new Pg18LandingPageApprovedBriefHeadSourceCandidate($restricted))
        ->readCurrent($tenant,$order,trim($row['site_ref']));
    if (!$head || $head->contractRef!==$row['contract_ref'] ||
        $head->contractRevision!==(int)$row['terms_revision'] ||
        $head->sourceVersion!==1 ||
        !hash_equals($head->sourceSha256,hash('sha256',$head->sourceBytes()))) {
        throw new RuntimeException('Approved fixture HEAD mismatch');
    }
    // Opaque, inert HTML from the *existing same-order fixture*, not a new
    // generator and not a claim that the real Sites renderer was invoked.
    $html='<!doctype html><html lang="pt-BR"><head><meta charset="utf-8">'
        .'<meta name="robots" content="noindex, nofollow">'
        .'<title>Landing de laboratório</title></head><body>'
        .'<main><h1>Landing fictícia para teste privado</h1>'
        .'<p>Prévia sintética, sem cliente, publicidade ou publicação.</p>'
        .'</main></body></html>';
    $db->exec(<<<'SQL'
CREATE TABLE landing_exact_click_lab.preview_artifacts (
    order_ref text PRIMARY KEY REFERENCES landing_exact_click_lab.orders(order_ref),
    tenant_ref text NOT NULL, buyer_ref text NOT NULL,
    site_ref uuid NOT NULL, contract_ref text NOT NULL,
    contract_revision integer NOT NULL, terms_sha256 char(64) NOT NULL,
    brief_version integer NOT NULL, brief_sha256 char(64) NOT NULL,
    html_bytes text NOT NULL CHECK (octet_length(html_bytes) BETWEEN 1 AND 32768),
    html_sha256 char(64) NOT NULL CHECK (html_sha256 ~ '^[a-f0-9]{64}$')
);
REVOKE ALL ON landing_exact_click_lab.preview_artifacts FROM PUBLIC;
SQL);
    $insert=$db->prepare('INSERT INTO landing_exact_click_lab.preview_artifacts VALUES(?,?,?,?,?,?,?,?,?,?,?)');
    $insert->execute([$order,$tenant,$buyer,trim($row['site_ref']),$row['contract_ref'],
        (int)$row['terms_revision'],trim($row['terms_sha256']),
        $head->sourceVersion,$head->sourceSha256,$html,hash('sha256',$html)]);
    echo "LANDING LAB PREVIEW FIXTURE SEEDED: same clicked order and approved synthetic brief\n";
    echo 'HTML_SHA256='.hash('sha256',$html).' BRIEF_SHA256='.$head->sourceSha256."\n";
    exit(0);
}
if ($mode==='inspect') {
    $s=$db->prepare('SELECT html_sha256,brief_sha256,contract_revision FROM landing_exact_click_lab.preview_artifacts WHERE order_ref=?');
    $s->execute([$order]); $r=$s->fetch(PDO::FETCH_ASSOC);
    if (!$r) exit(3);
    echo json_encode(['htmlSha256'=>trim($r['html_sha256']),
        'briefSha256'=>trim($r['brief_sha256']),
        'contractRevision'=>(int)$r['contract_revision'],
        'operational'=>false],JSON_THROW_ON_ERROR)."\n"; exit(0);
}
if ($mode==='corrupt') {
    $s=$db->prepare("UPDATE landing_exact_click_lab.preview_artifacts SET html_sha256=repeat('0',64) WHERE order_ref=?");
    $s->execute([$order]); if ($s->rowCount()!==1) exit(3);
    echo "LAB PREVIEW HASH CORRUPTED\n"; exit(0);
}
if ($mode==='restore') {
    // pgcrypto SHA function availability varies: use PHP digest instead.
    $s=$db->prepare('SELECT html_bytes FROM landing_exact_click_lab.preview_artifacts WHERE order_ref=?');
    $s->execute([$order]);$html=$s->fetchColumn();
    if (!is_string($html)) exit(3);
    $up=$db->prepare('UPDATE landing_exact_click_lab.preview_artifacts SET html_sha256=? WHERE order_ref=?');
    $up->execute([hash('sha256',$html),$order]);
    echo "LAB PREVIEW HASH RESTORED\n"; exit(0);
}
if ($mode==='revoke') {
    $event=$db->prepare(<<<'SQL'
INSERT INTO portal_landing_candidate.approval_events
(event_ref,tenant_ref,order_ref,site_ref,source_version,approval_epoch,
 approval_ref,actor_ref,event_type)
SELECT 'preview-lab-revoked-1',tenant_ref,order_ref,site_ref,source_version,
       approval_epoch,approval_ref,approved_actor_ref,'revoked'
  FROM portal_landing_candidate.brief_revisions
 WHERE tenant_ref=? AND order_ref=? AND source_version=1
SQL);
    $event->execute([$tenant,$order]);if ($event->rowCount()!==1) exit(3);
    echo "LAB BRIEF REVOKED; HTTP GET MUST DENY\n"; exit(0);
}
exit(64);
