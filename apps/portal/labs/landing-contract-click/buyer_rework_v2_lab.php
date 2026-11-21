<?php
declare(strict_types=1);

/**
 * CI-only Landing v1 -> buyer-requested rework -> editorial v2 -> private preview.
 * Reuses Portal #1041/#1043/#1047/#1050/#1053 and the pinned Sites renderer.
 * No official identities, authorization writer, legal consent or publisher.
 */
if (getenv('APP_ENV') !== 'testing' || getenv('GITHUB_ACTIONS') !== 'true'
    || getenv('LANDING_EXACT_CLICK_LAB') !== '1'
    || getenv('LANDING_PREVIEW_GET_LAB') !== '1'
    || getenv('LANDING_SITES_RENDER_LAB') !== '1'
    || getenv('LANDING_FINAL_ACCEPT_LAB') !== '1'
    || getenv('LANDING_REWORK_V2_LAB') !== '1'
    || (PHP_SAPI !== 'cli'
        && !in_array($_SERVER['REMOTE_ADDR'] ?? '', ['127.0.0.1', '::1'], true))) {
    if (PHP_SAPI !== 'cli') http_response_code(404);
    exit(78);
}
require_once __DIR__.'/../../app/Domain/SitesIntegration/ApprovedLandingPageBriefHead.php';
require_once __DIR__.'/../../app/Domain/SitesIntegration/CurrentApprovedLandingPageBriefHeadSource.php';
require_once __DIR__.'/../../app/Domain/SitesIntegration/Pg18LandingPageApprovedBriefHeadSourceCandidate.php';

use App\Domain\SitesIntegration\Pg18LandingPageApprovedBriefHeadSourceCandidate;

const REWORK_TENANT='tenant-A';
const REWORK_BUYER='buyer-A';
const REWORK_ORDER='landing-order-001';
const REWORK_DSN='pgsql:host=127.0.0.1;port=5432;dbname=postgres';
const REWORK_V2_BYTES='{"headline":"Landing ficticia revisada apos pedido do comprador","description":"Revisao editorial sintetica e privada da mesma compra"}';

