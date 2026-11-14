<?php

declare(strict_types=1);

/**
 * CI-ONLY PostgreSQL 18 exercise of future per-customer ownership.
 *
 * This creates roles, schema and rows ONLY in the disposable database
 * portal_ownership_ci. It does not migrate the Portal or activate serving.
 *
 * Customer identity is the authenticated SQL login; no browser-provided actor,
 * session variable or client-supplied tenant can select another owner.
 */
if (getenv('GITHUB_ACTIONS') !== 'true'
    || getenv('PORTAL_P0_OWNER_PG_LAB') !== '1'
    || getenv('PORTAL_OWNER_PG_HOST') !== '127.0.0.1'
    || getenv('PORTAL_OWNER_PG_DB') !== 'portal_ownership_ci') {
    throw new RuntimeException('Disposable GitHub PostgreSQL ownership lab required.');
}
require __DIR__.'/../app/Homologation/SyntheticPurchaseOwnership.php';

use App\Homologation\SyntheticPurchaseOwnership;

$dsn = 'pgsql:host=127.0.0.1;port=5432;dbname=portal_ownership_ci';
$options = [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC];
$db = new PDO($dsn, 'postgres', 'ci-postgres-admin-synthetic', $options);
$checks = 0;

function checkOwner(bool $condition, string $message): void {
    global $checks;
    if (!$condition) throw new RuntimeException($message);
    ++$checks;
}
function rejectedOwner(callable $action, string $message): void {
    try {
        $action();
    } catch (PDOException $exception) {
        checkOwner($exception->getCode() !== '00000', $message);
        return;
    }
    throw new RuntimeException($message);
}

$db->exec(<<<'SQL'
CREATE SCHEMA portal_ownership_lab;
CREATE TABLE portal_ownership_lab.orders (
    id text PRIMARY KEY,
    organization_id text NOT NULL,
    customer_id text,
    request_key text NOT NULL,
    product text NOT NULL CHECK (product IN ('onepage','landing-pages','performance')),
    state text NOT NULL DEFAULT 'pending' CHECK (state IN ('pending','approved')),
    UNIQUE (organization_id, customer_id, request_key),
    CHECK (id ~ '^TEST-[a-zA-Z0-9-]{8,64}$')
);
CREATE ROLE owner_ci_a LOGIN PASSWORD 'ci-owner-a-only' NOSUPERUSER NOBYPASSRLS NOINHERIT NOCREATEDB NOCREATEROLE NOREPLICATION;
CREATE ROLE owner_ci_b LOGIN PASSWORD 'ci-owner-b-only' NOSUPERUSER NOBYPASSRLS NOINHERIT NOCREATEDB NOCREATEROLE NOREPLICATION;
CREATE ROLE owner_ci_c LOGIN PASSWORD 'ci-owner-c-only' NOSUPERUSER NOBYPASSRLS NOINHERIT NOCREATEDB NOCREATEROLE NOREPLICATION;
GRANT CONNECT ON DATABASE portal_ownership_ci TO owner_ci_a,owner_ci_b,owner_ci_c;
GRANT USAGE ON SCHEMA portal_ownership_lab TO owner_ci_a,owner_ci_b,owner_ci_c;
GRANT SELECT,INSERT ON portal_ownership_lab.orders TO owner_ci_a,owner_ci_b,owner_ci_c;
GRANT UPDATE (state) ON portal_ownership_lab.orders TO owner_ci_a,owner_ci_b,owner_ci_c;
ALTER TABLE portal_ownership_lab.orders ENABLE ROW LEVEL SECURITY;
ALTER TABLE portal_ownership_lab.orders FORCE ROW LEVEL SECURITY;
CREATE POLICY per_authenticated_db_owner ON portal_ownership_lab.orders
    FOR ALL
    USING (
        organization_id = CASE current_user
            WHEN 'owner_ci_a' THEN 'test-org-a'
            WHEN 'owner_ci_b' THEN 'test-org-a'
            WHEN 'owner_ci_c' THEN 'test-org-b'
            ELSE NULL END
        AND customer_id = CASE current_user
            WHEN 'owner_ci_a' THEN 'test-customer-a'
            WHEN 'owner_ci_b' THEN 'test-customer-b'
            WHEN 'owner_ci_c' THEN 'test-customer-a'
            ELSE NULL END
    )
    WITH CHECK (
        organization_id = CASE current_user
            WHEN 'owner_ci_a' THEN 'test-org-a'
            WHEN 'owner_ci_b' THEN 'test-org-a'
            WHEN 'owner_ci_c' THEN 'test-org-b'
            ELSE NULL END
        AND customer_id = CASE current_user
            WHEN 'owner_ci_a' THEN 'test-customer-a'
            WHEN 'owner_ci_b' THEN 'test-customer-b'
            WHEN 'owner_ci_c' THEN 'test-customer-a'
            ELSE NULL END
    );
SQL);

