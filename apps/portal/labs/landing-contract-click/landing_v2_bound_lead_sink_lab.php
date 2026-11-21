<?php
declare(strict_types=1);

/**
 * R27: test-only destination binding and separate local lead receiver.
 * This is NOT an enabled customer form, an operational CRM route, a
 * publisher or a replacement for an authorized VEN destination.
 */
if (!in_array(PHP_SAPI, ['cli', 'cli-server'], true)
    || getenv('GITHUB_ACTIONS') !== 'true'
    || getenv('APP_ENV') !== 'testing'
    || getenv('LANDING_R27_LEAD_LAB') !== '1') exit(78);
define('LANDING_R27_READ_ONLY_LIBRARY', true);
require_once __DIR__.'/landing_v2_mtls_release_lab.php';

const R27_DEST = 'ci-local-inbox-only';
const R27_RELEASE = R26_RELEASE;
const R27_SCHEMA = 'sites.landing-lead-probe.lab.v1';

function r27db(string $role='postgres'): PDO {
    $pass = $role === 'postgres' ? 'ci-only' : 'ci-synthetic-not-operational';
    return r26db($role, $pass);
}
function r27respond(int $code, string $body): never {
    http_response_code($code);
    header('Content-Type: text/plain; charset=utf-8');
    header('Cache-Control: no-store');
    header('X-Content-Type-Options: nosniff');
    header('X-Landing-Lab: synthetic-no-publisher-no-form');
    echo $body."\n";
    exit;
}
function r27canonical(array $payload): string {
    return json_encode($payload, JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);
}
function r27parse(int $limit=2048): ?array {
    $raw=file_get_contents('php://input', false, null, 0, $limit+1);
    if (!is_string($raw) || strlen($raw)>$limit || strlen($raw)<10) return null;
    try {
        $parsed=json_decode($raw, true, 8, JSON_THROW_ON_ERROR);
        if (!is_array($parsed) || array_is_list($parsed) || r27canonical($parsed)!==$raw) return null;
        return $parsed;
    } catch (Throwable) { return null; }
}
function r27payload(array $e, string $event, string $lead): array {
    return [
        'schemaVersion'=>R27_SCHEMA,
        'tenantRef'=>R26_TENANT,
        'buyerRef'=>R26_BUYER,
        'orderRef'=>R26_ORDER,
        'siteRef'=>trim((string)$e['site_ref']),
        'releaseRef'=>R27_RELEASE,
        'artifactDigest'=>(string)$e['html_sha256'],
        'eventRef'=>$event,
        'leadRef'=>$lead,
    ];
}

/** A distinct, explicit lab owner binds exactly one current synthetic release. */
function r27bound(array $e): bool {
    $q=r27db()->prepare(<<<'SQL'
SELECT destination_ref,operational FROM landing_lead_r27.bindings
WHERE tenant_ref=? AND buyer_ref=? AND order_ref=? AND site_ref=?
  AND release_ref=? AND html_sha256=? AND enabled=true
  AND bound_at<=CURRENT_TIMESTAMP AND expires_at>CURRENT_TIMESTAMP
SQL);
    $q->execute([
        R26_TENANT,R26_BUYER,R26_ORDER,trim((string)$e['site_ref']),
        R27_RELEASE,(string)$e['html_sha256']
    ]);
    $rows=$q->fetchAll(PDO::FETCH_ASSOC);
    return count($rows)===1 && $rows[0]['destination_ref']===R27_DEST
        && in_array($rows[0]['operational'], ['f',false,0,'0'], true);
}

