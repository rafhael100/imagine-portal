<?php
declare(strict_types=1);
// CI-only synthetic seed/revision. Never an operational Landing writer.
if (PHP_SAPI !== 'cli' || getenv('APP_ENV') !== 'testing' || getenv('LANDING_EXACT_CLICK_LAB') !== '1') {exit(78);}
function labDb(): PDO {
    return new PDO('pgsql:host=127.0.0.1;port=5432;dbname=postgres','postgres','ci-only',[
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_EMULATE_PREPARES => false]);
}
$mode=$argv[1]??'';
$db=labDb();
if ($mode==='seed') {
    $db->exec('DROP SCHEMA IF EXISTS landing_exact_click_lab CASCADE');
    $db->exec(<<<'SQL'
CREATE SCHEMA landing_exact_click_lab;
REVOKE ALL ON SCHEMA landing_exact_click_lab FROM PUBLIC;
CREATE TABLE landing_exact_click_lab.orders (
    order_ref text PRIMARY KEY, tenant_ref text NOT NULL, buyer_ref text NOT NULL,
    site_ref uuid NOT NULL, contract_ref text NOT NULL, product text NOT NULL CHECK (product='landing-page'),
    terms_revision integer NOT NULL CHECK (terms_revision>0),
    terms_bytes text NOT NULL CHECK (octet_length(terms_bytes) BETWEEN 1 AND 10000),
    terms_sha256 char(64) NOT NULL CHECK (terms_sha256 ~ '^[a-f0-9]{64}$')
);
CREATE TABLE landing_exact_click_lab.presentations (
    challenge_ref char(32) PRIMARY KEY,
    order_ref text NOT NULL REFERENCES landing_exact_click_lab.orders(order_ref),
    tenant_ref text NOT NULL, buyer_ref text NOT NULL, contract_ref text NOT NULL, session_sha256 char(64) NOT NULL,
    csrf_sha256 char(64) NOT NULL, terms_revision integer NOT NULL,
    terms_sha256 char(64) NOT NULL, created_at timestamptz NOT NULL DEFAULT now(),
    expires_at timestamptz NOT NULL DEFAULT (now() + interval '3 minutes'),
    consumed_at timestamptz
);
CREATE TABLE landing_exact_click_lab.acceptances (
    challenge_ref char(32) PRIMARY KEY REFERENCES landing_exact_click_lab.presentations(challenge_ref),
    order_ref text NOT NULL REFERENCES landing_exact_click_lab.orders(order_ref),
    tenant_ref text NOT NULL, buyer_ref text NOT NULL, contract_ref text NOT NULL,
    terms_revision integer NOT NULL, terms_sha256 char(64) NOT NULL,
    accepted_at timestamptz NOT NULL DEFAULT now(), explicit_accept boolean NOT NULL CHECK (explicit_accept)
);
CREATE INDEX landing_exact_click_presentations_order ON landing_exact_click_lab.presentations(order_ref);
CREATE INDEX landing_exact_click_acceptance_order ON landing_exact_click_lab.acceptances(order_ref);
REVOKE ALL ON ALL TABLES IN SCHEMA landing_exact_click_lab FROM PUBLIC;
SQL);
    $body="CONTRATO FICTÍCIO LANDING PAGE — REVISÃO 1\nServiço de laboratório, sem compra, pagamento, publicação ou direitos operacionais.";
    $s=$db->prepare('INSERT INTO landing_exact_click_lab.orders VALUES(?,?,?,?,?,?,?,?,?)');
    $s->execute(['landing-order-001','tenant-A','buyer-A','11111111-1111-4111-8111-111111111111','contract-landing-lab-001','landing-page',1,$body,hash('sha256',$body)]);
    echo "LANDING EXACT CLICK SEED OK: synthetic same-order terms v1\n";exit;
}
if ($mode==='revise') {
    $db->beginTransaction();
    $s=$db->prepare('SELECT terms_revision FROM landing_exact_click_lab.orders WHERE order_ref=? FOR UPDATE');
    $s->execute(['landing-order-001']);
    if ((int)$s->fetchColumn()!==1) {$db->rollBack();exit(2);}
    $body="CONTRATO FICTÍCIO LANDING PAGE — REVISÃO 2\nTexto modificado; aceite anterior não é válido.";
    $s=$db->prepare('UPDATE landing_exact_click_lab.orders SET terms_revision=2,terms_bytes=?,terms_sha256=? WHERE order_ref=?');
    $s->execute([$body,hash('sha256',$body),'landing-order-001']);
    $db->commit();
    echo "LANDING EXACT CLICK REVISED v2; no old acceptance carried forward\n";exit;
}
if ($mode==='readback') {
    // Independent process/connection: reject an old acceptance after terms change.
    $s=$db->prepare(<<<'SQL'
SELECT o.tenant_ref,o.buyer_ref,o.order_ref,o.site_ref,o.contract_ref,o.terms_revision,o.terms_sha256,o.terms_bytes,
       EXISTS(SELECT 1 FROM landing_exact_click_lab.acceptances a
              JOIN landing_exact_click_lab.presentations p ON p.challenge_ref=a.challenge_ref
              WHERE a.order_ref=o.order_ref AND a.tenant_ref=o.tenant_ref AND a.buyer_ref=o.buyer_ref
                AND a.contract_ref=o.contract_ref AND p.contract_ref=o.contract_ref
                AND a.terms_revision=o.terms_revision AND a.terms_sha256=o.terms_sha256
                AND p.consumed_at IS NOT NULL AND a.explicit_accept=true) AS current_accepted
FROM landing_exact_click_lab.orders o WHERE o.order_ref=? AND o.tenant_ref=? AND o.buyer_ref=?
SQL);
    $s->execute(['landing-order-001','tenant-A','buyer-A']);
    $row=$s->fetch(PDO::FETCH_ASSOC);
    if (!$row || !hash_equals(hash('sha256',$row['terms_bytes']),trim($row['terms_sha256']))) {exit(3);}
    echo json_encode([
        'order'=>$row['order_ref'],'tenant'=>$row['tenant_ref'],'buyer'=>$row['buyer_ref'],
        'siteRef'=>$row['site_ref'],'contractRef'=>$row['contract_ref'],
        'revision'=>(int)$row['terms_revision'],'terms_sha256'=>trim($row['terms_sha256']),
        'currentAccepted'=>in_array($row['current_accepted'],['t','1',true],true),
        'operationalAuthority'=>false,'pspInvoked'=>false
    ],JSON_THROW_ON_ERROR)."\n";exit;
}
exit(64);
