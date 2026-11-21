<?php
declare(strict_types=1);

/**
 * R28, IN127-R05: isolated loss of a local provider ACK.
 * State belongs to a disposable provider ledger, not to the sender or an ACK.
 * Never a real publication, public URL, credential or provider authorization.
 */
if (!in_array(PHP_SAPI,['cli','cli-server'],true)
    || getenv('GITHUB_ACTIONS')!=='true' || getenv('APP_ENV')!=='testing'
    || getenv('LANDING_R28_ACK_LAB')!=='1') exit(78);
define('LANDING_R27_READ_ONLY_LIBRARY',true);
require_once __DIR__.'/landing_v2_mtls_release_lab.php';

const R28_REQUEST = 'ci-landing-v2-probe-001';

function r28db(string $role='postgres'): PDO {
    return r26db($role,$role==='postgres'?'ci-only':'ci-synthetic-not-operational');
}
function r28text(int $status,string $text):never {
    http_response_code($status);
    header('Content-Type: text/plain; charset=utf-8');
    header('Cache-Control: no-store');
    header('X-Content-Type-Options: nosniff');
    echo $text."\n";exit;
}
function r28record(string $role='postgres'):?array {
    $q=r28db($role)->prepare(<<<'SQL'
SELECT tenant_ref,buyer_ref,order_ref,site_ref,release_ref,html_sha256,
 request_ref,deadline_epoch,delivery_state,side_effects,html_bytes
FROM landing_ack_r28.release_state
WHERE tenant_ref=? AND order_ref=? AND request_ref=?
SQL);
    $q->execute([R26_TENANT,R26_ORDER,R28_REQUEST]);
    $a=$q->fetchAll(PDO::FETCH_ASSOC);
    return count($a)===1?$a[0]:null;
}
function r28preflight():?array {
    $e=r26evidence();
    $r=r28record();
    if($e===null||$r===null
        || $r['tenant_ref']!==R26_TENANT || $r['buyer_ref']!==R26_BUYER
        || $r['order_ref']!==R26_ORDER || $r['site_ref']!==trim((string)$e['site_ref'])
        || $r['release_ref']!==R26_RELEASE
        || !hash_equals((string)$r['html_sha256'],(string)$e['html_sha256'])
        || (int)$r['deadline_epoch']<=0
        || $r['request_ref']!==R28_REQUEST) return null;
    return $r;
}
function r28request(array $r):array {
    return [
        'requestRef'=>R28_REQUEST,
        'tenantRef'=>R26_TENANT,
        'buyerRef'=>R26_BUYER,
        'orderRef'=>R26_ORDER,
        'siteRef'=>(string)$r['site_ref'],
        'releaseRef'=>R26_RELEASE,
        'artifactDigest'=>(string)$r['html_sha256'],
        'deadlineEpoch'=>(int)$r['deadline_epoch'],
    ];
}
function r28canonical(array $a):string {
    return json_encode($a,JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES);
}
function r28input():?array {
    $raw=file_get_contents('php://input',false,null,0,2049);
    if(!is_string($raw)||strlen($raw)>2048||strlen($raw)<3)return null;
    try {
        $a=json_decode($raw,true,8,JSON_THROW_ON_ERROR);
        return is_array($a)&&!array_is_list($a)&&r28canonical($a)===$raw?$a:null;
    }catch(Throwable){return null;}
}
function r28http(string $url,?string $post=null):array {
    // All URLs below are fixed loopback literals, not derived from the request.
    $ch=curl_init($url);
    curl_setopt_array($ch,[
        CURLOPT_RETURNTRANSFER=>true,CURLOPT_FOLLOWLOCATION=>false,
        CURLOPT_CONNECTTIMEOUT_MS=>500,CURLOPT_TIMEOUT_MS=>1500,
        CURLOPT_PROXY=>'',CURLOPT_MAXREDIRS=>0,
    ]);
    if($post!==null)curl_setopt_array($ch,[
        CURLOPT_POST=>true,CURLOPT_POSTFIELDS=>$post,
        CURLOPT_HTTPHEADER=>['Content-Type: application/json']
    ]);
    $body=curl_exec($ch);
    $status=(int)curl_getinfo($ch,CURLINFO_RESPONSE_CODE);
    curl_close($ch);
    return [$status,is_string($body)?$body:''];
}

