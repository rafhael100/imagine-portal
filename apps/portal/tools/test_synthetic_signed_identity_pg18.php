<?php

declare(strict_types=1);

/**
 * CI-only candidate: a single DB login with per-customer RLS validated by a
 * short-lived context signed OUTSIDE that login. No Laravel wiring, no serving.
 *
 * A trusted identity issuer is simulated here with a random in-memory key.
 * The checkout role cannot SELECT the key or mint other customer signatures.
 * This does not implement real MFA, key custody, revocation or anti-rollback.
 */
if (getenv('GITHUB_ACTIONS') !== 'true'
    || getenv('PORTAL_P0_OWNER_PG_LAB') !== '1'
    || getenv('PORTAL_OWNER_PG_HOST') !== '127.0.0.1'
    || getenv('PORTAL_OWNER_PG_DB') !== 'portal_ownership_ci') {
    throw new RuntimeException('Disposable PostgreSQL 18 CI lab required.');
}
$dsn='pgsql:host=127.0.0.1;port=5432;dbname=portal_ownership_ci';
$opts=[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,
       PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC];
$admin=new PDO($dsn,'postgres','ci-postgres-admin-synthetic',$opts);
$issuerSecret=random_bytes(32);
$count=0;
function proof(bool $ok,string $why): void {
    global $count;
    if (!$ok) throw new RuntimeException($why);
    ++$count;
}
function deny(callable $action,string $why): void {
    try { $action(); } catch (PDOException $e) {
        proof($e->getCode()!=='00000',$why); return;
    }
    throw new RuntimeException($why);
}
$admin->exec(<<<'SQL'
CREATE EXTENSION IF NOT EXISTS pgcrypto;
CREATE SCHEMA portal_signed_lab;
CREATE ROLE owner_ci_shared LOGIN PASSWORD 'ci-shared-runtime-only'
    NOSUPERUSER NOBYPASSRLS NOINHERIT NOCREATEDB NOCREATEROLE NOREPLICATION;
GRANT CONNECT ON DATABASE portal_ownership_ci TO owner_ci_shared;
GRANT USAGE ON SCHEMA portal_signed_lab TO owner_ci_shared;
CREATE TABLE portal_signed_lab.signing_keys (
    scope text PRIMARY KEY,
    secret bytea NOT NULL
);
REVOKE ALL ON portal_signed_lab.signing_keys FROM PUBLIC;
CREATE FUNCTION portal_signed_lab.context_ok() RETURNS boolean
LANGUAGE plpgsql VOLATILE SECURITY DEFINER
SET search_path=pg_catalog,portal_signed_lab
AS $fn$
DECLARE
    o text := current_setting('app.signed_org',true);
    c text := current_setting('app.signed_customer',true);
    e text := current_setting('app.signed_expires',true);
    n text := current_setting('app.signed_nonce',true);
    s text := current_setting('app.signed_mac',true);
    k bytea;
    payload bytea;
BEGIN
    -- Reject ambiguous encoding and maliciously crafted custom GUC values
    -- BEFORE joining the canonical HMAC input.
    IF o IS NULL OR o !~ '^test-org-[ab]$'
        OR c IS NULL OR c !~ '^test-customer-[ab]$'
        OR e IS NULL OR e !~ '^[0-9]{10}$'
        OR n IS NULL OR n !~ '^[a-f0-9]{32}$'
        OR s IS NULL OR s !~ '^[a-f0-9]{64}$' THEN
        RETURN false;
    END IF;
    IF e::bigint <= extract(epoch FROM clock_timestamp())::bigint
       OR e::bigint > extract(epoch FROM clock_timestamp())::bigint + 60 THEN
        RETURN false;
    END IF;
    SELECT secret INTO k FROM portal_signed_lab.signing_keys WHERE scope='preview';
    IF k IS NULL THEN RETURN false; END IF;
    payload=convert_to(o||E'\n'||c||E'\n'||e||E'\n'||n,'UTF8');
    RETURN encode(public.hmac(payload,k,'sha256'),'hex')=s;
END
$fn$;
REVOKE ALL ON FUNCTION portal_signed_lab.context_ok() FROM PUBLIC;
GRANT EXECUTE ON FUNCTION portal_signed_lab.context_ok() TO owner_ci_shared;
CREATE TABLE portal_signed_lab.orders (
    id text PRIMARY KEY,
    organization_id text NOT NULL,
    customer_id text NOT NULL,
    state text NOT NULL DEFAULT 'pending'
        CHECK (state IN ('pending','approved'))
);
INSERT INTO portal_signed_lab.orders(id,organization_id,customer_id) VALUES
 ('TEST-signed-aa','test-org-a','test-customer-a'),
 ('TEST-signed-ab','test-org-a','test-customer-b'),
 ('TEST-signed-ba','test-org-b','test-customer-a');
ALTER TABLE portal_signed_lab.orders ENABLE ROW LEVEL SECURITY;
ALTER TABLE portal_signed_lab.orders FORCE ROW LEVEL SECURITY;
CREATE POLICY signed_owner_only ON portal_signed_lab.orders
 FOR ALL
 USING (
    portal_signed_lab.context_ok()
    AND organization_id=current_setting('app.signed_org',true)
    AND customer_id=current_setting('app.signed_customer',true)
 )
 WITH CHECK (
    portal_signed_lab.context_ok()
    AND organization_id=current_setting('app.signed_org',true)
    AND customer_id=current_setting('app.signed_customer',true)
 );
GRANT SELECT,INSERT ON portal_signed_lab.orders TO owner_ci_shared;
GRANT UPDATE (state) ON portal_signed_lab.orders TO owner_ci_shared;
SQL);
$addSecret=$admin->prepare('INSERT INTO portal_signed_lab.signing_keys(scope,secret) VALUES (?,?::bytea)');
$addSecret->execute(['preview','\\x'.bin2hex($issuerSecret)]);
$shared=new PDO($dsn,'owner_ci_shared','ci-shared-runtime-only',$opts);

