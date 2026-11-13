<?php

declare(strict_types=1);

require __DIR__.'/../app/Homologation/PersistedTestPurchase.php';
use App\Homologation\PersistedTestPurchase;

function connection(): PDO {
    return new PDO('pgsql:host=127.0.0.1;port=5432;dbname=portal_purchase_rehearsal','postgres','synthetic-ci-only',[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
}
function check(bool $value,string $label): void { if (!$value) throw new RuntimeException($label); }
function denied(callable $operation): void {
    try { $operation(); } catch (RuntimeException|InvalidArgumentException $e) { return; }
    throw new RuntimeException('Expected rejection.');
}
$db=connection();
$db->exec("CREATE SCHEMA purchase_rehearsal; CREATE TABLE purchase_rehearsal.orders (
 id text PRIMARY KEY,tenant text NOT NULL CHECK (tenant IN ('test-org-a','test-org-b')),
 request_key text NOT NULL,product text NOT NULL CHECK (product IN ('onepage','landing-pages','performance')),
 payment text NOT NULL DEFAULT 'pending' CHECK (payment IN ('pending','declined','approved')),
 revision integer NOT NULL DEFAULT 0 CHECK (revision>=0),briefing jsonb,
 UNIQUE(tenant,request_key), CHECK (briefing IS NULL OR payment='approved'))");
$store=new PersistedTestPurchase($db);
foreach (['onepage','landing-pages','performance'] as $product) {
    $key='TEST-request-'.$product;
    $order=$store->create('test-org-a',$key,$product);
    check($store->create('test-org-a',$key,$product)===$order,'Exact replay');
    denied(fn()=>$store->create('test-org-a',$key,$product==='onepage'?'performance':'onepage'));
    denied(fn()=>$store->read('test-org-b',$order['id']));
    $brief=['business'=>'Empresa fictícia','goal'=>'Objetivo fictício','audience'=>'Público fictício','materials'=>'Somente amostras'];
    denied(fn()=>$store->briefing('test-org-a',$order['id'],0,$brief));
    $declined=$store->payment('test-org-a',$order['id'],0,'declined');
    denied(fn()=>$store->briefing('test-org-a',$order['id'],1,$brief));
    denied(fn()=>$store->payment('test-org-a',$order['id'],0,'approved'));
    $pending=$store->payment('test-org-a',$order['id'],1,'pending');
    denied(fn()=>$store->briefing('test-org-a',$order['id'],2,$brief));
    $approved=$store->payment('test-org-a',$order['id'],2,'approved');
    denied(fn()=>$store->briefing('test-org-a',$order['id'],3,['goal'=>'incompleto']));
    $complete=$store->briefing('test-org-a',$order['id'],3,$brief);
    // A new database connection and service instance must read the saved data.
    $reloaded=(new PersistedTestPurchase(connection()))->read('test-org-a',$order['id']);
    check($complete===$reloaded && $reloaded['briefing']==$brief,'Durable reload');
    check($complete['real_payment']===false && $complete['authorizes_http']===false,'No operational authority');
    denied(fn()=>$store->payment('test-org-a',$order['id'],4,'declined'));
    denied(fn()=>$store->briefing('test-org-b',$order['id'],4,$brief));
    $other=$store->create('test-org-b',$key,$product);
    check($other['id']!==$order['id'],'Distinct tenant order');
    echo 'PASS persisted purchase '.$product.PHP_EOL;
}
denied(fn()=>$store->create('real-company','TEST-real-request','onepage'));
denied(fn()=>$store->create('test-org-a','TEST-unknown-request','unknown'));
check((int)$db->query('SELECT count(*) FROM purchase_rehearsal.orders')->fetchColumn()===6,'No duplicated orders');
echo 'PASS replay, conflict, payment gating, tenant mismatch, stale revision, durable briefing'.PHP_EOL;


/*
 * P0 isolation step: same real PostgreSQL table, restricted login roles,
 * database-enforced tenant boundary, not a browser-controlled tenant string.
 * Everything below runs on a disposable CI Postgres cluster only.
 */
$db->exec("CREATE ROLE purchase_rehearsal_a WITH LOGIN PASSWORD 'ci-synthetic-a-only' NOSUPERUSER NOBYPASSRLS NOINHERIT NOCREATEDB NOCREATEROLE NOREPLICATION");
$db->exec("CREATE ROLE purchase_rehearsal_b WITH LOGIN PASSWORD 'ci-synthetic-b-only' NOSUPERUSER NOBYPASSRLS NOINHERIT NOCREATEDB NOCREATEROLE NOREPLICATION");
$db->exec('GRANT CONNECT ON DATABASE portal_purchase_rehearsal TO purchase_rehearsal_a, purchase_rehearsal_b');
$db->exec('GRANT USAGE ON SCHEMA purchase_rehearsal TO purchase_rehearsal_a, purchase_rehearsal_b');
$db->exec('GRANT SELECT, INSERT, UPDATE ON purchase_rehearsal.orders TO purchase_rehearsal_a, purchase_rehearsal_b');
$db->exec('ALTER TABLE purchase_rehearsal.orders ENABLE ROW LEVEL SECURITY');
$db->exec('ALTER TABLE purchase_rehearsal.orders FORCE ROW LEVEL SECURITY');
$trustedRoleTenant = "CASE current_user WHEN 'purchase_rehearsal_a' THEN 'test-org-a' WHEN 'purchase_rehearsal_b' THEN 'test-org-b' ELSE NULL END";
$db->exec('CREATE POLICY purchase_rehearsal_tenant ON purchase_rehearsal.orders FOR ALL USING (tenant = ('.$trustedRoleTenant.')) WITH CHECK (tenant = ('.$trustedRoleTenant.'))');

function limitedConnection(string $role): PDO {
    $users = [
        'purchase_rehearsal_a' => 'ci-synthetic-a-only',
        'purchase_rehearsal_b' => 'ci-synthetic-b-only',
    ];
    if (!isset($users[$role])) throw new InvalidArgumentException('Unknown CI role.');
    return new PDO('pgsql:host=127.0.0.1;port=5432;dbname=portal_purchase_rehearsal',
        $role, $users[$role], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
}
$restrictedA = limitedConnection('purchase_rehearsal_a');
$restrictedB = limitedConnection('purchase_rehearsal_b');
foreach ([
    ['purchase_rehearsal_a', $restrictedA],
    ['purchase_rehearsal_b', $restrictedB],
] as [$role, $pdo]) {
    check($pdo->query('SELECT current_user')->fetchColumn() === $role, 'DB-authenticated role must match server identity');
    $stmt = $db->prepare('SELECT rolsuper, rolbypassrls, rolcreatedb, rolcreaterole FROM pg_roles WHERE rolname = ?');
    $stmt->execute([$role]);
    $flags = $stmt->fetch(PDO::FETCH_NUM);
    check($flags !== false && in_array($flags[0], [false,'f',0,'0'], true)
        && in_array($flags[1], [false,'f',0,'0'], true)
        && in_array($flags[2], [false,'f',0,'0'], true)
        && in_array($flags[3], [false,'f',0,'0'], true),
        'Test runtime roles must have no escalation or RLS bypass');
}
check((bool)$db->query("SELECT relrowsecurity AND relforcerowsecurity FROM pg_class WHERE oid='purchase_rehearsal.orders'::regclass")->fetchColumn(),
    'Expected FORCE RLS enabled for the actual table');
$actorA = new PersistedTestPurchase($restrictedA);
$actorB = new PersistedTestPurchase($restrictedB);
$request = 'TEST-restricted-same-key-0001';
$a = $actorA->create('test-org-a', $request, 'onepage');
$b = $actorB->create('test-org-b', $request, 'onepage');
check($a['id'] !== $b['id'], 'Idempotency scope must remain per real DB role and organization');
check($actorA->create('test-org-a', $request, 'onepage') === $a, 'Restricted actor idempotency');
denied(fn() => $actorB->read('test-org-a', $a['id']));
denied(fn() => $actorA->read('test-org-b', $b['id']));
denied(fn() => $actorB->payment('test-org-a', $a['id'], 0, 'approved'));
denied(fn() => $actorB->create('test-org-a', 'TEST-restricted-foreign-0001', 'onepage'));

// Crucially, bypassing the PHP store is denied by the DATABASE policy.
$query = $restrictedB->prepare('SELECT count(*) FROM purchase_rehearsal.orders WHERE id=?');
$query->execute([$a['id']]);
check((int)$query->fetchColumn() === 0, 'Raw SQL may not reveal another organization');
$query = $restrictedB->prepare("UPDATE purchase_rehearsal.orders SET payment='approved' WHERE id=?");
$query->execute([$a['id']]);
check($query->rowCount() === 0, 'Raw SQL cannot mutate another organization');
denied(fn() => $restrictedB->exec("INSERT INTO purchase_rehearsal.orders (id, tenant, request_key, product) VALUES ('TEST-".str_repeat('a',32)."','test-org-a','TEST-ci-forged-0001','onepage')"));
denied(fn() => $restrictedB->exec('DELETE FROM purchase_rehearsal.orders'));
denied(fn() => $restrictedB->exec('SET ROLE purchase_rehearsal_a'));

$approved = $actorA->payment('test-org-a', $a['id'], 0, 'approved');
$brief = [
    'business' => 'Loja de exemplo',
    'goal' => 'Divulgar serviço fictício',
    'audience' => 'Clientes fictícios',
    'materials' => 'Imagens de amostra',
];
$completed = $actorA->briefing('test-org-a', $a['id'], $approved['revision'], $brief);
$reconnected = (new PersistedTestPurchase(limitedConnection('purchase_rehearsal_a')))
    ->read('test-org-a', $a['id']);
check($completed === $reconnected && $reconnected['briefing'] == $brief,
    'Limited runtime role must persist equivalent JSONB briefing across new DB connection');
check($actorB->read('test-org-b', $b['id']) === $b,
    'Neighbor organization cannot be affected by other tenant changes');
echo 'PASS restricted PostgreSQL login roles, FORCE RLS and cross-tenant deny at SQL boundary'.PHP_EOL;


// Next P0 slice: the caller cannot even supply an organization to this API.
// Its scope comes only from the authenticated restricted PostgreSQL login.
require __DIR__.'/../app/Homologation/RoleBoundTestPurchase.php';
denied(fn() => new \App\Homologation\RoleBoundTestPurchase($db)); // superuser blocked
$boundA = new \App\Homologation\RoleBoundTestPurchase(limitedConnection('purchase_rehearsal_a'));
$boundB = new \App\Homologation\RoleBoundTestPurchase(limitedConnection('purchase_rehearsal_b'));
$boundOrder = $boundA->create('TEST-role-bound-landing-00001', 'landing-pages');
check($boundA->create('TEST-role-bound-landing-00001', 'landing-pages') === $boundOrder,
    'Role-bound idempotent creation is scoped to DB login');
denied(fn() => $boundB->read($boundOrder['id']));
denied(fn() => $boundB->payment($boundOrder['id'], 0, 'approved'));
denied(fn() => $boundB->briefing($boundOrder['id'], 0, $brief));
$paid = $boundA->payment($boundOrder['id'], 0, 'approved');
$written = $boundA->briefing($boundOrder['id'], $paid['revision'], $brief);
$readerAfterNewLogin = new \App\Homologation\RoleBoundTestPurchase(
    limitedConnection('purchase_rehearsal_a')
);
check($readerAfterNewLogin->read($boundOrder['id']) === $written,
    'Role-bound briefing must persist across an entirely new limited connection');
check($written['synthetic'] === true && $written['authorizes_http'] === false
    && \App\Homologation\RoleBoundTestPurchase::authorizesCustomerHttp() === false,
    'Role-bound store must never authorize customer HTTP');
echo 'PASS role-bound purchase prevents caller-controlled tenant selection and keeps NO-SERVING'.PHP_EOL;
