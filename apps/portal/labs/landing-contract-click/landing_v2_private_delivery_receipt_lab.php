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
    // R33: enforce the transport budget *during* streaming, not after
    // curl_exec has already buffered an untrusted mock provider response.
    $limit=$url===R29_STATE_URL?4096:32768;
    // R36: a 200 response with the right bytes but a different media type
    // is not an observed browser-renderable release or canonical state source.
    $expectedMime=$url===R29_STATE_URL?'application/json':'text/html; charset=utf-8';
    $mimeHeaders=[];
    // R37: Content-Disposition and Refresh change what a browser observes,
    // even with a 200 status, correct media type and identical response bytes.
    $presentationHeaders=[];
    // R39: bytes hashed by the server must be the bytes a browser would
    // render. A misleading Content-Encoding can make them differ.
    $representationHeaders=[];
    $data='';
    $h=curl_init($url);
    curl_setopt_array($h, [
        CURLOPT_RETURNTRANSFER=>false, CURLOPT_FOLLOWLOCATION=>false,
        CURLOPT_MAXREDIRS=>0, CURLOPT_PROXY=>'',
        CURLOPT_CONNECTTIMEOUT_MS=>500, CURLOPT_TIMEOUT_MS=>2000,
        CURLOPT_HEADERFUNCTION=>static function($handle,string $line) use (&$mimeHeaders,&$presentationHeaders,&$representationHeaders):int {
            // Reset only on a new HTTP status line. Duplicate Content-Type
            // headers within the final response are ambiguous and refused.
            if(preg_match('/\\AHTTP\\/[0-9.]+ [0-9]{3}[^\\r\\n]*\\r?\\n\\z/', $line)===1) {
                $mimeHeaders=[];
                $presentationHeaders=[];
                $representationHeaders=[];
            } else if(preg_match('/\\Acontent-type:[ \\t]*([^\\r\\n]+)\\r?\\n\\z/i',$line,$m)===1) {
                $mimeHeaders[]=trim($m[1]);
            } else if(preg_match('/\\A(?:content-disposition|refresh):[^\\r\\n]*\\r?\\n\\z/i',$line)===1) {
                $presentationHeaders[]=trim($line);
            } else if(preg_match('/\\Acontent-encoding:[^\\r\\n]*\\r?\\n\\z/i', $line)===1) {
                // R28 has no content coding; refuse even identity to keep
                // the exact approved HTML and HTTP representation aligned.
                $representationHeaders[]=trim($line);
            }
            return strlen($line);
        },
        CURLOPT_WRITEFUNCTION=>static function($handle,string $chunk) use (&$data,$limit):int {
            $length=strlen($chunk);
            if($length>$limit-strlen($data))return 0; // abort transfer
            $data.=$chunk;
            return $length;
        },
    ]);
    $ok=curl_exec($h);
    $status=(int)curl_getinfo($h,CURLINFO_RESPONSE_CODE);
    curl_close($h);
    return $ok===true && $status===200 && $mimeHeaders===[$expectedMime]
        && $presentationHeaders===[] && $representationHeaders===[]
        ? [$data,$status] : null;
}
/**
 * R35: the R28 provider emits one canonical state envelope. A JSON object
 * with duplicate fields, a second authority flag, reordered fields, or
 * strings in place of integers must not silently become publication evidence.
 * This is only a shape check; independent PG18 and HTML SHA checks still apply.
 */
