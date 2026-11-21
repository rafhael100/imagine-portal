<?php
declare(strict_types=1);

/**
 * R29: CI-only buyer-scoped receipt and local delivery link. Never a public
 * publisher, native Portal identity, operational signed receipt or deployed URL.
 * Release state MUST be independently read from the R28 mock after its lost ACK.
 */
if (!in_array(PHP_SAPI, ['cli', 'cli-server'], true)
    || getenv('GITHUB_ACTIONS') !== 'true'
    || getenv('APP_ENV') !== 'testing'
    || getenv('LANDING_R29_DELIVERY_LAB') !== '1'
    || getenv('LANDING_R28_ACK_LAB') !== '1'
    || getenv('LANDING_V2_MTLS_LAB') !== '1') exit(78);
if (PHP_SAPI === 'cli-server'
    && !in_array($_SERVER['REMOTE_ADDR'] ?? '', ['127.0.0.1', '::1'], true)) {
    http_response_code(404); exit;
}
define('LANDING_R27_READ_ONLY_LIBRARY', true);
require_once __DIR__.'/landing_v2_mtls_release_lab.php';

const R29_REQUEST = 'ci-landing-v2-probe-001';
const R29_RELEASE = R26_RELEASE;
const R29_STATE_URL = 'http://127.0.0.1:8791/ci/state?tenantRef=tenant-A&orderRef=landing-order-001&releaseRef=landing-v2-generation-2-ci';
const R29_HTML_URL = 'http://127.0.0.1:8791/ci/served';

