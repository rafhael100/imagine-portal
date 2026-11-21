<?php
declare(strict_types=1);

/**
 * CI-only bridge from an already-clicked Landing contract and approved PG18
 * briefing fixture to the ACTUAL pinned Imagine Sites static renderer.
 * No official writer, client approval, provider, lead, checkout or publication.
 */
if (PHP_SAPI !== 'cli' ||
    getenv('APP_ENV') !== 'testing' ||
    getenv('LANDING_EXACT_CLICK_LAB') !== '1' ||
    getenv('LANDING_PREVIEW_GET_LAB') !== '1' ||
    getenv('LANDING_SITES_RENDER_LAB') !== '1' ||
    getenv('GITHUB_ACTIONS') !== 'true') {
    fwrite(STDERR, "LANDING SITES RENDER LAB DENIED\n");
    exit(78);
}

require_once __DIR__.'/../../app/Domain/SitesIntegration/ApprovedLandingPageBriefHead.php';
require_once __DIR__.'/../../app/Domain/SitesIntegration/CurrentApprovedLandingPageBriefHeadSource.php';
require_once __DIR__.'/../../app/Domain/SitesIntegration/Pg18LandingPageApprovedBriefHeadSourceCandidate.php';

$workspace=getenv('GITHUB_WORKSPACE');
if (!is_string($workspace) || $workspace==='' ||
    !is_dir($workspace.'/sites-snapshot/src/Production')) {
    throw new RuntimeException('Pinned Sites renderer checkout is required');
}
foreach (['SiteBrief','ReleaseArtifact','StructuredCopyCandidate','StaticSiteRenderer'] as $name) {
    require_once $workspace.'/sites-snapshot/src/Production/'.$name.'.php';
}

use App\Domain\SitesIntegration\Pg18LandingPageApprovedBriefHeadSourceCandidate;
use Imagine\Sites\Production\StructuredCopyCandidate;
use Imagine\Sites\Production\StaticSiteRenderer;

$db=new PDO('pgsql:host=127.0.0.1;port=5432;dbname=postgres',
    'postgres','ci-only',[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,
                           PDO::ATTR_EMULATE_PREPARES=>false]);
$tenant='tenant-A';$buyer='buyer-A';$order='landing-order-001';
$mode=$argv[1]??'';