function listSigned(PDO $db): array {
    return $db->query('SELECT id,organization_id,customer_id,state
         FROM portal_signed_lab.orders ORDER BY id')->fetchAll();
}
function updateSigned(PDO $db,string $id): int {
    $q=$db->prepare("UPDATE portal_signed_lab.orders SET state='approved' WHERE id=?");
    $q->execute([$id]); return $q->rowCount();
}
function signedContext(string $org,string $customer,string $expires,
                       string $nonce,string $secret): array {
    $payload=$org."\n".$customer."\n".$expires."\n".$nonce;
    return [$org,$customer,$expires,$nonce,hash_hmac('sha256',$payload,$secret)];
}
function applySigned(PDO $db,array $context): void {
    $names=['app.signed_org','app.signed_customer','app.signed_expires',
            'app.signed_nonce','app.signed_mac'];
    foreach ($names as $i=>$name) {
        $set=$db->prepare('SELECT set_config(?,?,true)');
        $set->execute([$name,$context[$i]]);
    }
}
function withSigned(PDO $db,array $context,callable $assert): void {
    $db->beginTransaction();
    try {
        applySigned($db,$context);
        $assert($db);
    } finally {
        if ($db->inTransaction()) $db->rollBack();
    }
}

proof(listSigned($shared)===[],'No signed context must show zero rows.');
deny(static fn()=>$shared->query('SELECT secret FROM portal_signed_lab.signing_keys'),
     'Checkout SQL login must not extract the signer secret.');
deny(static fn()=>$shared->exec('SET ROLE postgres'),
     'Checkout SQL login may not become signer or superuser.');
deny(static fn()=>$shared->exec("UPDATE portal_signed_lab.signing_keys SET scope='leak'"),
     'Checkout SQL login may not mutate signer custody.');
proof((int)$admin->query("SELECT count(*) FROM portal_signed_lab.orders")->fetchColumn()===3,
      'Admin-only fixture must have three customers in two companies.');

$expires=(string)(time()+45);
$nonce=bin2hex(random_bytes(16));
$grantA=signedContext('test-org-a','test-customer-a',$expires,$nonce,$issuerSecret);
withSigned($shared,$grantA,static function(PDO $db): void {
    proof((bool)$db->query('SELECT portal_signed_lab.context_ok()')->fetchColumn(),
          'Issuer-generated A signature must validate.');
    $rows=listSigned($db);
    proof(count($rows)===1 && $rows[0]['id']==='TEST-signed-aa',
          'Same-tenant customer B must be invisible to signed A.');
    proof(updateSigned($db,'TEST-signed-ab')===0,
          'Signed A cannot mutate another customer in same tenant.');
    proof(updateSigned($db,'TEST-signed-ba')===0,
          'Signed A cannot mutate another company.');
    // A malicious request can SET arbitrary GUC values, but the DB's
    // independent HMAC verifier must not authorize the forgery.
    $change=$db->prepare("SELECT set_config('app.signed_customer',?,true)");
    $change->execute(['test-customer-b']);
    proof(!(bool)$db->query('SELECT portal_signed_lab.context_ok()')->fetchColumn(),
          'Forging the user GUC must break the HMAC.');
    proof(listSigned($db)===[],'Unsigned user forgery must see zero rows.');
    proof(updateSigned($db,'TEST-signed-ab')===0,
          'Unsigned forgery must not alter any neighboring order.');
});
proof(listSigned($shared)===[],
      'Transaction-local signed identity must vanish after transaction rollback.');

$grantB=signedContext('test-org-a','test-customer-b',$expires,
                      bin2hex(random_bytes(16)),$issuerSecret);
withSigned($shared,$grantB,static function(PDO $db): void {
    $rows=listSigned($db);
    proof(count($rows)===1 && $rows[0]['id']==='TEST-signed-ab',
          'Signed B must not inherit A authorization on the same DB login.');
    proof(updateSigned($db,'TEST-signed-aa')===0,'B cannot mutate A.');
    proof(updateSigned($db,'TEST-signed-ab')===1,'B can update own synthetic order.');
});
$grantOther=signedContext('test-org-b','test-customer-a',$expires,
                         bin2hex(random_bytes(16)),$issuerSecret);
withSigned($shared,$grantOther,static function(PDO $db): void {
    $rows=listSigned($db);
    proof(count($rows)===1 && $rows[0]['id']==='TEST-signed-ba',
          'Same customer label in another organization is separately scoped.');
    // An independently signed B context cannot be broadened by changing org.
    $switch=$db->prepare("SELECT set_config('app.signed_org',?,true)");
    $switch->execute(['test-org-a']);
    proof(listSigned($db)===[],'Spoofed organization must fail HMAC validation.');
});
$expired=signedContext('test-org-a','test-customer-a',(string)(time()-5),
                       bin2hex(random_bytes(16)),$issuerSecret);
withSigned($shared,$expired,static function(PDO $db): void {
    proof(listSigned($db)===[],
          'Cryptographically correct but expired context must not authorize.');
});
$badMac=$grantA;
$badMac[4]=str_repeat('0',64);
withSigned($shared,$badMac,static function(PDO $db): void {
    proof(listSigned($db)===[],'Forged MAC must not grant access.');
});
// Revisit the role after closing its original connection; it must remain
// unauthorized without a fresh signed, time-limited IAM assertion.
$reopened=new PDO($dsn,'owner_ci_shared','ci-shared-runtime-only',$opts);
proof(listSigned($reopened)===[],
      'New SQL connection must not inherit previous customer identity.');
echo "PASS {$count} signed owner SQL checks: one restricted DB login, distinct issuer key, same-tenant isolation, HMAC tamper/expiry, no ambient identity; CI ONLY / NO SERVING\n";
