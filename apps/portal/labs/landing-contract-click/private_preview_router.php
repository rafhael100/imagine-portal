<?php
declare(strict_types=1);
/**
 * Loopback HTTP fixture only. NOT a Portal route, native session, Sites HTML
 * controller, legal acceptance, final buyer approval or publishing authority.
 */
if (getenv('APP_ENV') !== 'testing' ||
    getenv('LANDING_EXACT_CLICK_LAB') !== '1' ||
    getenv('LANDING_PREVIEW_GET_LAB') !== '1' ||
    getenv('GITHUB_ACTIONS') !== 'true' ||
    !in_array($_SERVER['REMOTE_ADDR']??'', ['127.0.0.1','::1'], true)) {
    http_response_code(404);exit;
}
header('Cache-Control: private, no-store, max-age=0');
header('X-Robots-Tag: noindex, nofollow');
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('Referrer-Policy: no-referrer');
header("Content-Security-Policy: default-src 'none'; sandbox; frame-ancestors 'none'; base-uri 'none'; form-action 'none'");
function denied(): never {
    http_response_code(403);
    header('Content-Type: text/plain; charset=utf-8');
    echo "LANDING LAB PRIVATE PREVIEW DENIED\n";exit;
}
if (($_SERVER['REQUEST_METHOD']??'') !== 'GET' ||
    parse_url($_SERVER['REQUEST_URI']??'',PHP_URL_PATH) !== '/lab/private-preview') denied();
$order=$_GET['order']??null;
if (!is_string($order) || $order !== 'landing-order-001') denied();
ini_set('session.use_strict_mode','1');
ini_set('session.cookie_httponly','1');
ini_set('session.cookie_samesite','Strict');
session_start();
$tenant=$_SESSION['tenant']??null;$buyer=$_SESSION['buyer']??null;
if ($tenant !== 'tenant-A' || $buyer !== 'buyer-A') denied();
session_write_close();

try {
    require_once __DIR__.'/../../app/Domain/SitesIntegration/ApprovedLandingPageBriefHead.php';
    require_once __DIR__.'/../../app/Domain/SitesIntegration/CurrentApprovedLandingPageBriefHeadSource.php';
    require_once __DIR__.'/../../app/Domain/SitesIntegration/Pg18LandingPageApprovedBriefHeadSourceCandidate.php';
    $opts=[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_EMULATE_PREPARES=>false];
    $dsn='pgsql:host=127.0.0.1;port=5432;dbname=postgres';
    // This privileged CI fixture read does NOT qualify as an operational source.
    $db=new PDO($dsn,'postgres','ci-only',$opts);
    $read=static function() use ($db,$tenant,$buyer,$order): ?array {
        $stmt=$db->prepare(<<<'SQL'
SELECT o.tenant_ref,o.buyer_ref,o.order_ref,o.site_ref,o.contract_ref,
       o.terms_revision,o.terms_sha256,o.terms_bytes,
       v.contract_revision AS artifact_contract_revision,
       v.terms_sha256 AS artifact_terms_sha256,
       v.site_ref AS artifact_site_ref,
       v.contract_ref AS artifact_contract_ref,
       v.brief_version,v.brief_sha256,v.html_bytes,v.html_sha256
FROM landing_exact_click_lab.orders o
JOIN landing_exact_click_lab.preview_artifacts v
  ON v.order_ref=o.order_ref AND v.tenant_ref=o.tenant_ref
 AND v.buyer_ref=o.buyer_ref
WHERE o.order_ref=? AND o.tenant_ref=? AND o.buyer_ref=?
  AND o.product='landing-page'
  AND EXISTS (
      SELECT 1 FROM landing_exact_click_lab.acceptances a
      JOIN landing_exact_click_lab.presentations p ON p.challenge_ref=a.challenge_ref
      WHERE a.order_ref=o.order_ref AND a.tenant_ref=o.tenant_ref
        AND a.buyer_ref=o.buyer_ref AND a.contract_ref=o.contract_ref
        AND p.order_ref=o.order_ref AND p.tenant_ref=o.tenant_ref
        AND p.buyer_ref=o.buyer_ref AND p.contract_ref=o.contract_ref
        AND a.terms_revision=o.terms_revision AND p.terms_revision=o.terms_revision
        AND a.terms_sha256=o.terms_sha256 AND p.terms_sha256=o.terms_sha256
        AND p.consumed_at IS NOT NULL AND a.explicit_accept=true
  )
SQL);
        $stmt->execute([$order,$tenant,$buyer]);
        $rows=$stmt->fetchAll(PDO::FETCH_ASSOC);
        return count($rows)===1?$rows[0]:null;
    };
    $a=$read();
    if (!$a || (int)$a['terms_revision']!==(int)$a['artifact_contract_revision'] ||
        $a['contract_ref']!==$a['artifact_contract_ref'] ||
        trim($a['site_ref'])!==trim($a['artifact_site_ref']) ||
        !hash_equals(trim($a['terms_sha256']),trim($a['artifact_terms_sha256'])) ||
        !hash_equals(hash('sha256',$a['terms_bytes']),trim($a['terms_sha256'])) ||
        !hash_equals(hash('sha256',$a['html_bytes']),trim($a['html_sha256']))) denied();
    $restricted=new PDO($dsn,'landing_bridge_ci_reader','synthetic-bridge-only',$opts);
    $head=(new \App\Domain\SitesIntegration\Pg18LandingPageApprovedBriefHeadSourceCandidate($restricted))
        ->readCurrent($tenant,$order,trim($a['site_ref']));
    if (!$head || $head->contractRef!==$a['contract_ref'] ||
        $head->contractRevision!==(int)$a['terms_revision'] ||
        $head->sourceVersion!==(int)$a['brief_version'] ||
        !hash_equals($head->sourceSha256,trim($a['brief_sha256']))) denied();
    $b=$read();
    if (!$b || !hash_equals(hash('sha256',json_encode($a,JSON_THROW_ON_ERROR)),
                             hash('sha256',json_encode($b,JSON_THROW_ON_ERROR)))) denied();
    // This proof checks actual response body bytes, not a claimed ACK or digest header.
    header('Content-Type: text/html; charset=utf-8');
    header('X-Landing-Lab-Only: synthetic-no-go');
    echo $a['html_bytes'];
} catch(Throwable) {denied();}
