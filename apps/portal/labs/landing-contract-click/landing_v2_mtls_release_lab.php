<?php
declare(strict_types=1);

/**
 * Landing R26: EXACT v2 buyer-clicked acceptance read from disposable PG18,
 * transferred over the historical Sites #474 loopback mTLS transport.
 * An unsigned laboratory projection is NOT a Portal client-approval receipt.
 * No native IAM/MFA, official terms writer, release or publisher is authorized.
 */
if (PHP_SAPI !== 'cli' && PHP_SAPI !== 'cli-server') exit(78);
if (getenv('GITHUB_ACTIONS') !== 'true' || getenv('APP_ENV') !== 'testing'
    || getenv('LANDING_V2_MTLS_LAB') !== '1'
    || getenv('SITES_F395_MTLS_LAB') !== '1') exit(78);
if (PHP_SAPI === 'cli-server'
    && !in_array($_SERVER['REMOTE_ADDR'] ?? '', ['127.0.0.1','::1'], true)) {
    http_response_code(404); exit;
}
require_once __DIR__.'/../../app/Domain/SitesIntegration/ApprovedLandingPageBriefHead.php';
require_once __DIR__.'/../../app/Domain/SitesIntegration/CurrentApprovedLandingPageBriefHeadSource.php';
require_once __DIR__.'/../../app/Domain/SitesIntegration/Pg18LandingPageApprovedBriefHeadSourceCandidate.php';
use App\Domain\SitesIntegration\Pg18LandingPageApprovedBriefHeadSourceCandidate;

const R26_ORDER = 'landing-order-001';
const R26_TENANT = 'tenant-A';
const R26_BUYER = 'buyer-A';
const R26_RELEASE = 'landing-v2-generation-2-ci';
const R26_DSN = 'pgsql:host=127.0.0.1;port=5432;dbname=postgres';

function r26db(string $user='postgres', string $pass='ci-only'): PDO {
    return new PDO(R26_DSN,$user,$pass,[
        PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_EMULATE_PREPARES=>false,
    ]);
}

/** Never derive authority from the request: only an exact, clicked PG18 row. */
function r26evidence(): ?array {
    $db=r26db(); // fresh connection; not the browser or preceding issuer process
    $q=$db->prepare(<<<'SQL'
SELECT a.challenge_ref,a.accepted_at,a.brief_version,a.brief_sha256,
       a.artifact_generation,a.html_sha256,a.terms_sha256,
       o.tenant_ref,o.buyer_ref,o.order_ref,o.site_ref,o.contract_ref,
       o.terms_revision,o.terms_bytes,
       v.html_bytes,v.html_sha256 AS stored_html_sha256,
       v.brief_sha256 AS stored_brief_sha256,
       v.brief_version AS stored_brief_version,
       v.artifact_generation AS stored_generation
FROM landing_exact_click_lab.final_acceptances a
JOIN landing_exact_click_lab.final_presentations p
  ON p.challenge_ref=a.challenge_ref AND p.consumed_at IS NOT NULL
JOIN landing_exact_click_lab.orders o
  ON o.tenant_ref=a.tenant_ref AND o.buyer_ref=a.buyer_ref
 AND o.order_ref=a.order_ref AND o.site_ref=a.site_ref
 AND o.contract_ref=a.contract_ref AND o.terms_revision=a.contract_revision
 AND o.terms_sha256=a.terms_sha256
JOIN landing_exact_click_lab.preview_artifacts v
  ON v.tenant_ref=a.tenant_ref AND v.buyer_ref=a.buyer_ref
 AND v.order_ref=a.order_ref AND v.site_ref=a.site_ref
 AND v.contract_ref=a.contract_ref AND v.contract_revision=a.contract_revision
 AND v.terms_sha256=a.terms_sha256
WHERE a.tenant_ref=? AND a.buyer_ref=? AND a.order_ref=?
 AND a.brief_version=2 AND a.artifact_generation=2
 AND o.product='landing-page'
SQL);
    $q->execute([R26_TENANT,R26_BUYER,R26_ORDER]);
    $rows=$q->fetchAll(PDO::FETCH_ASSOC);
    if(count($rows)!==1) return null;
    $r=$rows[0];
    foreach (['html_sha256','stored_html_sha256','brief_sha256','stored_brief_sha256','terms_sha256'] as $col)
        $r[$col]=trim((string)$r[$col]);
    if (!hash_equals($r['html_sha256'],$r['stored_html_sha256'])
        || !hash_equals(hash('sha256',(string)$r['html_bytes']),$r['html_sha256'])
        || !hash_equals($r['brief_sha256'],$r['stored_brief_sha256'])
        || !hash_equals(hash('sha256',(string)$r['terms_bytes']),$r['terms_sha256'])
        || (int)$r['stored_brief_version']!==2
        || (int)$r['stored_generation']!==2
        || (int)$r['terms_revision']!==2
        || strlen((string)$r['html_bytes'])>32768
        || strlen((string)$r['html_bytes'])<100
        || stripos((string)$r['html_bytes'],'<form')!==false
        || stripos((string)$r['html_bytes'],'<script')!==false) return null;

    $head=(new Pg18LandingPageApprovedBriefHeadSourceCandidate(
        r26db('landing_bridge_ci_reader','synthetic-bridge-only')
    ))->readCurrent(R26_TENANT,R26_ORDER,trim((string)$r['site_ref']));
    if (!$head || $head->sourceVersion!==2 || $head->approvalEpoch!==2
        || $head->tenantRef!==R26_TENANT || $head->orderRef!==R26_ORDER
        || $head->siteRef!==trim((string)$r['site_ref'])
        || $head->contractRef!==$r['contract_ref']
        || $head->contractRevision!==(int)$r['terms_revision']
        || !hash_equals($head->sourceSha256,$r['brief_sha256'])
        || !hash_equals(hash('sha256',$head->sourceBytes()),$r['brief_sha256'])
    ) return null;
    $audit=$db->prepare(<<<'SQL'
SELECT event_kind,actor_ref FROM landing_exact_click_lab.rework_editorial_audit
WHERE request_ref=(SELECT request_ref FROM landing_exact_click_lab.rework_requests
WHERE tenant_ref=? AND buyer_ref=? AND order_ref=?)
ORDER BY event_order
SQL);
    $audit->execute([R26_TENANT,R26_BUYER,R26_ORDER]);
    if($audit->fetchAll(PDO::FETCH_KEY_PAIR)!==[
        'draft_submitted'=>'fictional-editor-A',
        'reviewer_approved'=>'fictional-reviewer-B'
    ]) return null;
    return $r;
}

