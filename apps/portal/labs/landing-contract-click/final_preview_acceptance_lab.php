<?php
declare(strict_types=1);

/**
 * Landing same-order FINAL buyer acceptance experiment.
 * Disposable PG18 + loopback + synthetic session only; never Portal-native
 * identity, official legal acceptance, operational authorization or publisher.
 *
 * Reuses the already-clicked contract and original Sites-rendered HTTP artifact
 * from Portal #1041/#1043/#1047/#1050. No mock render or contract writer here.
 */
if (getenv('APP_ENV') !== 'testing'
    || getenv('GITHUB_ACTIONS') !== 'true'
    || getenv('LANDING_EXACT_CLICK_LAB') !== '1'
    || getenv('LANDING_PREVIEW_GET_LAB') !== '1'
    || getenv('LANDING_SITES_RENDER_LAB') !== '1'
    || getenv('LANDING_FINAL_ACCEPT_LAB') !== '1'
    || (PHP_SAPI !== 'cli'
        && !in_array($_SERVER['REMOTE_ADDR'] ?? '', ['127.0.0.1', '::1'], true))) {
    if (PHP_SAPI !== 'cli') http_response_code(404);
    exit(78);
}

require_once __DIR__.'/../../app/Domain/SitesIntegration/ApprovedLandingPageBriefHead.php';
require_once __DIR__.'/../../app/Domain/SitesIntegration/CurrentApprovedLandingPageBriefHeadSource.php';
require_once __DIR__.'/../../app/Domain/SitesIntegration/Pg18LandingPageApprovedBriefHeadSourceCandidate.php';

use App\Domain\SitesIntegration\Pg18LandingPageApprovedBriefHeadSourceCandidate;

const LAB_ORDER = 'landing-order-001';
const LAB_TENANT = 'tenant-A';
const LAB_BUYER = 'buyer-A';
const LAB_DSN = 'pgsql:host=127.0.0.1;port=5432;dbname=postgres';