if(PHP_SAPI==='cli-server') {
    $port=(int)($_SERVER['SERVER_PORT']??0);
    $path=parse_url((string)($_SERVER['REQUEST_URI']??''),PHP_URL_PATH);
    $method=$_SERVER['REQUEST_METHOD']??'';
    if($path==='/release'||$path==='/lead')r28text(403,'NO_PUBLIC_RELEASE');
    $r=r28preflight();
    if($r===null)r28text(403,'NO_CURRENT_ACCEPTANCE');
    if($port===8790 && $path==='/ci/submit' && $method==='POST') {
        if(r28input()!==['requestRef'=>R28_REQUEST])
            r28text(403,'SCOPE_DENIED');
        [$status,$body]=r28http('http://127.0.0.1:8791/ci/apply',
            r28canonical(r28request($r)));
        if($status!==201 || !str_contains($body,'MOCK_APPLIED'))
            r28text(502,'PROVIDER_NOT_APPLIED');
        // Deliberately suppress ACK on the local proxy boundary AFTER commit.
        r28text(504,'ACK_SUPPRESSED_BY_CI_PROXY');
    }
    if($port===8791 && $path==='/ci/apply' && $method==='POST') {
        $in=r28input();
        if($in===null||$in!==r28request($r))
            r28text(403,'WRONG_RELEASE_OR_DEADLINE');
        $e=r26evidence();
        if($e===null||!hash_equals((string)$e['html_sha256'],(string)$r['html_sha256']))
            r28text(403,'STALE_APPROVAL');
        $q=r28db()->prepare(<<<'SQL'
UPDATE landing_ack_r28.release_state
 SET delivery_state='APPLIED',side_effects=side_effects+1,html_bytes=?
WHERE tenant_ref=? AND order_ref=? AND request_ref=?
 AND delivery_state='PENDING' AND side_effects=0 AND deadline_epoch=?
RETURNING side_effects
SQL);
        $q->execute([
            (string)$e['html_bytes'],R26_TENANT,R26_ORDER,R28_REQUEST,
            (int)$r['deadline_epoch']
        ]);
        if((int)$q->fetchColumn()!==1)r28text(409,'REPLAY_NO_SECOND_EFFECT');
        r28text(201,'MOCK_APPLIED');
    }
    if($port===8791 && $path==='/ci/state' && $method==='GET') {
        $qs=(array)($_GET??[]);
        if($qs!==['tenantRef'=>R26_TENANT,'orderRef'=>R26_ORDER,'releaseRef'=>R26_RELEASE])
            r28text(403,'FOREIGN_STATE_DENIED');
        $read=r28record('landing_r28_reader'); // independent nonowner SQL role
        if($read===null)r28text(404,'STATE_NOT_FOUND');
        $body=[
            'requestRef'=>$read['request_ref'],
            'tenantRef'=>$read['tenant_ref'],
            'orderRef'=>$read['order_ref'],
            'releaseRef'=>$read['release_ref'],
            'artifactDigest'=>$read['html_sha256'],
            'deadlineEpoch'=>(int)$read['deadline_epoch'],
            'state'=>$read['delivery_state'],
            'sideEffects'=>(int)$read['side_effects']
        ];
        header('Cache-Control: no-store');
        header('Content-Type: application/json');
        echo r28canonical($body);return;
    }
    if($port===8791 && $path==='/ci/served' && $method==='GET') {
        $read=r28record('landing_r28_reader');
        if($read===null || $read['delivery_state']!=='APPLIED'
           || $read['side_effects']!=1
           || !hash_equals(hash('sha256',(string)$read['html_bytes']),
                (string)$read['html_sha256']))
            r28text(403,'RELEASE_NOT_OBSERVED');
        header('Cache-Control: no-store');
        header('Content-Type: text/html; charset=utf-8');
        echo $read['html_bytes'];return;
    }
    r28text(403,'NO_CI_ROUTE');
}