function reworkDb(string $user='postgres',string $password='ci-only'):PDO {
    return new PDO(REWORK_DSN,$user,$password,[
        PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_EMULATE_PREPARES=>false,
    ]);
}
function reworkDeny(int $status=403):never {
    http_response_code($status);
    header('Content-Type: text/plain; charset=utf-8');
    echo "LANDING REWORK LAB DENIED\n";
    exit;
}
function reworkEsc(string $s):string {
    return htmlspecialchars($s,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');
}
function reworkRow(PDO $db):array {
    $s=$db->prepare(<<<'SQL'
SELECT o.tenant_ref,o.buyer_ref,o.order_ref,o.site_ref,o.contract_ref,
       o.terms_revision,o.terms_sha256,o.terms_bytes,
       v.brief_version,v.brief_sha256,v.html_bytes,v.html_sha256,
       v.artifact_generation
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
     JOIN landing_exact_click_lab.presentations p ON p.challenge_ref=a.challenge_ref
     WHERE a.tenant_ref=o.tenant_ref AND a.buyer_ref=o.buyer_ref
       AND a.order_ref=o.order_ref AND a.contract_ref=o.contract_ref
       AND a.terms_revision=o.terms_revision AND a.terms_sha256=o.terms_sha256
       AND p.challenge_ref=a.challenge_ref AND p.consumed_at IS NOT NULL
       AND a.explicit_accept=true
  )
SQL);
    $s->execute([REWORK_TENANT,REWORK_BUYER,REWORK_ORDER]);
    $rows=$s->fetchAll(PDO::FETCH_ASSOC);
    if (count($rows)!==1) throw new RuntimeException('No exact clicked Landing order');
    $r=$rows[0];
    if ((int)$r['terms_revision']!==2
        || !hash_equals(hash('sha256',$r['terms_bytes']),trim($r['terms_sha256']))
        || !hash_equals(hash('sha256',$r['html_bytes']),trim($r['html_sha256']))) {
        throw new RuntimeException('Clicked terms or HTML bytes diverged');
    }
    return $r;
}
function reworkHead(array $r):?object {
    return (new Pg18LandingPageApprovedBriefHeadSourceCandidate(
        reworkDb('landing_bridge_ci_reader','synthetic-bridge-only')
    ))->readCurrent(REWORK_TENANT,REWORK_ORDER,trim($r['site_ref']));
}
function reworkCurrentV1(PDO $db):array {
    $r=reworkRow($db);
    $head=reworkHead($r);
    if (!$head || $head->sourceVersion!==1 || (int)$r['brief_version']!==1
        || (int)$r['artifact_generation']!==1
        || !hash_equals($head->sourceSha256,trim($r['brief_sha256']))
        || !hash_equals($head->sourceSha256,hash('sha256',$head->sourceBytes()))
        || $head->contractRef!==$r['contract_ref']
        || $head->contractRevision!==(int)$r['terms_revision']
        || $head->siteRef!==trim($r['site_ref'])) {
        throw new RuntimeException('Current approved v1 unavailable');
    }
    return $r;
}
function reworkRequest(PDO $db):array {
    $s=$db->prepare('SELECT * FROM landing_exact_click_lab.rework_requests WHERE tenant_ref=? AND buyer_ref=? AND order_ref=?');
    $s->execute([REWORK_TENANT,REWORK_BUYER,REWORK_ORDER]);
    $r=$s->fetch(PDO::FETCH_ASSOC);
    if (!$r) throw new RuntimeException('Buyer did not request rework');
    return $r;
}
if (PHP_SAPI==='cli') {
    $mode=$argv[1]??'';
    $db=reworkDb();
    if ($mode==='init') {
        reworkCurrentV1($db);
        $db->exec(<<<'SQL'
CREATE TABLE landing_exact_click_lab.rework_presentations (
 challenge_ref char(32) PRIMARY KEY, csrf_sha256 char(64) NOT NULL,
 session_sha256 char(64) NOT NULL, tenant_ref text NOT NULL, buyer_ref text NOT NULL,
 order_ref text NOT NULL, brief_version integer NOT NULL, brief_sha256 char(64) NOT NULL,
 html_sha256 char(64) NOT NULL, artifact_generation integer NOT NULL,
 expires_at timestamptz NOT NULL DEFAULT (clock_timestamp()+interval '10 minutes'),
 consumed_at timestamptz
);
CREATE TABLE landing_exact_click_lab.rework_requests (
 request_ref char(32) PRIMARY KEY, challenge_ref char(32) NOT NULL UNIQUE
 REFERENCES landing_exact_click_lab.rework_presentations(challenge_ref),
 tenant_ref text NOT NULL, buyer_ref text NOT NULL, order_ref text NOT NULL,
 site_ref uuid NOT NULL, contract_ref text NOT NULL, contract_revision integer NOT NULL,
 prior_brief_version integer NOT NULL, prior_brief_sha256 char(64) NOT NULL,
 prior_html_sha256 char(64) NOT NULL, prior_artifact_generation integer NOT NULL,
 requested_change text NOT NULL CHECK(requested_change='clarify-benefits'),
 requested_at timestamptz NOT NULL DEFAULT clock_timestamp(),
 UNIQUE(tenant_ref,buyer_ref,order_ref)
);
CREATE TABLE landing_exact_click_lab.rework_editorial_audit (
 event_order bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
 request_ref char(32) NOT NULL REFERENCES landing_exact_click_lab.rework_requests(request_ref),
 event_kind text NOT NULL CHECK(event_kind IN ('draft_submitted','reviewer_approved')),
 actor_ref text NOT NULL,
 source_version integer NOT NULL, source_sha256 char(64) NOT NULL,
 created_at timestamptz NOT NULL DEFAULT clock_timestamp(),
 UNIQUE(request_ref,event_kind)
);
REVOKE ALL ON landing_exact_click_lab.rework_presentations,
 landing_exact_click_lab.rework_requests,
 landing_exact_click_lab.rework_editorial_audit FROM PUBLIC;
SQL);
        echo "LANDING REWORK V2 LAB INIT: BUYER_REQUEST_REQUIRED; NO_GO\n";
        exit;
    }
    if ($mode==='readback') {
        $q=$db->query(<<<'SQL'
SELECT q.prior_brief_version,q.prior_brief_sha256,q.prior_html_sha256,
       q.requested_change,r.source_version,r.source_sha256,r.state,
       (SELECT count(*) FROM landing_exact_click_lab.rework_editorial_audit a
          WHERE a.request_ref=q.request_ref AND a.event_kind='draft_submitted') AS drafted,
       (SELECT count(*) FROM landing_exact_click_lab.rework_editorial_audit a
          WHERE a.request_ref=q.request_ref AND a.event_kind='reviewer_approved') AS reviewed
FROM landing_exact_click_lab.rework_requests q
LEFT JOIN portal_landing_candidate.brief_revisions r
  ON r.tenant_ref=q.tenant_ref AND r.order_ref=q.order_ref
 AND r.site_ref=q.site_ref AND r.source_version=2
SQL);
        $row=$q->fetch(PDO::FETCH_ASSOC);
        echo json_encode([
            'requested'=>(bool)$row,'requestKind'=>$row['requested_change']??null,
            'v1Digest'=>isset($row['prior_brief_sha256'])?trim($row['prior_brief_sha256']):null,
            'v2Digest'=>isset($row['source_sha256'])?trim($row['source_sha256']):null,
            'v2State'=>$row['state']??null,
            'drafted'=>(int)($row['drafted']??0),
            'reviewed'=>(int)($row['reviewed']??0),
            'operational'=>false,
        ],JSON_THROW_ON_ERROR)."\n";
        exit;
    }
    if ($mode==='draft-v2') {
        $q=reworkRequest($db);
        $r=reworkCurrentV1($db);
        if ($q['site_ref']!==$r['site_ref']
            || $q['contract_ref']!==$r['contract_ref']
            || (int)$q['prior_brief_version']!==(int)$r['brief_version']
            || !hash_equals(trim($q['prior_brief_sha256']),trim($r['brief_sha256']))
            || !hash_equals(trim($q['prior_html_sha256']),trim($r['html_sha256']))
            || (int)$q['prior_artifact_generation']!==(int)$r['artifact_generation'])
            throw new RuntimeException('V1 request drifted');
        $digest=hash('sha256',REWORK_V2_BYTES);
        $db->beginTransaction();
        $ins=$db->prepare(<<<'SQL'
INSERT INTO portal_landing_candidate.brief_revisions
(tenant_ref,order_ref,site_ref,contract_ref,contract_revision,source_version,
 source_sha256,source_bytes,state,approval_ref,approval_epoch,approved_actor_ref)
VALUES (?,?,?,?,?,2,?,?,'draft','fixture-approval-2',2,'fictional-reviewer-B')
SQL);
        $ins->execute([REWORK_TENANT,REWORK_ORDER,trim($r['site_ref']),
            $r['contract_ref'],(int)$r['terms_revision'],$digest,REWORK_V2_BYTES]);
        $event=$db->prepare('INSERT INTO landing_exact_click_lab.rework_editorial_audit(request_ref,event_kind,actor_ref,source_version,source_sha256) VALUES (?, ?, ?, 2, ?)');
        $event->execute([trim($q['request_ref']),'draft_submitted','fictional-editor-A',$digest]);
        $db->commit();
        if (reworkHead($r)!==null) throw new RuntimeException('Draft v2 MUST hide v1 approval HEAD');
        echo "LANDING REWORK V2 DRAFT: BUYER_REQUEST_LINKED; PREVIEW=DENIED; NO_GO\n";
        exit;
    }
    if ($mode==='approve-v2') {
        $q=reworkRequest($db);
        $db->beginTransaction();
        $s=$db->prepare(<<<'SQL'
UPDATE portal_landing_candidate.brief_revisions
 SET state='approved'
 WHERE tenant_ref=? AND order_ref=? AND site_ref=?
   AND source_version=2 AND state='draft' AND approval_epoch=2
   AND source_sha256=?
SQL);
        $digest=hash('sha256',REWORK_V2_BYTES);
        $s->execute([REWORK_TENANT,REWORK_ORDER,trim($q['site_ref']),$digest]);
        if ($s->rowCount()!==1) throw new RuntimeException('Draft CAS or digest mismatch');
        $audit=$db->prepare('INSERT INTO landing_exact_click_lab.rework_editorial_audit(request_ref,event_kind,actor_ref,source_version,source_sha256) VALUES (?, ?, ?, 2, ?)');
        $audit->execute([trim($q['request_ref']),'reviewer_approved','fictional-reviewer-B',$digest]);
        $approved=$db->prepare(<<<'SQL'
INSERT INTO portal_landing_candidate.approval_events
(event_ref,tenant_ref,order_ref,site_ref,source_version,approval_epoch,
 approval_ref,actor_ref,event_type)
VALUES('fictional-v2-approved',?,?,?,2,2,'fixture-approval-2',
 'fictional-reviewer-B','approved')
SQL);
        $approved->execute([REWORK_TENANT,REWORK_ORDER,trim($q['site_ref'])]);
        $db->commit();
        $r=reworkRow($db);
        $h=reworkHead($r);
        if (!$h || $h->sourceVersion!==2 || !hash_equals($digest,$h->sourceSha256))
            throw new RuntimeException('Approved v2 not independently readable');
        echo "LANDING REWORK V2 APPROVED FIXTURE: DISTINCT_EDITOR_REVIEWER; NO_GO\n";
        exit;
    }
    if ($mode==='render-v2') {
        $q=reworkRequest($db);
        $r=reworkRow($db);
        $head=reworkHead($r);
        if (!$head || $head->sourceVersion!==2 || $head->approvalEpoch!==2
            || $head->tenantRef!==REWORK_TENANT || $head->orderRef!==REWORK_ORDER
            || $head->siteRef!==trim($r['site_ref'])
            || $head->contractRef!==$r['contract_ref']
            || $head->contractRevision!==(int)$r['terms_revision']
            || (int)$r['brief_version']!==1 || (int)$r['artifact_generation']!==1
            || !hash_equals(trim($q['prior_brief_sha256']),trim($r['brief_sha256']))
            || !hash_equals(trim($q['prior_html_sha256']),trim($r['html_sha256']))
            || !hash_equals($head->sourceSha256,hash('sha256',$head->sourceBytes())))
            throw new RuntimeException('Unapproved or stale v2 source');
        $audit=$db->prepare('SELECT event_kind,actor_ref FROM landing_exact_click_lab.rework_editorial_audit WHERE request_ref=? ORDER BY event_order');
        $audit->execute([trim($q['request_ref'])]);
        if ($audit->fetchAll(PDO::FETCH_KEY_PAIR)!==[
            'draft_submitted'=>'fictional-editor-A',
            'reviewer_approved'=>'fictional-reviewer-B'])
            throw new RuntimeException('Editor/reviewer sequence missing');
        $dir=getenv('GITHUB_WORKSPACE').'/sites-snapshot/src/Production/';
        if (!is_file($dir.'StaticSiteRenderer.php')) throw new RuntimeException('Pinned Sites checkout absent');
        foreach (['SiteBrief','ReleaseArtifact','StructuredCopyCandidate','StaticSiteRenderer'] as $file)
            require_once $dir.$file.'.php';
        $copy=json_decode($head->sourceBytes(),true,8,JSON_THROW_ON_ERROR);
        if (!is_array($copy) || array_keys($copy)!==['headline','description']
            || !is_string($copy['headline']) || !is_string($copy['description']))
            throw new RuntimeException('V2 source format changed');
        $proposal=['schemaVersion'=>'sites.ai-copy.v1',
            'headline'=>$copy['headline'],'description'=>$copy['description'],
            'callToAction'=>'Conheça a demonstração fictícia',
            'benefits'=>['Informações claras para um cenário fictício',
                'Exibição adaptada a dispositivos móveis',
                'Revisão técnica privada sem publicação']];
        $site=\Imagine\Sites\Production\StructuredCopyCandidate::assemble(
            'landing_page','Marca Fictícia Imagine',$proposal);
        $html=(new \Imagine\Sites\Production\StaticSiteRenderer())->render($site);
        if ($html->sizeBytes>32768
            || !hash_equals($html->digest,hash('sha256',$html->html))
            || !str_contains($html->html,'data-site-product="landing-page"')
            || str_contains($html->html,'<form') || str_contains($html->html,'<script')
            || str_contains($html->html,'https://'))
            throw new RuntimeException('Invalid private Sites v2 HTML');
        $s=$db->prepare(<<<'SQL'
UPDATE landing_exact_click_lab.preview_artifacts
 SET brief_version=2,brief_sha256=?,html_bytes=?,html_sha256=?,
     artifact_generation=artifact_generation+1
 WHERE tenant_ref=? AND buyer_ref=? AND order_ref=? AND site_ref=?
   AND brief_version=1 AND brief_sha256=? AND html_sha256=?
   AND artifact_generation=1
SQL);
        $s->execute([$head->sourceSha256,$html->html,$html->digest,
            REWORK_TENANT,REWORK_BUYER,REWORK_ORDER,trim($r['site_ref']),
            trim($q['prior_brief_sha256']),trim($q['prior_html_sha256'])]);
        if ($s->rowCount()!==1) throw new RuntimeException('Rendering CAS rejected');
        echo "LANDING REWORK V2 SITES REAL RENDER PASS: GENERATION=2\n";
        echo "V2_BRIEF_SHA256=".$head->sourceSha256." V2_HTML_SHA256=".$html->digest."\n";
        echo "PUBLICATION=NO_GO; AUTH=LAB_ONLY; APPROVAL=FIXTURE_ONLY\n";
        exit;
    }
    exit(64);
}