function labDb(string $user = 'postgres', string $password = 'ci-only'): PDO {
    return new PDO(LAB_DSN, $user, $password, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);
}
function labDeny(int $status = 403): never {
    http_response_code($status);
    header('Content-Type: text/plain; charset=utf-8');
    echo "LANDING FINAL ACCEPTANCE LAB DENIED\n";
    exit;
}
function labEsc(string $s): string {
    return htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

/** Returns null if any current source, exact buyer, or digest disagrees. */
function labCurrent(PDO $db, string $tenant, string $buyer): ?array {
    if ($tenant !== LAB_TENANT || $buyer !== LAB_BUYER) return null;
    $query = $db->prepare(<<<'SQL'
SELECT o.tenant_ref,o.buyer_ref,o.order_ref,o.site_ref,o.contract_ref,
       o.terms_revision,o.terms_sha256,o.terms_bytes,
       v.contract_revision AS artifact_contract_revision,
       v.terms_sha256 AS artifact_terms_sha256,
       v.site_ref AS artifact_site_ref,v.contract_ref AS artifact_contract_ref,
       v.brief_version,v.brief_sha256,v.html_bytes,v.html_sha256,
       v.artifact_generation
  FROM landing_exact_click_lab.orders o
  JOIN landing_exact_click_lab.preview_artifacts v
    ON v.tenant_ref=o.tenant_ref AND v.buyer_ref=o.buyer_ref
   AND v.order_ref=o.order_ref AND v.site_ref=o.site_ref
 WHERE o.tenant_ref=? AND o.buyer_ref=? AND o.order_ref=?
   AND o.product='landing-page'
   AND EXISTS (
     SELECT 1 FROM landing_exact_click_lab.acceptances a
     JOIN landing_exact_click_lab.presentations p ON p.challenge_ref=a.challenge_ref
     WHERE a.order_ref=o.order_ref AND a.tenant_ref=o.tenant_ref
       AND a.buyer_ref=o.buyer_ref AND a.contract_ref=o.contract_ref
       AND a.terms_revision=o.terms_revision AND a.terms_sha256=o.terms_sha256
       AND a.explicit_accept=true
       AND p.order_ref=o.order_ref AND p.tenant_ref=o.tenant_ref
       AND p.buyer_ref=o.buyer_ref AND p.contract_ref=o.contract_ref
       AND p.terms_revision=o.terms_revision AND p.terms_sha256=o.terms_sha256
       AND p.consumed_at IS NOT NULL
   )
SQL);
    $query->execute([$tenant, $buyer, LAB_ORDER]);
    $rows = $query->fetchAll(PDO::FETCH_ASSOC);
    if (count($rows) !== 1) return null;
    $r = $rows[0];
    if ($r['contract_ref'] !== $r['artifact_contract_ref']
        || trim($r['site_ref']) !== trim($r['artifact_site_ref'])
        || (int)$r['terms_revision'] !== (int)$r['artifact_contract_revision']
        || !hash_equals(trim($r['terms_sha256']), trim($r['artifact_terms_sha256']))
        || !hash_equals(hash('sha256', $r['terms_bytes']), trim($r['terms_sha256']))
        || !hash_equals(hash('sha256', $r['html_bytes']), trim($r['html_sha256']))
        || (int)$r['artifact_generation'] < 1) return null;
    // Use the already-existing restricted PG18 reader; no approval writer.
    $reader = new Pg18LandingPageApprovedBriefHeadSourceCandidate(
        labDb('landing_bridge_ci_reader', 'synthetic-bridge-only'));
    $head = $reader->readCurrent($tenant, LAB_ORDER, trim($r['site_ref']));
    if (!$head
        || $head->tenantRef !== $tenant || $head->orderRef !== LAB_ORDER
        || $head->siteRef !== trim($r['site_ref'])
        || $head->contractRef !== $r['contract_ref']
        || $head->contractRevision !== (int)$r['terms_revision']
        || $head->sourceVersion !== (int)$r['brief_version']
        || !hash_equals($head->sourceSha256, trim($r['brief_sha256']))
        || !hash_equals($head->sourceSha256, hash('sha256', $head->sourceBytes()))) {
        return null;
    }
    // NEW #1055 CI-only gate: an explicitly requested adjustment suspends
    // final consent for exactly the old briefing/HTML generation immediately,
    // before a synthetic editor produces the revised draft. It does not revoke
    // official review authority or prevent viewing the old HTML as reference.
    if (getenv('LANDING_REWORK_V2_LAB') === '1'
        && $db->query("SELECT to_regclass('landing_exact_click_lab.rework_requests')")
              ->fetchColumn() !== null) {
        $pending = $db->prepare(<<<'SQL'
SELECT 1 FROM landing_exact_click_lab.rework_requests
 WHERE tenant_ref=? AND buyer_ref=? AND order_ref=? AND site_ref=?
   AND prior_brief_version=? AND prior_brief_sha256=?
   AND prior_artifact_generation=? AND prior_html_sha256=?
 LIMIT 1
SQL);
        $pending->execute([LAB_TENANT, LAB_BUYER, LAB_ORDER,
            trim($r['site_ref']), (int)$r['brief_version'],
            trim($r['brief_sha256']), (int)$r['artifact_generation'],
            trim($r['html_sha256'])]);
        if ($pending->fetchColumn() !== false) return null;
    }
    return $r;
}
function labAccepted(PDO $db, ?array $r): bool {
    if (!$r) return false;
    $s = $db->prepare(<<<'SQL'
SELECT count(*)
  FROM landing_exact_click_lab.final_acceptances a
 WHERE a.order_ref=? AND a.tenant_ref=? AND a.buyer_ref=?
   AND a.site_ref=? AND a.contract_ref=? AND a.contract_revision=?
   AND a.terms_sha256=? AND a.brief_version=? AND a.brief_sha256=?
   AND a.artifact_generation=? AND a.html_sha256=?
SQL);
    $s->execute([LAB_ORDER, LAB_TENANT, LAB_BUYER,
        trim($r['site_ref']), $r['contract_ref'], (int)$r['terms_revision'],
        trim($r['terms_sha256']), (int)$r['brief_version'], trim($r['brief_sha256']),
        (int)$r['artifact_generation'], trim($r['html_sha256'])]);
    return (int)$s->fetchColumn() === 1;
}

if (PHP_SAPI === 'cli') {
    $mode = $argv[1] ?? '';
    $db = labDb();
    if ($mode === 'init') {
        $db->exec(<<<'SQL'
ALTER TABLE landing_exact_click_lab.preview_artifacts
 ADD COLUMN artifact_generation integer NOT NULL DEFAULT 1
 CHECK (artifact_generation > 0);
CREATE TABLE landing_exact_click_lab.final_presentations (
    challenge_ref char(32) PRIMARY KEY,
    order_ref text NOT NULL,
    tenant_ref text NOT NULL,
    buyer_ref text NOT NULL,
    site_ref uuid NOT NULL,
    contract_ref text NOT NULL,
    contract_revision integer NOT NULL,
    terms_sha256 char(64) NOT NULL,
    brief_version integer NOT NULL,
    brief_sha256 char(64) NOT NULL,
    artifact_generation integer NOT NULL,
    html_sha256 char(64) NOT NULL,
    session_sha256 char(64) NOT NULL,
    csrf_sha256 char(64) NOT NULL,
    expires_at timestamptz NOT NULL DEFAULT (clock_timestamp() + interval '10 minutes'),
    consumed_at timestamptz NULL
);
CREATE TABLE landing_exact_click_lab.final_acceptances (
    challenge_ref char(32) PRIMARY KEY REFERENCES landing_exact_click_lab.final_presentations(challenge_ref),
    order_ref text NOT NULL,
    tenant_ref text NOT NULL,
    buyer_ref text NOT NULL,
    site_ref uuid NOT NULL,
    contract_ref text NOT NULL,
    contract_revision integer NOT NULL,
    terms_sha256 char(64) NOT NULL,
    brief_version integer NOT NULL,
    brief_sha256 char(64) NOT NULL,
    artifact_generation integer NOT NULL,
    html_sha256 char(64) NOT NULL,
    accepted_at timestamptz NOT NULL DEFAULT clock_timestamp(),
    UNIQUE(tenant_ref,buyer_ref,order_ref,artifact_generation)
);
CREATE TABLE landing_exact_click_lab.final_original_html (
    order_ref text PRIMARY KEY, html_bytes text NOT NULL, html_sha256 char(64) NOT NULL
);
INSERT INTO landing_exact_click_lab.final_original_html
SELECT order_ref,html_bytes,html_sha256 FROM landing_exact_click_lab.preview_artifacts;
REVOKE ALL ON landing_exact_click_lab.final_presentations,
 landing_exact_click_lab.final_acceptances,
 landing_exact_click_lab.final_original_html FROM PUBLIC;
SQL);
        if (!labCurrent($db, LAB_TENANT, LAB_BUYER)) {
            throw new RuntimeException('No current same-order real Sites preview');
        }
        echo "LANDING FINAL ACCEPT LAB INIT: synthetic source only; NO_GO\n";
        exit;
    }
    if ($mode === 'readback') {
        // A separate PHP process / PG18 connection after browser click.
        $r = labCurrent($db, LAB_TENANT, LAB_BUYER);
        echo json_encode([
            'labFinalAccepted' => labAccepted($db, $r),
            'artifactGeneration' => $r ? (int)$r['artifact_generation'] : null,
            'artifactSha256' => $r ? trim($r['html_sha256']) : null,
            'briefRevision' => $r ? (int)$r['brief_version'] : null,
            'currentSource' => $r !== null,
            'operational' => false,
        ], JSON_THROW_ON_ERROR)."\n";
        exit;
    }
    if ($mode === 'change-artifact' || $mode === 'restore-artifact') {
        // Content revision in the disposable database only. Even byte-identical
        // restoration increases generation; stale buyer approval never revives.
        $r = labCurrent($db, LAB_TENANT, LAB_BUYER);
        if (!$r) throw new RuntimeException('No current fixture to revise');
        $html = $mode === 'change-artifact'
            ? $r['html_bytes']."\n<!-- alteracao sintetica de artefato -->"
            : (string)$db->query("SELECT html_bytes FROM landing_exact_click_lab.final_original_html WHERE order_ref='landing-order-001'")->fetchColumn();
        if ($html === '' || strlen($html) > 32768 || $html === $r['html_bytes'])
            throw new RuntimeException('Expected a new nonempty artifact');
        $up = $db->prepare(<<<'SQL'
UPDATE landing_exact_click_lab.preview_artifacts
   SET html_bytes=?,html_sha256=?,artifact_generation=artifact_generation+1
 WHERE order_ref=? AND tenant_ref=? AND buyer_ref=?
   AND artifact_generation=? AND html_sha256=?
SQL);
        $up->execute([$html,hash('sha256',$html),LAB_ORDER,LAB_TENANT,LAB_BUYER,
            (int)$r['artifact_generation'],trim($r['html_sha256'])]);
        if ($up->rowCount() !== 1) throw new RuntimeException('CAS failed');
        echo "LANDING LAB ARTIFACT GENERATION ADVANCED; NO_GO\n";
        exit;
    }
    exit(64);
}

header('Cache-Control: private, no-store, max-age=0');
header('Content-Security-Policy: default-src \'none\'; style-src \'unsafe-inline\'; form-action \'self\'; base-uri \'none\'; frame-ancestors \'none\'');
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('X-Landing-Lab-Only: final-click-synthetic-no-go');
ini_set('session.use_strict_mode','1');
ini_set('session.cookie_httponly','1');
ini_set('session.cookie_samesite','Strict');
session_start();
$tenant = $_SESSION['tenant'] ?? null;
$buyer = $_SESSION['buyer'] ?? null;
if ($tenant !== LAB_TENANT || $buyer !== LAB_BUYER) labDeny();
$path = parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH);
$method = $_SERVER['REQUEST_METHOD'] ?? '';
try {
    $db = labDb();
    if ($path === '/lab/final-review' && $method === 'GET') {
        $r = labCurrent($db, $tenant, $buyer);
        if (!$r) labDeny();
        $challenge = bin2hex(random_bytes(16));
        $csrf = bin2hex(random_bytes(32));
        $s = $db->prepare(<<<'SQL'
INSERT INTO landing_exact_click_lab.final_presentations
(challenge_ref,order_ref,tenant_ref,buyer_ref,site_ref,contract_ref,
 contract_revision,terms_sha256,brief_version,brief_sha256,artifact_generation,
 html_sha256,session_sha256,csrf_sha256)
VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?)
SQL);
        $s->execute([$challenge,LAB_ORDER,$tenant,$buyer,trim($r['site_ref']),
            $r['contract_ref'],(int)$r['terms_revision'],trim($r['terms_sha256']),
            (int)$r['brief_version'],trim($r['brief_sha256']),
            (int)$r['artifact_generation'],trim($r['html_sha256']),
            hash('sha256',session_id()),hash('sha256',$csrf)]);
        header('Content-Type: text/html; charset=utf-8');
        echo '<!doctype html><html lang="pt-BR"><meta charset="utf-8">';
        echo '<title>Aceite final fictício da prévia Landing</title>';
        echo '<h1>Aceite final fictício da prévia Landing</h1>';
        echo '<p>Somente laboratório. Não é aceite oficial ou autorização de publicação.</p>';
        echo '<p id="order">'.labEsc(LAB_ORDER).'</p>';
        echo '<p id="brief-revision">'.(int)$r['brief_version'].'</p>';
        echo '<p id="artifact-generation">'.(int)$r['artifact_generation'].'</p>';
        echo '<p id="html-digest">'.labEsc(trim($r['html_sha256'])).'</p>';
        echo '<a id="view-preview" href="http://127.0.0.1:8766/lab/private-preview?order=landing-order-001">Conferir prévia privada</a>';
        echo '<form method="post" action="/lab/final-accept">';
        foreach (['challenge'=>$challenge,'csrf'=>$csrf,'order'=>LAB_ORDER,
                  'generation'=>(string)$r['artifact_generation'],
                  'digest'=>trim($r['html_sha256'])] as $k=>$v) {
            echo '<input type="hidden" name="'.labEsc($k).'" value="'.labEsc($v).'">';
        }
        echo '<label><input id="final-checkbox" type="checkbox" name="confirm" value="yes" required>';
        echo ' Aceito explicitamente apenas esta geração e SHA do HTML fictício</label>';
        echo '<button id="final-confirm" type="submit">Confirmar prévia fictícia</button>';
        echo '</form></html>';
        exit;
    }
    if ($path === '/lab/final-accept' && $method === 'POST') {
        $challenge = $_POST['challenge'] ?? null;
        $csrf = $_POST['csrf'] ?? null;
        $order = $_POST['order'] ?? null;
        $generation = $_POST['generation'] ?? null;
        $digest = $_POST['digest'] ?? null;
        if (!is_string($challenge) || !preg_match('/\A[a-f0-9]{32}\z/D',$challenge)
            || !is_string($csrf) || !preg_match('/\A[a-f0-9]{64}\z/D',$csrf)
            || $order !== LAB_ORDER || !is_string($generation)
            || !preg_match('/\A[1-9][0-9]{0,7}\z/D',$generation)
            || !is_string($digest) || !preg_match('/\A[a-f0-9]{64}\z/D',$digest)
            || ($_POST['confirm'] ?? null) !== 'yes') labDeny(409);
        $db->beginTransaction();
        $s = $db->prepare('SELECT * FROM landing_exact_click_lab.final_presentations WHERE challenge_ref=? FOR UPDATE');
        $s->execute([$challenge]);
        $p = $s->fetch(PDO::FETCH_ASSOC);
        $r = labCurrent($db,$tenant,$buyer);
        if (!$p || !$r || $p['consumed_at'] !== null
            || strtotime($p['expires_at']) <= time()
            || $p['order_ref'] !== LAB_ORDER || $p['tenant_ref'] !== $tenant
            || $p['buyer_ref'] !== $buyer
            || trim($p['site_ref']) !== trim($r['site_ref'])
            || $p['contract_ref'] !== $r['contract_ref']
            || (int)$p['contract_revision'] !== (int)$r['terms_revision']
            || !hash_equals(trim($p['terms_sha256']),trim($r['terms_sha256']))
            || (int)$p['brief_version'] !== (int)$r['brief_version']
            || !hash_equals(trim($p['brief_sha256']),trim($r['brief_sha256']))
            || (int)$p['artifact_generation'] !== (int)$r['artifact_generation']
            || (int)$p['artifact_generation'] !== (int)$generation
            || !hash_equals(trim($p['html_sha256']),trim($r['html_sha256']))
            || !hash_equals($digest,trim($r['html_sha256']))
            || !hash_equals(trim($p['session_sha256']),hash('sha256',session_id()))
            || !hash_equals(trim($p['csrf_sha256']),hash('sha256',$csrf))) {
            $db->rollBack();labDeny(409);
        }
        $s = $db->prepare('UPDATE landing_exact_click_lab.final_presentations SET consumed_at=clock_timestamp() WHERE challenge_ref=? AND consumed_at IS NULL');
        $s->execute([$challenge]);
        if ($s->rowCount() !== 1) {$db->rollBack();labDeny(409);}
        $s = $db->prepare(<<<'SQL'
INSERT INTO landing_exact_click_lab.final_acceptances
(challenge_ref,order_ref,tenant_ref,buyer_ref,site_ref,contract_ref,
 contract_revision,terms_sha256,brief_version,brief_sha256,artifact_generation,html_sha256)
VALUES (?,?,?,?,?,?,?,?,?,?,?,?)
SQL);
        $s->execute([$challenge,LAB_ORDER,$tenant,$buyer,trim($r['site_ref']),
            $r['contract_ref'],(int)$r['terms_revision'],trim($r['terms_sha256']),
            (int)$r['brief_version'],trim($r['brief_sha256']),
            (int)$r['artifact_generation'],trim($r['html_sha256'])]);
        $db->commit();
        header('Content-Type: text/plain; charset=utf-8');
        echo "LANDING LAB FINAL ACCEPTED ONLY; RELEASE=NO_GO\n";
        exit;
    }
    labDeny(404);
} catch (Throwable) {
    if (isset($db) && $db->inTransaction()) $db->rollBack();
    labDeny(409);
}