/** Immutable, unsigned laboratory projection; does NOT assert MFA, authority or release. */
function r26projection(array $r): array {
    return [
        'schemaVersion'=>'sites.landing-final-acceptance.lab.v1',
        'tenantRef'=>(string)$r['tenant_ref'],
        'siteRef'=>trim((string)$r['site_ref']),
        'releaseRef'=>R26_RELEASE,
        'artifactDigest'=>(string)$r['html_sha256'],
        'editorialRevision'=>2,
        'orderRef'=>(string)$r['order_ref'],
        'buyerRef'=>(string)$r['buyer_ref'],
        'contractRef'=>(string)$r['contract_ref'],
        'contractRevision'=>(int)$r['terms_revision'],
        'termsDigest'=>(string)$r['terms_sha256'],
        'briefDigest'=>(string)$r['brief_sha256'],
        'artifactGeneration'=>2,
        'acceptanceRef'=>trim((string)$r['challenge_ref']),
        'operational'=>false,
        'publisherAuthorized'=>false,
        'formEnabled'=>false,
    ];
}

// Allows a later CI-only composition to reuse the verified PG18 source without re-running its dispatcher.
if (defined('LANDING_R27_READ_ONLY_LIBRARY') && LANDING_R27_READ_ONLY_LIBRARY === true) return;

if(PHP_SAPI==='cli-server') {
    header('Cache-Control: no-store');
    header('X-Content-Type-Options: nosniff');
    header('Content-Security-Policy: default-src \'none\'; style-src \'unsafe-inline\'; form-action \'none\'');
    header('X-Landing-Local-Only: no-publisher-no-lead');
    if (($_SERVER['REQUEST_METHOD']??'')!=='GET'
        || parse_url($_SERVER['REQUEST_URI']??'',PHP_URL_PATH)!=='/lab/static-release') {
        http_response_code(403);
        header('Content-Type: text/plain; charset=utf-8');
        echo "NO_DESTINATION_NO_LEAD\n";
        return;
    }
    $r=r26evidence();
    if (!$r) {http_response_code(403); echo "STALE_OR_REVOKED\n";return;}
    header('Content-Type: text/html; charset=utf-8');
    echo $r['html_bytes'];
    return;
}