if ($mode==='render') {
    // Reuse the #1041 browser click, #1043 approved-head read and #1047
    // disposable artifact table; do not invent a new acceptance event.
    $stmt=$db->prepare(<<<'SQL'
SELECT o.tenant_ref,o.buyer_ref,o.order_ref,o.site_ref,o.contract_ref,
       o.terms_revision,o.terms_sha256,o.terms_bytes,
       v.brief_version,v.brief_sha256,v.html_sha256 AS prior_html_sha256
FROM landing_exact_click_lab.orders o
JOIN landing_exact_click_lab.preview_artifacts v
  ON v.tenant_ref=o.tenant_ref AND v.buyer_ref=o.buyer_ref
 AND v.order_ref=o.order_ref AND v.site_ref=o.site_ref
 AND v.contract_ref=o.contract_ref AND v.contract_revision=o.terms_revision
 AND v.terms_sha256=o.terms_sha256
WHERE o.tenant_ref=? AND o.buyer_ref=? AND o.order_ref=?
  AND o.product='landing-page'
  AND EXISTS (
      SELECT 1 FROM landing_exact_click_lab.acceptances a
      JOIN landing_exact_click_lab.presentations p
        ON p.challenge_ref=a.challenge_ref
      WHERE a.tenant_ref=o.tenant_ref AND a.buyer_ref=o.buyer_ref
        AND a.order_ref=o.order_ref AND a.contract_ref=o.contract_ref
        AND p.tenant_ref=o.tenant_ref AND p.buyer_ref=o.buyer_ref
        AND p.order_ref=o.order_ref AND p.contract_ref=o.contract_ref
        AND a.terms_revision=o.terms_revision
        AND p.terms_revision=o.terms_revision
        AND a.terms_sha256=o.terms_sha256
        AND p.terms_sha256=o.terms_sha256
        AND a.explicit_accept=true AND p.consumed_at IS NOT NULL
  )
SQL);
    $stmt->execute([$tenant,$buyer,$order]);
    $rows=$stmt->fetchAll(PDO::FETCH_ASSOC);
    if (count($rows)!==1) throw new RuntimeException('No exact same-order click/preview provenance');
    $row=$rows[0];
    if ((int)$row['terms_revision']!==2 ||
        !hash_equals(hash('sha256',$row['terms_bytes']),trim($row['terms_sha256']))) {
        throw new RuntimeException('Old or tampered clicked terms');
    }
    $restricted=new PDO('pgsql:host=127.0.0.1;port=5432;dbname=postgres',
        'landing_bridge_ci_reader','synthetic-bridge-only',
        [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_EMULATE_PREPARES=>false]);
    $head=(new Pg18LandingPageApprovedBriefHeadSourceCandidate($restricted))
        ->readCurrent($tenant,$order,trim($row['site_ref']));
    if (!$head ||
        $head->tenantRef!==$tenant || $head->orderRef!==$order ||
        $head->siteRef!==trim($row['site_ref']) ||
        $head->contractRef!==$row['contract_ref'] ||
        $head->contractRevision!==(int)$row['terms_revision'] ||
        $head->sourceVersion!==(int)$row['brief_version'] ||
        !hash_equals($head->sourceSha256,trim($row['brief_sha256'])) ||
        !hash_equals($head->sourceSha256,hash('sha256',$head->sourceBytes()))) {
        throw new RuntimeException('Briefing HEAD and exact click diverged');
    }
    $copy=json_decode($head->sourceBytes(),true,8,JSON_THROW_ON_ERROR);
    if (!is_array($copy) || array_is_list($copy) ||
        count($copy)!==2 ||
        array_keys($copy)!==['headline','description'] ||
        !is_string($copy['headline']) || !is_string($copy['description'])) {
        throw new RuntimeException('Unrecognized approved fictional brief shape');
    }
    // The source owns only text; site type, brand, CTA and preset are fixed
    // on the Sites side. Neither terms nor the fixture can choose authority.
    $candidate=[
        'schemaVersion'=>'sites.ai-copy.v1',
        'headline'=>$copy['headline'],
        'description'=>$copy['description'],
        'callToAction'=>'Conheça a demonstração fictícia',
        'benefits'=>[
            'Informações claras para um cenário fictício',
            'Exibição adaptada a dispositivos móveis',
            'Revisão técnica privada sem publicação',
        ],
    ];
    $brief=StructuredCopyCandidate::assemble('landing_page','Marca Fictícia Imagine',$candidate);
    $artifact=(new StaticSiteRenderer())->render($brief);
    if ($artifact->sizeBytes>32768 ||
        !hash_equals($artifact->digest,hash('sha256',$artifact->html)) ||
        !str_contains($artifact->html,'data-site-product="landing-page"') ||
        !str_contains($artifact->html,'<meta name="robots" content="noindex, nofollow">') ||
        str_contains($artifact->html,'<script') ||
        str_contains($artifact->html,'<form') ||
        str_contains($artifact->html,'http://') ||
        str_contains($artifact->html,'https://')) {
        throw new RuntimeException('Sites renderer generated unsafe non-private HTML');
    }
    $update=$db->prepare(<<<'SQL'
UPDATE landing_exact_click_lab.preview_artifacts
   SET html_bytes=?,html_sha256=?
 WHERE tenant_ref=? AND buyer_ref=? AND order_ref=? AND site_ref=?
   AND contract_ref=? AND contract_revision=? AND terms_sha256=?
   AND brief_version=? AND brief_sha256=? AND html_sha256=?
SQL);
    $update->execute([$artifact->html,$artifact->digest,$tenant,$buyer,$order,
        trim($row['site_ref']),$head->contractRef,$head->contractRevision,
        trim($row['terms_sha256']),$head->sourceVersion,$head->sourceSha256,
        trim($row['prior_html_sha256'])]);
    if ($update->rowCount()!==1) throw new RuntimeException('CAS rejected renderer replacement');
    echo "LANDING SITES REAL RENDER LAB PASS: exact source/revision to Sites engine\n";
    echo "SITES_ENGINE_PIN=8172e5cc14e97951e529c843059c214e05c33db5\n";
    echo "BRIEF_REVISION=".$head->sourceVersion." BRIEF_SHA256=".$head->sourceSha256."\n";
    echo "HTML_SHA256=".$artifact->digest." HTML_BYTES=".$artifact->sizeBytes."\n";
    echo "AUTH=LAB_SESSION_ONLY; BRIEF_APPROVAL=FIXTURE_ONLY; CLIENT_ACCEPTANCE=NOT_TESTED; RELEASE=NO_GO\n";
    exit(0);
}
if ($mode==='drift-brief') {
    // Laboratory-only mutation: the old rendered artifact must now be denied,
    // even though its HTML bytes and SHA have not changed.
    $bytes='{"headline":"Landing ficticia revisada depois da renderizacao","description":"Alteracao sem novo aceite de preview"}';
    $stmt=$db->prepare(<<<'SQL'
UPDATE portal_landing_candidate.brief_revisions
   SET source_bytes=?,source_sha256=?
 WHERE tenant_ref=? AND order_ref=? AND source_version=1
SQL);
    $stmt->execute([$bytes,hash('sha256',$bytes),$tenant,$order]);
    if ($stmt->rowCount()!==1) throw new RuntimeException('Fixture drift not applied');
    echo "LANDING LAB BRIEF DRIFT APPLIED: stale rendered SHA must be denied\n";
    exit(0);
}
exit(64);