function r29decodeState(string $raw): ?array {
    try {
        $value=json_decode($raw,true,8,JSON_THROW_ON_ERROR);
        if(!is_array($value) || array_is_list($value))return null;
        if(array_keys($value)!==[
            'requestRef','tenantRef','orderRef','releaseRef',
            'artifactDigest','deadlineEpoch','state','sideEffects'
        ])return null;
        foreach(['requestRef','tenantRef','orderRef','releaseRef','artifactDigest','state'] as $field)
            if(!is_string($value[$field]))return null;
        if(!is_int($value['deadlineEpoch']) || !is_int($value['sideEffects']))
            return null;
        // Exact round trip forbids duplicate JSON members and noncanonical
        // representations before the provider's fields can authorize a receipt.
        if(json_encode($value,JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES)!==$raw)
            return null;
        return $value;
    } catch(Throwable) { return null; }
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
    // R38: an APPLIED read before HTML is not sufficient if the provider
    // changes current release state while the bytes are being observed.
    // One fresh state GET, no retry or new publication dispatch.
    $stateAfter=r29http(R29_STATE_URL);
    if (!$state || !$served || !$stateAfter || strlen($served[0])>32768
        || !hash_equals($state[0],$stateAfter[0])) return null;
    $reply=r29decodeState($state[0]);
    if ($reply===null
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
  AND issued_at <= clock_timestamp() AND expires_at > clock_timestamp()
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
        || !hash_equals((string)$r['brief_sha256'],(string)$e['brief_sha256'])) return null;
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
            // R34: the private link is a single canonical receipt reference.
            // PHP otherwise collapses duplicate query keys and URL-decodes aliases,
            // which makes audit evidence ambiguous even when the SHA is correct.
            $query=(string)($_SERVER['QUERY_STRING']??'');
            if(preg_match('/\Aref=([a-f0-9]{32})\z/D',$query,$match)!==1
                || count($_GET)!==1 || ($_GET['ref']??null)!==$match[1])r29deny();
            $ref=$match[1];
        } else if(($_GET??[])!==[])r29deny();
        $record=r29receipt($ref);
        if(!$record)r29deny();
        $r=$record['receipt'];
        if($path==='/lab/delivery/html') {
            header('Content-Type: text/html; charset=utf-8');
            echo $record['snapshot']['bytes'];exit;
        }
        header('Content-Type: text/html; charset=utf-8');
        echo '<!doctype html><html lang="pt-BR"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Entrega local fictícia Landing</title>';
        // Standalone, accessible laboratory readback; never a client-facing publisher.
        echo '<style>*,*::before,*::after{box-sizing:border-box}body{max-width:46rem;margin:0 auto;padding:clamp(1rem,4vw,2rem);font:1rem/1.5 system-ui,sans-serif;color:#292929}main{min-width:0}p{overflow-wrap:anywhere}a{color:#874000;text-underline-offset:.2em;overflow-wrap:anywhere}a:focus-visible{outline:3px solid #F9941D;outline-offset:3px}</style></head><body><main>';
        echo '<h1>Entrega privada de laboratório — não é publicação</h1>';
        echo '<p>Pedido: <span id="order">'.r29escape($r['order_ref']).'</span></p>';
        echo '<p>Release: <span id="release">'.r29escape($r['release_ref']).'</span></p>';
        echo '<p>SHA-256: <span id="digest">'.r29escape($r['html_sha256']).'</span></p>';
        echo '<p>Geração: <span id="generation">'.(int)$r['artifact_generation'].'</span></p>';
        echo '<p><a id="download" href="/lab/delivery/html?ref='.r29escape($r['receipt_ref']).'">Ver HTML local deste pedido</a></p>';
        echo '<p>Somente CI descartável. Formulário desligado, sem URL pública, sem autorização de publicação.</p></main></body></html>';
        exit;
    } catch(Throwable) {r29deny();}
}