function r29db(string $user='postgres'): PDO {
    return r26db($user, $user==='postgres' ? 'ci-only' : 'ci-synthetic-not-operational');
}
function r29http(string $url): ?array {
    // Fixed loopback endpoints only; no user-provided URL or redirects.
    if (!in_array($url, [R29_STATE_URL, R29_HTML_URL], true)) return null;
    $h=curl_init($url);
    curl_setopt_array($h, [
        CURLOPT_RETURNTRANSFER=>true, CURLOPT_FOLLOWLOCATION=>false,
        CURLOPT_MAXREDIRS=>0, CURLOPT_PROXY=>'',
        CURLOPT_CONNECTTIMEOUT_MS=>500, CURLOPT_TIMEOUT_MS=>2000
    ]);
    $data=curl_exec($h);
    $status=(int)curl_getinfo($h, CURLINFO_RESPONSE_CODE);
    curl_close($h);
    return $status===200 && is_string($data) ? [$data,$status] : null;
}
function r29source(): ?array {
    $e=r26evidence(); // current clicked v2 and current approved briefing, not historical ACK
    if ($e===null) return null;
    $q=r29db('landing_r28_reader')->prepare(<<<'SQL'
SELECT tenant_ref,buyer_ref,order_ref,site_ref,release_ref,html_sha256,
       request_ref,deadline_epoch,delivery_state,side_effects,html_bytes
FROM landing_ack_r28.release_state
WHERE tenant_ref=? AND order_ref=? AND request_ref=?
SQL);
    $q->execute([R26_TENANT,R26_ORDER,R29_REQUEST]);
    $states=$q->fetchAll(PDO::FETCH_ASSOC);
    if(count($states)!==1) return null;
    $s=$states[0];
    if($s['tenant_ref']!==R26_TENANT || $s['buyer_ref']!==R26_BUYER
        || $s['order_ref']!==R26_ORDER || $s['site_ref']!==trim((string)$e['site_ref'])
        || $s['release_ref']!==R29_RELEASE || $s['request_ref']!==R29_REQUEST
        || $s['delivery_state']!=='APPLIED' || (int)$s['side_effects']!==1
        || (int)$s['deadline_epoch']<=0
        || !hash_equals((string)$e['html_sha256'],(string)$s['html_sha256'])
        || !hash_equals(hash('sha256',(string)$s['html_bytes']), (string)$s['html_sha256'])) return null;
    $state=r29http(R29_STATE_URL);
    $served=r29http(R29_HTML_URL);
    if (!$state || !$served || strlen($served[0])>32768) return null;
    try { $reply=json_decode($state[0],true,8,JSON_THROW_ON_ERROR); }
    catch(Throwable) { return null; }
    if (!is_array($reply)
        || ($reply['state']??null)!=='APPLIED'
        || ($reply['sideEffects']??null)!==1
        || ($reply['tenantRef']??null)!==R26_TENANT
        || ($reply['orderRef']??null)!==R26_ORDER
        || ($reply['requestRef']??null)!==R29_REQUEST
        || ($reply['releaseRef']??null)!==R29_RELEASE
        || ($reply['deadlineEpoch']??null)!==(int)$s['deadline_epoch']
        || ($reply['artifactDigest']??null)!==(string)$s['html_sha256']
        || !hash_equals(hash('sha256',$served[0]),(string)$s['html_sha256'])
        || !hash_equals($served[0],(string)$e['html_bytes'])
        || !hash_equals($served[0],(string)$s['html_bytes'])
        || stripos($served[0],'<form')!==false
        || stripos($served[0],'<script')!==false) return null;
    // Re-check source after provider observation; never treat an ACK as consent.
    $latest=r26evidence();
    if($latest===null
       || !hash_equals((string)$latest['html_sha256'],(string)$e['html_sha256'])
       || !hash_equals((string)$latest['brief_sha256'],(string)$e['brief_sha256'])
       || (string)$latest['challenge_ref']!==(string)$e['challenge_ref']) return null;
    return ['e'=>$e,'s'=>$s,'bytes'=>$served[0]];
}
function r29receipt(?string $ref=null): ?array {
    $snapshot=r29source();
    if ($snapshot===null) return null;
    $db=r29db('landing_r29_reader');
    $sql=<<<'SQL'
SELECT receipt_ref,tenant_ref,buyer_ref,order_ref,site_ref,release_ref,
       html_sha256,brief_sha256,artifact_generation,acceptance_ref,
       request_ref,issued_at,expires_at
FROM landing_delivery_r29.receipts
WHERE tenant_ref=? AND buyer_ref=? AND order_ref=? AND release_ref=?
SQL;
    if ($ref!==null) $sql.=' AND receipt_ref=?';
    $q=$db->prepare($sql);
    $params=[R26_TENANT,R26_BUYER,R26_ORDER,R29_RELEASE];
    if($ref!==null) $params[]=$ref;
    $q->execute($params);
    $records=$q->fetchAll(PDO::FETCH_ASSOC);
    if(count($records)!==1) return null;
    $r=$records[0];$e=$snapshot['e'];
    if($r['site_ref']!==trim((string)$e['site_ref'])
        || $r['acceptance_ref']!==(string)$e['challenge_ref']
        || $r['request_ref']!==R29_REQUEST
        || (int)$r['artifact_generation']!==(int)$e['artifact_generation']
        || !hash_equals((string)$r['html_sha256'],(string)$e['html_sha256'])
        || !hash_equals((string)$r['brief_sha256'],(string)$e['brief_sha256'])
        || strtotime((string)$r['expires_at'])<=time()) return null;
    return ['receipt'=>$r,'snapshot'=>$snapshot];
}
function r29deny():never {
    http_response_code(403);
    header('Content-Type: text/plain; charset=utf-8');
    header('Cache-Control: no-store');
    echo "NO_CURRENT_BUYER_DELIVERY\n";exit;
}
function r29escape(string $text):string {
    return htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

if(PHP_SAPI==='cli-server') {
    header('Cache-Control: private, no-store, max-age=0');
    header('X-Content-Type-Options: nosniff');
    header('X-Frame-Options: DENY');
    header('Referrer-Policy: no-referrer');
    header('X-Robots-Tag: noindex, nofollow');
    header('X-Landing-Lab-Only: no-go-fictitious-buyer-delivery');
    header("Content-Security-Policy: default-src 'none'; style-src 'unsafe-inline'; frame-ancestors 'none'; base-uri 'none'; form-action 'none'");
    if(($_SERVER['REQUEST_METHOD']??'')!=='GET')r29deny();
    $path=parse_url((string)($_SERVER['REQUEST_URI']??''),PHP_URL_PATH);
    if(!in_array($path,['/lab/delivery','/lab/delivery/html'],true))r29deny();
    ini_set('session.use_strict_mode','1');
    ini_set('session.cookie_httponly','1');
    ini_set('session.cookie_samesite','Strict');
    session_start();
    $tenant=$_SESSION['tenant']??null;$buyer=$_SESSION['buyer']??null;
    session_write_close();
    if($tenant!==R26_TENANT || $buyer!==R26_BUYER)r29deny();
    try {
        $ref=null;
        if($path==='/lab/delivery/html') {
            $ref=$_GET['ref']??null;
            if(!is_string($ref) || preg_match('/\A[a-f0-9]{32}\z/D',$ref)!==1)r29deny();
        } else if(($_GET??[])!==[])r29deny();
        $record=r29receipt($ref);
        if(!$record)r29deny();
        $r=$record['receipt'];
        if($path==='/lab/delivery/html') {
            header('Content-Type: text/html; charset=utf-8');
            echo $record['snapshot']['bytes'];exit;
        }
        header('Content-Type: text/html; charset=utf-8');
        echo '<!doctype html><html lang="pt-BR"><meta charset="utf-8"><title>Entrega local fictícia Landing</title>';
        echo '<h1>Entrega privada de laboratório — não é publicação</h1>';
        echo '<p id="order">'.r29escape($r['order_ref']).'</p>';
        echo '<p id="release">'.r29escape($r['release_ref']).'</p>';
        echo '<p id="digest">'.r29escape($r['html_sha256']).'</p>';
        echo '<p id="generation">'.(int)$r['artifact_generation'].'</p>';
        echo '<a id="download" href="/lab/delivery/html?ref='.r29escape($r['receipt_ref']).'">Ver HTML local deste pedido</a>';
        echo '<p>Somente CI descartável. Formulário desligado, sem URL pública, sem autorização de publicação.</p></html>';
        exit;
    } catch(Throwable) {r29deny();}
}

$mode=$argv[1]??'';
if($mode==='init') {
    r29db()->exec(<<<'SQL'
CREATE SCHEMA landing_delivery_r29;
CREATE TABLE landing_delivery_r29.receipts (
 receipt_ref char(32) PRIMARY KEY,tenant_ref text NOT NULL,buyer_ref text NOT NULL,
 order_ref text NOT NULL,site_ref text NOT NULL,release_ref text NOT NULL,
 html_sha256 char(64) NOT NULL,brief_sha256 char(64) NOT NULL,
 artifact_generation integer NOT NULL,acceptance_ref char(32) NOT NULL,
 request_ref text NOT NULL,issued_at timestamptz NOT NULL DEFAULT CURRENT_TIMESTAMP,
 expires_at timestamptz NOT NULL DEFAULT (CURRENT_TIMESTAMP+INTERVAL '10 minutes'),
 UNIQUE(tenant_ref,buyer_ref,order_ref,release_ref)
);
CREATE ROLE landing_r29_reader LOGIN PASSWORD 'ci-synthetic-not-operational';
REVOKE ALL ON SCHEMA landing_delivery_r29 FROM PUBLIC;
GRANT USAGE ON SCHEMA landing_delivery_r29 TO landing_r29_reader;
GRANT SELECT ON landing_delivery_r29.receipts TO landing_r29_reader;
ALTER TABLE landing_delivery_r29.receipts ENABLE ROW LEVEL SECURITY;
ALTER TABLE landing_delivery_r29.receipts FORCE ROW LEVEL SECURITY;
CREATE POLICY buyer_receipt ON landing_delivery_r29.receipts FOR SELECT TO landing_r29_reader
 USING(tenant_ref='tenant-A' AND buyer_ref='buyer-A'
 AND order_ref='landing-order-001' AND release_ref='landing-v2-generation-2-ci');
SQL);
    echo "R29_TEST_ONLY_RECEIPT_STORAGE_READY; NO_PUBLIC_URL\n";exit;
}
if($mode==='issue') {
    $s=r29source();
    if($s===null)throw new RuntimeException('No independent applied state and served bytes: cannot issue receipt');
    $e=$s['e'];$ref=bin2hex(random_bytes(16));
    $q=r29db()->prepare(<<<'SQL'
INSERT INTO landing_delivery_r29.receipts
(receipt_ref,tenant_ref,buyer_ref,order_ref,site_ref,release_ref,
 html_sha256,brief_sha256,artifact_generation,acceptance_ref,request_ref)
VALUES(?,?,?,?,?,?,?,?,?,?,?)
SQL);
    $q->execute([$ref,R26_TENANT,R26_BUYER,R26_ORDER,trim((string)$e['site_ref']),
        R29_RELEASE,(string)$e['html_sha256'],(string)$e['brief_sha256'],
        (int)$e['artifact_generation'],(string)$e['challenge_ref'],R29_REQUEST]);
    if(!r29receipt($ref))throw new RuntimeException('Independent receipt readback failed');
    echo "R29_ISSUED_AFTER_INDEPENDENT_RELEASE_SHA=".(string)$e['html_sha256']."; LAB_ONLY\n";exit;
}
if($mode==='readback') {
    $r=r29receipt();
    if(!$r)throw new RuntimeException('Readback cannot verify release and buyer receipt');
    echo "R29_INDEPENDENT_RECEIPT_READBACK; ORDER=".R26_ORDER.
        "; GENERATION=".$r['receipt']['artifact_generation'].
        "; SHA=".$r['receipt']['html_sha256'].
        "; PUBLIC=NO; OPERATIONAL=NO\n";exit;
}
if($mode==='tamper' || $mode==='restore') {
    $e=r26evidence();
    if(!$e)throw new RuntimeException('Current synthetic v2 absent');
    $bytes=$mode==='tamper'?(string)$e['html_bytes']."<!-- tamper CI only -->":(string)$e['html_bytes'];
    $q=r29db()->prepare(<<<'SQL'
UPDATE landing_ack_r28.release_state SET html_bytes=?
WHERE tenant_ref=? AND order_ref=? AND request_ref=?
 AND delivery_state='APPLIED' AND side_effects=1
SQL);
    $q->execute([$bytes,R26_TENANT,R26_ORDER,R29_REQUEST]);
    if($q->rowCount()!==1)throw new RuntimeException('Cannot isolate tamper');
    echo "R29_".strtoupper($mode)."_LOCAL_MOCK_ONLY\n";exit;
}
if($mode==='readback-revoked') {
    if(r26evidence()!==null || r29receipt()!==null)
        throw new RuntimeException('Revoked briefing must suppress prior receipt');
    echo "R29_REVOKED_SOURCE_DENIES_DELIVERY; NO_GO\n";exit;
}
exit(64);