if (PHP_SAPI==='cli-server') {
    $port=(int)($_SERVER['SERVER_PORT']??0);
    $route=parse_url((string)($_SERVER['REQUEST_URI']??''), PHP_URL_PATH);
    if (($_SERVER['REQUEST_METHOD']??'')!=='POST') r27respond(403,'FORM_OFF');
    // Public route stays disabled even when an internal synthetic destination is bound.
    if ($route==='/lead' || $route==='/') r27respond(403,'NO_OFFICIAL_DESTINATION');
    $e=r26evidence(); // fresh independent PG18 connection + current approval, never a stale ACK
    if ($e===null || !r27bound($e)) r27respond(403,'NO_CURRENT_LAB_BINDING');

    if ($port===8780 && $route==='/lab/synthetic-send') {
        $in=r27parse();
        if ($in===null || array_keys($in)!==[
            'tenantRef','releaseRef','artifactDigest','eventRef','leadRef'
        ] || $in['tenantRef']!==R26_TENANT
          || $in['releaseRef']!==R27_RELEASE
          || $in['artifactDigest']!==$e['html_sha256']
          || !is_string($in['eventRef'])
          || preg_match('/\Aci-event-[0-9]{3}\z/D',$in['eventRef'])!==1
          || !in_array($in['leadRef'],['ci-lead-A','ci-lead-B'],true))
            r27respond(403,'SCOPE_DENIED');
        // This hard-coded loopback sink is not a client-defined URL or the Portal.
        $out=r27canonical(r27payload($e,$in['eventRef'],$in['leadRef']));
        $ch=curl_init('http://127.0.0.1:8781/ci/ingest');
        curl_setopt_array($ch,[
            CURLOPT_POST=>true,CURLOPT_POSTFIELDS=>$out,
            CURLOPT_HTTPHEADER=>['Content-Type: application/json'],
            CURLOPT_RETURNTRANSFER=>true,CURLOPT_FOLLOWLOCATION=>false,
            CURLOPT_CONNECTTIMEOUT_MS=>500,CURLOPT_TIMEOUT_MS=>2000,
            CURLOPT_PROXY=>'',CURLOPT_MAXREDIRS=>0,
        ]);
        $response=curl_exec($ch);
        $code=(int)curl_getinfo($ch,CURLINFO_RESPONSE_CODE);
        curl_close($ch);
        if (!is_string($response) || !in_array($code,[201,200,409],true))
            r27respond(502,'SINK_UNAVAILABLE');
        r27respond($code,trim($response));
    }
    if ($port===8781 && $route==='/ci/ingest') {
        $in=r27parse();
        if ($in===null || array_keys($in)!==[
            'schemaVersion','tenantRef','buyerRef','orderRef','siteRef',
            'releaseRef','artifactDigest','eventRef','leadRef'
        ] || $in!==r27payload($e,(string)($in['eventRef']??''),(string)($in['leadRef']??''))
          || preg_match('/\Aci-event-[0-9]{3}\z/D',(string)$in['eventRef'])!==1
          || !in_array($in['leadRef'],['ci-lead-A','ci-lead-B'],true))
            r27respond(403,'SINK_SCOPE_DENIED');
        $db=r27db('landing_r27_sink');
        $digest=hash('sha256',r27canonical($in));
        $insert=$db->prepare(<<<'SQL'
INSERT INTO landing_lead_r27.inbox
(tenant_ref,buyer_ref,order_ref,site_ref,release_ref,html_sha256,
 event_ref,lead_ref,payload_sha256)
VALUES(?,?,?,?,?,?,?,?,?)
ON CONFLICT (tenant_ref,order_ref,site_ref,release_ref,event_ref) DO NOTHING
SQL);
        $insert->execute([
            R26_TENANT,R26_BUYER,R26_ORDER,(string)$in['siteRef'],
            R27_RELEASE,(string)$e['html_sha256'],(string)$in['eventRef'],
            (string)$in['leadRef'],$digest
        ]);
        $fresh=$insert->rowCount()===1;
        $read=$db->prepare(<<<'SQL'
SELECT lead_ref,payload_sha256 FROM landing_lead_r27.inbox
WHERE tenant_ref=? AND order_ref=? AND site_ref=? AND release_ref=? AND event_ref=?
SQL);
        $read->execute([
            R26_TENANT,R26_ORDER,(string)$in['siteRef'],R27_RELEASE,(string)$in['eventRef']
        ]);
        $record=$read->fetch(PDO::FETCH_ASSOC);
        if (!is_array($record) || !hash_equals($digest,(string)$record['payload_sha256'])
            || $record['lead_ref']!==$in['leadRef'])
            r27respond(409,'IDEMPOTENCY_CONFLICT');
        r27respond($fresh?201:200,$fresh?'SYNTHETIC_SINK_STORED':'SYNTHETIC_SINK_REPLAY');
    }
    r27respond(403,'NO_PUBLIC_ENDPOINT');
}