$mode=$argv[1]??'';
$dir=(string)getenv('F388_FIXTURE_DIR');
if ($dir!=='/tmp/f388-fixture' || !is_dir($dir)) exit(78);
if ($mode==='emit') {
    $r=r26evidence();
    if (!$r) throw new RuntimeException('Exact buyer-clicked v2 evidence absent');
    $projection=r26projection($r);
    if(file_put_contents($dir.'/proof.json',json_encode($projection,JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES),LOCK_EX)===false
       || file_put_contents($dir.'/mode.txt','ok',LOCK_EX)===false)
        throw new RuntimeException('Local transport fixture unavailable');
    echo 'V2_LAB_PROJECTION_SHA256='.hash('sha256',json_encode($projection,JSON_THROW_ON_ERROR))."\n";
    echo 'V2_LANDING_HTML_SHA256='.$r['html_sha256']."\n";
    echo "UNSIGNED=YES; IAM=LAB_ONLY; FORM=OFF; PUBLISH=NO_GO\n";
    exit;
}
if ($mode==='check' || $mode==='check-revoked') {
    spl_autoload_register(static function(string $class):void {
        $prefix='Imagine\\Sites\\';
        if(!str_starts_with($class,$prefix))return;
        $suffix=substr($class,strlen($prefix));
        if(!preg_match('~\A(?:Publication|Domain)\\\\[A-Za-z][A-Za-z0-9]*\z~D',$suffix))
            throw new RuntimeException('Invalid test class');
        require_once (string)getenv('R26_SITES_MTLS_ROOT').'/src/'.str_replace('\\','/',$suffix).'.php';
    });
    $ca=$dir.'/ca.crt';
    $source=new \Imagine\Sites\Publication\PortalClientApprovalMtlsHttpSource(
        'https://localhost:18444/internal/sites/v1/approvals/resolve',
        'localhost',$ca,$dir.'/client.crt',$dir.'/client.key',3000
    );
    $old=json_decode((string)file_get_contents($dir.'/proof.json'),true,12,JSON_THROW_ON_ERROR);
    if(!is_array($old) || ($old['operational']??null)!==false
        || ($old['publisherAuthorized']??null)!==false
        || ($old['formEnabled']??null)!==false) throw new RuntimeException('Unsafe fixture');
    $manifest=new \Imagine\Sites\Domain\ReleaseManifest(
        R26_TENANT,(string)$old['siteRef'],R26_RELEASE,(string)$old['artifactDigest']
    );
    if ($mode==='check') {
        $now=r26evidence();
        if(!$now) throw new RuntimeException('Independent PG18 acceptance readback failed');
        $data=$source->fetchCurrentReceipt($manifest,2);
        if($data===null || $data!==r26projection($now))
            throw new RuntimeException('mTLS transferred wrong source, revision, or digest');
        foreach([
            new \Imagine\Sites\Domain\ReleaseManifest('tenant-B',$manifest->siteRef,R26_RELEASE,$manifest->digest),
            new \Imagine\Sites\Domain\ReleaseManifest(R26_TENANT,$manifest->siteRef,R26_RELEASE,hash('sha256','tamper')),
            new \Imagine\Sites\Domain\ReleaseManifest(R26_TENANT,$manifest->siteRef,'landing-v1-ci',$manifest->digest),
        ] as $wrong) {
            if($source->fetchCurrentReceipt($wrong,2)!==null)
                throw new RuntimeException('Foreign tenant, release or digest was served');
        }
        if($source->fetchCurrentReceipt($manifest,1)!==null)
            throw new RuntimeException('Stale revision was served');
        // NO official signing key or MFA from a laboratory browser session:
        // the real Sites publication approval gate MUST deny this projection.
        $gate=new \Imagine\Sites\Publication\SignedPortalClientApprovalGate(
            $source,new class implements \Imagine\Sites\Publication\IndependentClientApprovalWitness {
                public function confirmsCurrent(
                    \Imagine\Sites\Domain\ReleaseManifest $m,int $revision,
                    string $ref,int $epoch
                ):bool {return false;}
            },''
        );
        if($gate->verify($manifest,2)->allowed)
            throw new RuntimeException('Unsigned lab evidence authorized publication!');
        echo "LANDING_V2_PG18_TO_SITES474_MTLS_PASS; EXACT_V2_SHA=".$now['html_sha256']."\n";
        echo "SIGNED_PUBLISH_GATE=DENIED; NO_OFFICIAL_MFA; NO_GO\n";
    } else {
        if(r26evidence()!==null)
            throw new RuntimeException('Revoked v2 must not be current');
        // mTLS server still holds the old test projection. Fresh PG read wins.
        if($source->fetchCurrentReceipt($manifest,2)===null)
            throw new RuntimeException('Stale TLS fixture unexpectedly unavailable');
        echo "REVOKED_CURRENT_HEAD=DENIED; STALE_TLS_PROJECTION_NOT_AUTHORITY\n";
    }
    exit;
}
if ($mode==='revoke') {
    $db=r26db();
    $db->exec("UPDATE portal_landing_candidate.brief_revisions
        SET state='revoked' WHERE tenant_ref='tenant-A' AND order_ref='landing-order-001'
        AND source_version=2 AND state='approved'");
    if (r26evidence()!==null) throw new RuntimeException('Revocation failed closed');
    echo "V2_FIXTURE_REVOKED; NO_GO\n";exit;
}
exit(64);