$mode=$argv[1]??'';
if($mode==='probe-state-canonical') {
    // Pure negative cases exercise the exact decoder used by HTTP readback.
    // No provider dispatch, PG mutation, buyer auth or network access.
    $valid=[
        'requestRef'=>R29_REQUEST,'tenantRef'=>R26_TENANT,
        'orderRef'=>R26_ORDER,'releaseRef'=>R29_RELEASE,
        'artifactDigest'=>str_repeat('a',64),'deadlineEpoch'=>1234567890,
        'state'=>'APPLIED','sideEffects'=>1
    ];
    $raw=json_encode($valid,JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES);
    if(r29decodeState($raw)!==$valid)
        throw new RuntimeException('R35 exact canonical state rejected');
    $invalid=[
        json_encode([...$valid,'operationalAuthority'=>true],JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES),
        str_replace('"state":"APPLIED"', '"state":"PENDING","state":"APPLIED"', $raw),
        ' '.$raw,
        json_encode(['state'=>'APPLIED']+$valid,JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES),
        json_encode([...$valid,'sideEffects'=>'1'],JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES),
        json_encode([...$valid,'deadlineEpoch'=>'1234567890'],JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES)
    ];
    foreach($invalid as $i=>$candidate)
        if(r29decodeState($candidate)!==null)
            throw new RuntimeException('R35 ambiguous provider state accepted: case '.($i+1));
    echo "R35_CANONICAL_PROVIDER_STATE_PASS: 1 accepted, 6 rejected; NO_GO; NO_EGRESS\n";
    exit;
}
if($mode==='probe-state-drift') {
    // R38: exercise the full readback against a CI-only provider that returns
    // APPLIED, exact HTML, then PENDING on the second state GET.
    if(r29source()!==null)
        throw new RuntimeException('R38 stale first APPLIED accepted despite current state drift');
    echo "R38_STATE_DRIFT_BETWEEN_GETS_DENIED; NO_NEW_RECEIPT; NO_GO; LOOPBACK_ONLY\n";
    exit;
}
if($mode==='probe-content-encoding') {
    // R39: malicious Content-Encoding on either otherwise-200 endpoint
    // must never be interpreted as evidence of browser-visible bytes.
    if(r29http(R29_STATE_URL)!==null || r29http(R29_HTML_URL)!==null)
        throw new RuntimeException('R39 encoded representation accepted as exact delivery');
    echo "R39_CONTENT_ENCODING_DENIED: state=1 html=1; NO_GO; NO_EGRESS\n";
    exit;
}
if($mode==='probe-presentation-headers') {
    // R37: an origin cannot prove that HTML was browser-visible merely by
    // returning the right MIME when it orders a download or Refresh navigation.
    // This is not a real publisher or a native buyer acceptance test.
    if(r29http(R29_STATE_URL)!==null || r29http(R29_HTML_URL)!==null)
        throw new RuntimeException('R37 presentation-changing provider header accepted');
    echo "R37_PRESENTATION_HEADERS_DENIED: state=1 html=1; NO_GO; NO_EGRESS\n";
    exit;
}
if($mode==='probe-provider-mime') {
    // CI-only 200 responses with short bodies but wrong media types must
    // never be treated as valid state or as a browser-rendered HTML release.
    if(r29http(R29_STATE_URL)!==null || r29http(R29_HTML_URL)!==null)
        throw new RuntimeException('R36 wrong media type accepted as release evidence');
    echo "R36_WRONG_PROVIDER_MIME_DENIED: state=1 html=1; NO_GO; NO_EGRESS\n";
    exit;
}
if($mode==='probe-http-bounds') {
    // The R33 CI-only adverse server returns HTTP 200 with oversize state and
    // HTML. Neither may be accepted or buffered to completion by r29http().
    if(r29http(R29_STATE_URL)!==null || r29http(R29_HTML_URL)!==null)
        throw new RuntimeException('R33 oversized local provider was accepted');
    echo "R33_OVERSIZED_PROVIDER_STATE_AND_HTML_ABORTED; NO_GO; NO_REAL_EGRESS\n";
    exit;
}
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
    // R32: concurrent retries on the SAME fictitious order/release must never
    // create a second receipt or extend its PG-clock expiry. No replacement of
    // a stale, revoked, expired or differently sourced receipt is permitted.
    $q=r29db()->prepare(<<<'SQL'
INSERT INTO landing_delivery_r29.receipts
(receipt_ref,tenant_ref,buyer_ref,order_ref,site_ref,release_ref,
 html_sha256,brief_sha256,artifact_generation,acceptance_ref,request_ref)
VALUES(?,?,?,?,?,?,?,?,?,?,?)
ON CONFLICT (tenant_ref,buyer_ref,order_ref,release_ref) DO NOTHING
SQL);
    $q->execute([$ref,R26_TENANT,R26_BUYER,R26_ORDER,trim((string)$e['site_ref']),
        R29_RELEASE,(string)$e['html_sha256'],(string)$e['brief_sha256'],
        (int)$e['artifact_generation'],(string)$e['challenge_ref'],R29_REQUEST]);
    $created=$q->rowCount()===1;
    // Reader is a separate PG18 connection with a non-owner RLS role. Exact
    // site/acceptance/revision/digests/time and fresh served bytes are checked.
    $readback=r29receipt();
    if(!$readback || ($created &&
        !hash_equals((string)$readback['receipt']['receipt_ref'],$ref)))
        throw new RuntimeException('Independent receipt readback failed; no new authority');
    if($created) {
        echo "R29_ISSUED_AFTER_INDEPENDENT_RELEASE_SHA=".(string)$e['html_sha256']."; LAB_ONLY\n";
    } else {
        echo "R32_REPLAY_REUSED_EXACT_BUYER_RECEIPT_SHA=".(string)$e['html_sha256']."; NO_NEW_ROW; LAB_ONLY\n";
    }
    exit;
}
if($mode==='count-receipts') {
    $q=r29db('landing_r29_reader')->prepare(<<<'SQL'
SELECT count(*) FROM landing_delivery_r29.receipts
WHERE tenant_ref=? AND buyer_ref=? AND order_ref=? AND release_ref=?
SQL);
    $q->execute([R26_TENANT,R26_BUYER,R26_ORDER,R29_RELEASE]);
    if((int)$q->fetchColumn()!==1 || r29receipt()===null)
        throw new RuntimeException('R32 scoped receipt count or exact readback mismatch');
    echo "R32_ONE_SCOPED_RECEIPT_PG18; COUNT=1; REPLAY_NO_NEW_ROW; NO_GO\n";
    exit;
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
// R31: mutate ONLY the ephemeral laboratory ledger to demonstrate that a
// receipt outside the database clock window is denied on both buyer endpoints.
// These controls never run in a server route, nor mint a new receipt.
if($mode==='expire-receipt' || $mode==='future-issue') {
    $q=r29db()->prepare(<<<'SQL'
UPDATE landing_delivery_r29.receipts
SET issued_at=CASE WHEN ?='future-issue'
                  THEN clock_timestamp()+INTERVAL '2 minutes'
                  ELSE issued_at END,
    expires_at=CASE WHEN ?='expire-receipt'
                    THEN clock_timestamp()-INTERVAL '1 second'
                    ELSE clock_timestamp()+INTERVAL '10 minutes' END
WHERE tenant_ref=? AND buyer_ref=? AND order_ref=? AND release_ref=?
SQL);
    $q->execute([$mode,$mode,R26_TENANT,R26_BUYER,R26_ORDER,R29_RELEASE]);
    if($q->rowCount()!==1 || r29receipt()!==null)
        throw new RuntimeException('R31 temporal validity must deny the buyer receipt');
    echo $mode==='expire-receipt'
        ? "R31_EXPIRED_RECEIPT_DENIED_BY_PG_CLOCK; LAB_ONLY\n"
        : "R31_FUTURE_ISSUED_RECEIPT_DENIED_BY_PG_CLOCK; LAB_ONLY\n";
    exit;
}
if($mode==='readback-revoked') {
    if(r26evidence()!==null || r29receipt()!==null)
        throw new RuntimeException('Revoked briefing must suppress prior receipt');
    echo "R29_REVOKED_SOURCE_DENIES_DELIVERY; NO_GO\n";exit;
}
exit(64);