$mode=$argv[1]??'';
$db=r27db();
if ($mode==='init') {
    $db->exec(<<<'SQL'
CREATE SCHEMA landing_lead_r27;
CREATE TABLE landing_lead_r27.bindings (
 tenant_ref text NOT NULL, buyer_ref text NOT NULL, order_ref text NOT NULL,
 site_ref text NOT NULL, release_ref text NOT NULL, html_sha256 text NOT NULL,
 destination_ref text NOT NULL, operational boolean NOT NULL DEFAULT false,
 enabled boolean NOT NULL DEFAULT false, bound_at timestamptz NOT NULL,
 expires_at timestamptz NOT NULL,
 PRIMARY KEY(tenant_ref,order_ref,site_ref,release_ref)
);
CREATE TABLE landing_lead_r27.inbox (
 tenant_ref text NOT NULL, buyer_ref text NOT NULL, order_ref text NOT NULL,
 site_ref text NOT NULL, release_ref text NOT NULL, html_sha256 text NOT NULL,
 event_ref text NOT NULL, lead_ref text NOT NULL, payload_sha256 text NOT NULL,
 received_at timestamptz NOT NULL DEFAULT CURRENT_TIMESTAMP,
 PRIMARY KEY(tenant_ref,order_ref,site_ref,release_ref,event_ref)
);
CREATE ROLE landing_r27_sink LOGIN PASSWORD 'ci-synthetic-not-operational';
CREATE ROLE landing_r27_reader LOGIN PASSWORD 'ci-synthetic-not-operational';
REVOKE ALL ON SCHEMA landing_lead_r27 FROM PUBLIC;
GRANT USAGE ON SCHEMA landing_lead_r27 TO landing_r27_sink,landing_r27_reader;
GRANT INSERT,SELECT ON landing_lead_r27.inbox TO landing_r27_sink;
GRANT SELECT ON landing_lead_r27.inbox TO landing_r27_reader;
ALTER TABLE landing_lead_r27.inbox ENABLE ROW LEVEL SECURITY;
ALTER TABLE landing_lead_r27.inbox FORCE ROW LEVEL SECURITY;
CREATE POLICY r27_sink_ins ON landing_lead_r27.inbox FOR INSERT TO landing_r27_sink
 WITH CHECK (tenant_ref='tenant-A' AND buyer_ref='buyer-A'
 AND order_ref='landing-order-001' AND release_ref='landing-v2-generation-2-ci');
CREATE POLICY r27_sink_read ON landing_lead_r27.inbox FOR SELECT TO landing_r27_sink
 USING (tenant_ref='tenant-A' AND buyer_ref='buyer-A'
 AND order_ref='landing-order-001' AND release_ref='landing-v2-generation-2-ci');
CREATE POLICY r27_audit_read ON landing_lead_r27.inbox FOR SELECT TO landing_r27_reader
 USING (tenant_ref='tenant-A' AND buyer_ref='buyer-A'
 AND order_ref='landing-order-001' AND release_ref='landing-v2-generation-2-ci');
SQL);
    echo "R27_CI_SCHEMA_AND_RLS_READY; NO_OFFICIAL_WRITER\n"; exit;
}
if ($mode==='bind') {
    $e=r26evidence();
    if ($e===null) throw new RuntimeException('Cannot bind without current exact v2');
    $q=$db->prepare(<<<'SQL'
INSERT INTO landing_lead_r27.bindings
(tenant_ref,buyer_ref,order_ref,site_ref,release_ref,html_sha256,
 destination_ref,operational,enabled,bound_at,expires_at)
VALUES(?,?,?,?,?,?,?,false,true,CURRENT_TIMESTAMP,CURRENT_TIMESTAMP+INTERVAL '5 minutes')
SQL);
    $q->execute([
        R26_TENANT,R26_BUYER,R26_ORDER,trim((string)$e['site_ref']),
        R27_RELEASE,(string)$e['html_sha256'],R27_DEST
    ]);
    if(!r27bound($e))throw new RuntimeException('Lab binding not independently readable');
    echo "R27_EXPLICIT_CI_ONLY_BINDING; NOT_VEN_NOT_OPERATIONAL\n"; exit;
}
if ($mode==='revoke-binding') {
    $db->exec("UPDATE landing_lead_r27.bindings SET enabled=false");
    echo "R27_CI_BINDING_REVOKED\n";exit;
}
if ($mode==='readback') {
    $e=r26evidence();
    if($e===null)throw new RuntimeException('Current v2 absent');
    $audit=r27db('landing_r27_reader');
    $q=$audit->prepare(<<<'SQL'
SELECT tenant_ref,buyer_ref,order_ref,site_ref,release_ref,html_sha256,
 event_ref,lead_ref,payload_sha256 FROM landing_lead_r27.inbox
WHERE tenant_ref=? AND order_ref=? AND release_ref=?
SQL);
    $q->execute([R26_TENANT,R26_ORDER,R27_RELEASE]);
    $rows=$q->fetchAll(PDO::FETCH_ASSOC);
    $expected=r27payload($e,'ci-event-001','ci-lead-A');
    if (count($rows)!==1 || $rows[0]['tenant_ref']!==R26_TENANT
        || $rows[0]['buyer_ref']!==R26_BUYER
        || $rows[0]['site_ref']!==trim((string)$e['site_ref'])
        || $rows[0]['html_sha256']!==$e['html_sha256']
        || $rows[0]['event_ref']!=='ci-event-001'
        || $rows[0]['lead_ref']!=='ci-lead-A'
        || !hash_equals(hash('sha256',r27canonical($expected)),$rows[0]['payload_sha256']))
        throw new RuntimeException('Independent synthetic sink readback mismatch');
    $wrong=$audit->query("SELECT COUNT(*) FROM landing_lead_r27.inbox WHERE tenant_ref='tenant-B'");
    if((int)$wrong->fetchColumn()!==0)throw new RuntimeException('Tenant isolation failure');
    echo "R27_INDEPENDENT_SINK_READBACK=1; IDEMPOTENT=1; SHA=".$e['html_sha256']."\n";
    echo "FORM=OFF; DEST=CI_ONLY; CRM_VEN=NOT_CONNECTED; PUBLISH=NO_GO\n";exit;
}
exit(64);