header('Cache-Control: private, no-store, max-age=0');
header('Content-Security-Policy: default-src \'none\'; style-src \'unsafe-inline\'; form-action \'self\'; base-uri \'none\'; frame-ancestors \'none\'');
header('X-Content-Type-Options: nosniff');
ini_set('session.use_strict_mode','1');
ini_set('session.cookie_httponly','1');
ini_set('session.cookie_samesite','Strict');
session_start();
if (($_SESSION['tenant']??null)!==REWORK_TENANT
    || ($_SESSION['buyer']??null)!==REWORK_BUYER) reworkDeny();
try {
    $db=reworkDb();
    $path=parse_url($_SERVER['REQUEST_URI']??'',PHP_URL_PATH);
    $method=$_SERVER['REQUEST_METHOD']??'';
    if ($path==='/lab/request-review' && $method==='GET') {
        $r=reworkCurrentV1($db);
        $challenge=bin2hex(random_bytes(16));
        $csrf=bin2hex(random_bytes(32));
        $insert=$db->prepare(<<<'SQL'
INSERT INTO landing_exact_click_lab.rework_presentations
(challenge_ref,csrf_sha256,session_sha256,tenant_ref,buyer_ref,order_ref,
 brief_version,brief_sha256,html_sha256,artifact_generation)
VALUES(?,?,?,?,?,?,?,?,?,?)
SQL);
        $insert->execute([$challenge,hash('sha256',$csrf),
            hash('sha256',session_id()),REWORK_TENANT,REWORK_BUYER,REWORK_ORDER,
            (int)$r['brief_version'],trim($r['brief_sha256']),
            trim($r['html_sha256']),(int)$r['artifact_generation']]);
        header('Content-Type: text/html; charset=utf-8');
        echo '<!doctype html><html lang="pt-BR"><meta charset="utf-8"><title>Pedido de ajuste Landing — teste</title>';
        echo '<h1>Solicitar ajuste fictício</h1><p id="order">'.reworkEsc(REWORK_ORDER).'</p>';
        echo '<p id="brief-version">'.(int)$r['brief_version'].'</p>';
        echo '<p id="html-digest">'.reworkEsc(trim($r['html_sha256'])).'</p>';
        echo '<form method="post" action="/lab/request-rework">';
        foreach (['challenge'=>$challenge,'csrf'=>$csrf,'htmlDigest'=>trim($r['html_sha256']),
            'briefVersion'=>(string)$r['brief_version']] as $key=>$val)
            echo '<input type="hidden" name="'.reworkEsc($key).'" value="'.reworkEsc($val).'">';
        echo '<label><input id="confirm-rework" type="checkbox" name="confirm" value="yes" required> ';
        echo 'Solicito ajuste de clareza na versão privada</label>';
        echo '<input type="hidden" name="kind" value="clarify-benefits">';
        echo '<button id="request-rework" type="submit">Solicitar ajuste fictício</button>';
        echo '</form></html>';
        exit;
    }
    if ($path==='/lab/request-rework' && $method==='POST') {
        $challenge=$_POST['challenge']??null;
        $csrf=$_POST['csrf']??null;
        $digest=$_POST['htmlDigest']??null;
        if (!is_string($challenge) || !preg_match('/\A[a-f0-9]{32}\z/D',$challenge)
            || !is_string($csrf) || !preg_match('/\A[a-f0-9]{64}\z/D',$csrf)
            || !is_string($digest) || !preg_match('/\A[a-f0-9]{64}\z/D',$digest)
            || ($_POST['briefVersion']??null)!=='1'
            || ($_POST['kind']??null)!=='clarify-benefits'
            || ($_POST['confirm']??null)!=='yes') reworkDeny(409);
        $db->beginTransaction();
        $s=$db->prepare('SELECT * FROM landing_exact_click_lab.rework_presentations WHERE challenge_ref=? FOR UPDATE');
        $s->execute([$challenge]);
        $p=$s->fetch(PDO::FETCH_ASSOC);
        $r=reworkCurrentV1($db);
        if (!$p || $p['consumed_at']!==null || strtotime($p['expires_at'])<=time()
            || $p['tenant_ref']!==REWORK_TENANT || $p['buyer_ref']!==REWORK_BUYER
            || $p['order_ref']!==REWORK_ORDER || (int)$p['brief_version']!==1
            || !hash_equals(trim($p['csrf_sha256']),hash('sha256',$csrf))
            || !hash_equals(trim($p['session_sha256']),hash('sha256',session_id()))
            || !hash_equals(trim($p['brief_sha256']),trim($r['brief_sha256']))
            || !hash_equals(trim($p['html_sha256']),$digest)
            || !hash_equals(trim($r['html_sha256']),$digest)
            || (int)$p['artifact_generation']!==(int)$r['artifact_generation']) {
            $db->rollBack();reworkDeny(409);
        }
        $s=$db->prepare('UPDATE landing_exact_click_lab.rework_presentations SET consumed_at=clock_timestamp() WHERE challenge_ref=? AND consumed_at IS NULL');
        $s->execute([$challenge]);
        if ($s->rowCount()!==1) {$db->rollBack();reworkDeny(409);}
        $s=$db->prepare(<<<'SQL'
INSERT INTO landing_exact_click_lab.rework_requests
(request_ref,challenge_ref,tenant_ref,buyer_ref,order_ref,site_ref,
 contract_ref,contract_revision,prior_brief_version,prior_brief_sha256,
 prior_html_sha256,prior_artifact_generation,requested_change)
VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?)
SQL);
        $s->execute([bin2hex(random_bytes(16)),$challenge,REWORK_TENANT,
            REWORK_BUYER,REWORK_ORDER,trim($r['site_ref']),$r['contract_ref'],
            (int)$r['terms_revision'],1,trim($r['brief_sha256']),
            $digest,(int)$r['artifact_generation'],'clarify-benefits']);
        $db->commit();
        header('Content-Type: text/plain; charset=utf-8');
        echo "LANDING LAB BUYER REWORK REQUEST PERSISTED; NO_GO\n";
        exit;
    }
    reworkDeny(404);
} catch(Throwable) {
    if (isset($db) && $db->inTransaction()) $db->rollBack();
    reworkDeny(409);
}
