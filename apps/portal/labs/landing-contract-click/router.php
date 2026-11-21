<?php
declare(strict_types=1);
// Only a test fixture: no Portal login, legal signature, PSP, or operational writer.
if (getenv('APP_ENV') !== 'testing' || getenv('LANDING_EXACT_CLICK_LAB') !== '1'
    || !in_array($_SERVER['REMOTE_ADDR'] ?? '', ['127.0.0.1','::1'], true)) {
    http_response_code(404); exit;
}
ini_set('session.use_strict_mode','1');
ini_set('session.cookie_httponly','1');
ini_set('session.cookie_samesite','Strict');
header('Cache-Control: no-store');
header("Content-Security-Policy: default-src 'none'; style-src 'unsafe-inline'; form-action 'self'; base-uri 'none'; frame-ancestors 'none'");
header('X-Content-Type-Options: nosniff');
session_start();
function db(): PDO {
    return new PDO('pgsql:host=127.0.0.1;port=5432;dbname=postgres','postgres','ci-only',[
        PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION, PDO::ATTR_EMULATE_PREPARES=>false]);
}
function esc(string $v): string {return htmlspecialchars($v,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');}
function deny(int $status=403): never {http_response_code($status);echo "LANDING LAB DENIED\n";exit;}
function ownedOrder(PDO $db,string $order): array {
    if (!isset($_SESSION['tenant'],$_SESSION['buyer'])) deny();
    $s=$db->prepare("SELECT * FROM landing_exact_click_lab.orders WHERE order_ref=? AND tenant_ref=? AND buyer_ref=? AND product='landing-page'");
    $s->execute([$order,$_SESSION['tenant'],$_SESSION['buyer']]);
    $r=$s->fetch(PDO::FETCH_ASSOC);
    if (!$r || !hash_equals(hash('sha256',$r['terms_bytes']),trim($r['terms_sha256']))) deny();
    return $r;
}
$path=parse_url($_SERVER['REQUEST_URI']??'/',PHP_URL_PATH);
$method=$_SERVER['REQUEST_METHOD']??'GET';
if ($path==='/lab/login' && $method==='GET') {
    echo '<h1>Acesso sintético — não é autenticação real</h1><form method="post"><select name="fixture" id="fixture"><option value="buyer-A">Comprador correto</option><option value="buyer-B">Outro comprador da mesma empresa</option><option value="buyer-foreign">Outra empresa</option></select><button id="login" type="submit">Entrar no laboratório</button></form>';exit;
}
if ($path==='/lab/login' && $method==='POST') {
    $map=['buyer-A'=>['tenant-A','buyer-A'],'buyer-B'=>['tenant-A','buyer-B'],'buyer-foreign'=>['tenant-B','buyer-foreign']];
    $who=$map[$_POST['fixture']??'']??null;
    if (!$who)deny();
    session_regenerate_id(true);
    [$_SESSION['tenant'],$_SESSION['buyer']]=$who;
    header('Location: /lab/terms?order=landing-order-001',true,303);exit;
}
if ($path==='/lab/terms' && $method==='GET') {
    $order=$_GET['order']??'';
    if (!is_string($order) || strlen($order)>128)deny();
    $db=db();$r=ownedOrder($db,$order);
    $challenge=bin2hex(random_bytes(16));$csrf=bin2hex(random_bytes(32));
    $_SESSION['csrf']=$csrf;
    $s=$db->prepare('INSERT INTO landing_exact_click_lab.presentations(challenge_ref,order_ref,tenant_ref,buyer_ref,contract_ref,session_sha256,csrf_sha256,terms_revision,terms_sha256) VALUES(?,?,?,?,?,?,?,?,?)');
    $s->execute([$challenge,$order,$_SESSION['tenant'],$_SESSION['buyer'],$r['contract_ref'],hash('sha256',session_id()),hash('sha256',$csrf),(int)$r['terms_revision'],trim($r['terms_sha256'])]);
    header('Content-Type: text/html; charset=utf-8');
    echo '<!doctype html><html lang="pt-BR"><meta charset="utf-8"><title>Contrato fictício Landing</title><h1>Revisar contrato fictício Landing</h1>';
    echo '<p>Laboratório sem contrato oficial, cobrança ou publicação.</p>';
    echo '<p id="order">'.esc($order).'</p><p id="contract-ref">'.esc($r['contract_ref']).'</p><p id="revision">'.esc((string)$r['terms_revision']).'</p>';
    echo '<p id="digest">'.esc(trim($r['terms_sha256'])).'</p><pre id="terms">'.esc($r['terms_bytes']).'</pre>';
    echo '<form id="accept-form" method="post" action="/lab/accept">';
    foreach(['order'=>$order,'revision'=>(string)$r['terms_revision'],'digest'=>trim($r['terms_sha256']),'challenge'=>$challenge,'csrf'=>$csrf] as $k=>$v) echo '<input type="hidden" name="'.esc($k).'" value="'.esc($v).'">';
    echo '<label><input id="checkbox" type="checkbox" name="accept" value="yes" required> Li os bytes exatos acima e confirmo EXPRESSAMENTE apenas neste teste sintético</label><button id="confirm" type="submit">Confirmar neste laboratório</button></form></html>';exit;
}
if ($path==='/lab/accept' && $method==='POST') {
    $order=$_POST['order']??'';$challenge=$_POST['challenge']??'';$csrf=$_POST['csrf']??'';$rev=$_POST['revision']??'';$digest=$_POST['digest']??'';
    if (!isset($_SESSION['buyer'],$_SESSION['tenant'],$_SESSION['csrf']) || ($_POST['accept']??'')!=='yes'
        || !is_string($order) || !preg_match('/\A[a-zA-Z0-9-]{1,80}\z/D',$order)
        || !is_string($challenge) || !preg_match('/\A[a-f0-9]{32}\z/D',$challenge)
        || !is_string($csrf) || !hash_equals($_SESSION['csrf'],$csrf)
        || !is_string($rev) || !preg_match('/\A[1-9][0-9]{0,7}\z/D',$rev)
        || !is_string($digest) || !preg_match('/\A[a-f0-9]{64}\z/D',$digest)) deny(409);
    $db=db();$db->beginTransaction();
    try {
        $s=$db->prepare('SELECT * FROM landing_exact_click_lab.orders WHERE order_ref=? AND tenant_ref=? AND buyer_ref=? FOR UPDATE');
        $s->execute([$order,$_SESSION['tenant'],$_SESSION['buyer']]);$o=$s->fetch(PDO::FETCH_ASSOC);
        $s=$db->prepare('SELECT * FROM landing_exact_click_lab.presentations WHERE challenge_ref=? AND order_ref=? AND tenant_ref=? AND buyer_ref=? FOR UPDATE');
        $s->execute([$challenge,$order,$_SESSION['tenant'],$_SESSION['buyer']]);$p=$s->fetch(PDO::FETCH_ASSOC);
        if (!$o || !$p || $o['product']!=='landing-page' || (int)$o['terms_revision']!==(int)$rev
            || (int)$p['terms_revision']!==(int)$rev || !hash_equals(trim($o['terms_sha256']),$digest)
            || !hash_equals(trim($p['terms_sha256']),$digest)
            || !hash_equals(hash('sha256',$o['terms_bytes']),$digest)
            || !hash_equals(trim($p['csrf_sha256']),hash('sha256',$csrf))
            || !hash_equals(trim($p['session_sha256']),hash('sha256',session_id()))
            || !hash_equals($p['contract_ref'],$o['contract_ref'])
            || $p['consumed_at']!==null || strtotime($p['expires_at'])<=time()) {$db->rollBack();deny(409);}
        $s=$db->prepare('UPDATE landing_exact_click_lab.presentations SET consumed_at=clock_timestamp() WHERE challenge_ref=? AND consumed_at IS NULL');
        $s->execute([$challenge]);
        if ($s->rowCount()!==1) {$db->rollBack();deny(409);}
        $s=$db->prepare('INSERT INTO landing_exact_click_lab.acceptances(challenge_ref,order_ref,tenant_ref,buyer_ref,contract_ref,terms_revision,terms_sha256,explicit_accept) VALUES(?,?,?,?,?,?,?,true)');
        $s->execute([$challenge,$order,$_SESSION['tenant'],$_SESSION['buyer'],$o['contract_ref'],(int)$rev,$digest]);
        $db->commit();unset($_SESSION['csrf']);
        header('Content-Type: text/plain; charset=utf-8');
        echo "LAB_ACCEPTED_ONLY: receipt persisted for exact order/revision/SHA; releaseGate=NO_GO\n";exit;
    } catch(Throwable $e) {if($db->inTransaction())$db->rollBack();deny(409);}
}
deny(404);