$roles = [
    'owner_ci_a' => ['ci-owner-a-only','test-org-a','test-customer-a'],
    'owner_ci_b' => ['ci-owner-b-only','test-org-a','test-customer-b'],
    'owner_ci_c' => ['ci-owner-c-only','test-org-b','test-customer-a'],
];
$conns = [];
foreach ($roles as $role => [$password, $organization, $customer]) {
    $conn = new PDO($dsn, $role, $password, $options);
    $conns[$role] = $conn;
    $loggedIn = $conn->query('SELECT session_user AS login,current_user AS role')->fetch();
    checkOwner($loggedIn === ['login'=>$role,'role'=>$role], 'Authenticated DB role mismatch');
    $flags = $db->prepare('SELECT rolsuper, rolbypassrls, rolcreaterole, rolcreatedb FROM pg_roles WHERE rolname=?');
    $flags->execute([$role]);
    $privileges = $flags->fetch();
    checkOwner($privileges !== false
        && !in_array(true, array_values($privileges), true),
        'CI customer may not have superuser, bypassRLS, CreateRole or CreateDB');
}
$force = $db->query("SELECT relrowsecurity AND relforcerowsecurity FROM pg_class
                     WHERE oid='portal_ownership_lab.orders'::regclass")->fetchColumn();
checkOwner((bool)$force, 'FORCE RLS must be enabled');

// The prior shared-org version has records without an owner. They must remain
// invisible: do not automatically adopt legacy rows for any customer.
$legacy = $db->prepare('INSERT INTO portal_ownership_lab.orders
    (id,organization_id,customer_id,request_key,product)
    VALUES (?,?,?,?,?)');
$legacy->execute(['TEST-legacy-unknown','test-org-a',null,'legacy-key','onepage']);
$legacy->execute(['TEST-legacy-b-unknown','test-org-b',null,'legacy-key','onepage']);

function listOrders(PDO $pdo): array {
    return $pdo->query('SELECT id,organization_id,customer_id,request_key,product,state
                        FROM portal_ownership_lab.orders ORDER BY id')
               ->fetchAll();
}

/** Synthetic idempotent create with a strict product-conflict fence. */
function createOwnerOrder(PDO $pdo, string $id, string $org, string $customer, string $key, string $product): array {
    $record = new SyntheticPurchaseOwnership($id, $org, $customer);
    checkOwner($record->belongsTo($org, $customer), 'Synthetic ownership must match');
    $pdo->beginTransaction();
    try {
        $insert=$pdo->prepare('INSERT INTO portal_ownership_lab.orders
            (id,organization_id,customer_id,request_key,product)
            VALUES (?,?,?,?,?) ON CONFLICT (organization_id,customer_id,request_key) DO NOTHING');
        $insert->execute([$id,$org,$customer,$key,$product]);
        $query=$pdo->prepare('SELECT id,organization_id,customer_id,request_key,product,state
            FROM portal_ownership_lab.orders
            WHERE organization_id=? AND customer_id=? AND request_key=?');
        $query->execute([$org,$customer,$key]);
        $row=$query->fetch();
        if (!is_array($row) || $row['product'] !== $product) {
            throw new RuntimeException('Conflicting request key or inaccessible order.');
        }
        $pdo->commit();
        return $row;
    } catch (Throwable $exception) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $exception;
    }
}

$a = $conns['owner_ci_a'];
$b = $conns['owner_ci_b'];
$c = $conns['owner_ci_c'];
$aOrder = createOwnerOrder($a,'TEST-owner-a-12345678','test-org-a','test-customer-a','same-request-key','onepage');
$bOrder = createOwnerOrder($b,'TEST-owner-b-12345678','test-org-a','test-customer-b','same-request-key','landing-pages');
$cOrder = createOwnerOrder($c,'TEST-owner-c-12345678','test-org-b','test-customer-a','same-request-key','performance');
checkOwner(count(listOrders($a))===1 && count(listOrders($b))===1 && count(listOrders($c))===1,
    'Every individual customer must read exactly one order, never legacy or neighbor rows.');
checkOwner($aOrder['id'] !== $bOrder['id'] && $bOrder['id'] !== $cOrder['id'],
    'Same request key must be independent per individual customer and organization.');
checkOwner(createOwnerOrder($a,'TEST-new-attempt-12345678','test-org-a','test-customer-a','same-request-key','onepage')===$aOrder,
    'Same owner idempotency must return the original durable order, not create a second.');
try {
    createOwnerOrder($a,'TEST-conflict-product-12345678','test-org-a','test-customer-a','same-request-key','performance');
    throw new RuntimeException('Product collision must be refused.');
} catch (RuntimeException $e) {
    checkOwner($e->getMessage()==='Conflicting request key or inaccessible order.',
        'A same-owner request key must reject conflicting product.');
}
checkOwner(count(listOrders($a))===1, 'Conflicting replay must not create a new order.');
foreach (['owner_ci_a','owner_ci_b','owner_ci_c'] as $role) {
    $session = $conns[$role];
    rejectedOwner(static fn()=>$session->exec(
        "SET ROLE ".($role==='owner_ci_a'?'owner_ci_b':'owner_ci_a')),
        'Restricted client must not impersonate a neighbor role.');
    rejectedOwner(static fn()=>$session->exec(
        'DELETE FROM portal_ownership_lab.orders'),
        'No customer role may delete orders.');
    // RLS is bound to database identity, not easily spoofed HTTP-style vars.
    $session->exec("SELECT set_config('app.client_id','test-customer-a',false)");
    checkOwner(count(listOrders($session))===1,
        'Untrusted session vars must not widen ownership policy.');
}
$forge=$b->prepare('INSERT INTO portal_ownership_lab.orders
    (id,organization_id,customer_id,request_key,product)
    VALUES (?,?,?,?,?)');
rejectedOwner(static fn()=>$forge->execute([
    'TEST-forged-owner-12345678','test-org-a','test-customer-a','owner-forgery','onepage'
]), 'RLS must deny forged customer ID even within the SAME organization.');
$forgeTenant=$a->prepare('INSERT INTO portal_ownership_lab.orders
    (id,organization_id,customer_id,request_key,product)
    VALUES (?,?,?,?,?)');
rejectedOwner(static fn()=>$forgeTenant->execute([
    'TEST-forged-tenant-12345678','test-org-b','test-customer-a','tenant-forgery','onepage'
]), 'RLS must deny forged organization, even for same customer label.');
// Column-level GRANT forbids reassigning immutable ownership.
rejectedOwner(static fn()=>$a->exec("UPDATE portal_ownership_lab.orders
    SET customer_id='test-customer-b' WHERE id='TEST-owner-a-12345678'"),
    'Clients must not reassign order ownership.');
$neighborMutation = $a->prepare("UPDATE portal_ownership_lab.orders SET state='approved' WHERE id=?");
$neighborMutation->execute([$bOrder['id']]);
checkOwner($neighborMutation->rowCount()===0, 'Cannot update another customer order in same org.');
$neighborMutation->execute([$cOrder['id']]);
checkOwner($neighborMutation->rowCount()===0, 'Cannot update another organization.');
$neighborMutation->execute([$aOrder['id']]);
checkOwner($neighborMutation->rowCount()===1, 'Own order may advance in this simulated DB rehearsal.');
$aAfterRestart = new PDO($dsn,'owner_ci_a','ci-owner-a-only',$options);
checkOwner(listOrders($aAfterRestart)[0]['state']==='approved', 'Order must survive new PostgreSQL login.');
checkOwner(count(listOrders($aAfterRestart))===1, 'No legacy/other customer data can appear after reconnect.');
checkOwner((int)$db->query('SELECT count(*) FROM portal_ownership_lab.orders')->fetchColumn()===5,
    'Exactly three new owned orders and two ownerless legacy records expected.');
echo "PASS {$checks} PostgreSQL 18 ownership checks: 2 same-org customers, other org, atomic per-owner replay, 2 invisible legacy rows, DB FORCE RLS; NO HTTP/DEPLOY\n";