$mode=$argv[1]??'';
if($mode==='init') {
    $e=r26evidence();
    if($e===null)throw new RuntimeException('Missing approved v2 CI evidence');
    $db=r28db();
    $db->exec(<<<'SQL'
CREATE SCHEMA landing_ack_r28;
CREATE TABLE landing_ack_r28.release_state(
 tenant_ref text NOT NULL,buyer_ref text NOT NULL,order_ref text NOT NULL,
 site_ref text NOT NULL,release_ref text NOT NULL,html_sha256 text NOT NULL,
 request_ref text NOT NULL,deadline_epoch bigint NOT NULL,
 delivery_state text NOT NULL DEFAULT 'PENDING',
 side_effects integer NOT NULL DEFAULT 0,html_bytes text NULL,
 PRIMARY KEY(tenant_ref,order_ref,request_ref),
 CHECK(delivery_state IN('PENDING','APPLIED')),
 CHECK(side_effects BETWEEN 0 AND 1)
);
CREATE ROLE landing_r28_reader LOGIN PASSWORD 'ci-synthetic-not-operational';
REVOKE ALL ON SCHEMA landing_ack_r28 FROM PUBLIC;
GRANT USAGE ON SCHEMA landing_ack_r28 TO landing_r28_reader;
GRANT SELECT ON landing_ack_r28.release_state TO landing_r28_reader;
ALTER TABLE landing_ack_r28.release_state ENABLE ROW LEVEL SECURITY;
ALTER TABLE landing_ack_r28.release_state FORCE ROW LEVEL SECURITY;
CREATE POLICY ci_read ON landing_ack_r28.release_state FOR SELECT TO landing_r28_reader
 USING (tenant_ref='tenant-A' AND buyer_ref='buyer-A'
 AND order_ref='landing-order-001' AND release_ref='landing-v2-generation-2-ci');
SQL);
    $q=$db->prepare(<<<'SQL'
INSERT INTO landing_ack_r28.release_state
(tenant_ref,buyer_ref,order_ref,site_ref,release_ref,html_sha256,request_ref,deadline_epoch)
VALUES(?,?,?,?,?,?,?,?)
SQL);
    // Synthetic original request deadline is immutable; no extension after the lost ACK.
    $deadline=time()+120;
    $q->execute([
        R26_TENANT,R26_BUYER,R26_ORDER,trim((string)$e['site_ref']),
        R26_RELEASE,(string)$e['html_sha256'],R28_REQUEST,$deadline
    ]);
    echo "R28_CI_REQUEST_PENDING; DEADLINE_FIXED; NO_PUBLISHER\n";exit;
}
if($mode==='reconcile' || $mode==='reconcile-wrong-digest') {
    $e=r26evidence();$r=r28preflight();
    if($e===null||$r===null)throw new RuntimeException('Fresh source denied');
    [$code,$raw]=r28http(
        'http://127.0.0.1:8791/ci/state?tenantRef=tenant-A&orderRef=landing-order-001&releaseRef=landing-v2-generation-2-ci'
    );
    if($code!==200)throw new RuntimeException('Provider state unavailable: no retry');
    $state=json_decode($raw,true,8,JSON_THROW_ON_ERROR);
    if(!is_array($state)||($state['state']??null)!=='APPLIED'
       || ($state['sideEffects']??null)!==1
       || ($state['requestRef']??null)!==R28_REQUEST
       || ($state['releaseRef']??null)!==R26_RELEASE
       || ($state['tenantRef']??null)!==R26_TENANT
       || ($state['orderRef']??null)!==R26_ORDER
       || ($state['deadlineEpoch']??null)!==(int)$r['deadline_epoch'])
        throw new RuntimeException('Provider state mismatch: block retry');
    [$http,$bytes]=r28http('http://127.0.0.1:8791/ci/served');
    if($http!==200)throw new RuntimeException('Independent HTML not served');
    $observed=hash('sha256',$bytes);
    $expected=$mode==='reconcile-wrong-digest'?str_repeat('a',64):(string)$e['html_sha256'];
    if(!hash_equals($expected,$observed)) {
        if($mode!=='reconcile-wrong-digest')
            throw new RuntimeException('HTML SHA mismatch');
        echo "R28_WRONG_HEAD_BLOCKED; NO_SECOND_DISPATCH; NO_GO\n";exit;
    }
    if($mode!=='reconcile'||$bytes!==$e['html_bytes'])
        throw new RuntimeException('Exact served bytes mismatch');
    $independent=r28record('landing_r28_reader');
    if(!$independent || $independent['side_effects']!=1
        || $independent['delivery_state']!=='APPLIED'
        || (int)$independent['deadline_epoch']!==(int)$r['deadline_epoch'])
        throw new RuntimeException('PG18 independent source mismatch');
    echo "R28_LOST_ACK_RECONCILED_NO_RETRY; APPLIED_ONCE=1; OBSERVED_SHA=".$observed."\n";
    echo "DEADLINE_UNCHANGED; MOCK_ONLY; FORM=OFF; PUBLISH=NO_GO\n";exit;
}
exit(64);
