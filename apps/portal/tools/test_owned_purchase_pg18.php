<?php

declare(strict_types=1);

/**
 * P0 OWN-02 — disposable CI experiment: atomic synthetic customer ownership.
 *
 * This PHP file lives under tools/, is NOT autoloaded by Laravel, and cannot
 * be run as a public endpoint. It deliberately proves the DAL transition
 * while documenting that shared-tenant SQL credentials are not sufficient
 * to prevent a compromised application from bypassing customer ownership.
 */
if (PHP_SAPI !== 'cli' || getenv('GITHUB_ACTIONS') !== 'true'
    || getenv('PORTAL_OWNED_PURCHASE_PG18_CI') !== '1') {
    fwrite(STDERR, "P0 OWN-02 refused outside explicitly isolated GitHub CI.\n");
    exit(1);
}

require __DIR__.'/../app/Homologation/PersistedTestPurchase.php';
require __DIR__.'/../app/Homologation/RoleBoundTestPurchase.php';
require __DIR__.'/../app/Homologation/SyntheticPurchaseOwnership.php';

use App\Homologation\RoleBoundTestPurchase;
use App\Homologation\SyntheticPurchaseOwnership;

final class OwnedPurchasePg18Laboratory
{
    private readonly string $tenant;
    private readonly string $customer;

    public function __construct(private readonly PDO $db, string $customer)
    {
        $this->tenant = (new RoleBoundTestPurchase($db))->organization();
        // A real customer ID must be obtained from server-verified membership;
        // this demo ONLY receives synthetic IDs from the CI fixture.
        new SyntheticPurchaseOwnership(
            'TEST-owner-check-12345678', $this->tenant, $customer
        );
        $this->customer = $customer;
        $this->db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->db->setAttribute(PDO::ATTR_EMULATE_PREPARES, false);
        $hasColumn = $db->query(
            "SELECT count(*) FROM information_schema.columns
             WHERE table_schema='purchase_rehearsal'
             AND table_name='orders' AND column_name='customer_id'"
        )->fetchColumn();
        if ((int) $hasColumn !== 1) {
            throw new RuntimeException('Explicit synthetic ownership column required.');
        }
    }

    private function scopedKey(string $key): string
    {
        if (preg_match('/\ATEST-[A-Za-z0-9-]{8,64}\z/D', $key) !== 1) {
            throw new InvalidArgumentException('Synthetic idempotency key required.');
        }
        // Same HTTP idempotency key can be used by different test customers.
        // No original user-provided customer ID/tenant is trusted by the store.
        return 'TEST-'.hash('sha256', $this->tenant."\0".$this->customer."\0".$key);
    }

    private function transaction(callable $operation): array
    {
        if ($this->db->inTransaction()) {
            throw new RuntimeException('Nested synthetic transaction denied.');
        }
        $this->db->beginTransaction();
        try {
            $result = $operation();
            $this->db->commit();
            return $result;
        } catch (Throwable $e) {
            if ($this->db->inTransaction()) $this->db->rollBack();
            throw $e;
        }
    }

    public function create(string $idempotencyKey, string $product): array
    {
        if (!in_array($product, ['onepage', 'landing-pages', 'performance'], true)) {
            throw new InvalidArgumentException('Unknown synthetic offer.');
        }
        $key = $this->scopedKey($idempotencyKey);
        $id = 'TEST-'.bin2hex(random_bytes(16));

        return $this->transaction(function () use ($key, $product, $id): array {
            // id, organization AND owner are written in ONE SQL transaction.
            // No intermediate unowned row is committed or exposed.
            $insert = $this->db->prepare(
                'INSERT INTO purchase_rehearsal.orders
                 (id,tenant,customer_id,request_key,product)
                 VALUES (?,?,?,?,?)
                 ON CONFLICT (tenant,request_key) DO NOTHING'
            );
            $insert->execute([$id, $this->tenant, $this->customer, $key, $product]);
            $query = $this->db->prepare(
                'SELECT id,tenant,customer_id,request_key,product,payment,revision,briefing
                 FROM purchase_rehearsal.orders
                 WHERE tenant=? AND customer_id=? AND request_key=? FOR UPDATE'
            );
            $query->execute([$this->tenant, $this->customer, $key]);
            $row = $query->fetch(PDO::FETCH_ASSOC);
            if (!is_array($row) || $row['product'] !== $product) {
                throw new RuntimeException('Synthetic idempotency conflict.');
            }
            return $this->result($row);
        });
    }

    private function row(string $orderId, bool $lock): array
    {
        if (preg_match('/\ATEST-[a-f0-9]{32}\z/D', $orderId) !== 1) {
            throw new RuntimeException('Order unavailable.');
        }
        $query = $this->db->prepare(
            'SELECT id,tenant,customer_id,request_key,product,payment,revision,briefing
             FROM purchase_rehearsal.orders
             WHERE id=? AND tenant=? AND customer_id=?'
            .($lock ? ' FOR UPDATE' : '')
        );
        $query->execute([$orderId, $this->tenant, $this->customer]);
        $row = $query->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row)) throw new RuntimeException('Order unavailable.');
        return $row;
    }

    public function read(string $orderId): array
    {
        return $this->result($this->row($orderId, false));
    }

    public function rows(): array
    {
        $query = $this->db->prepare(
            'SELECT id,tenant,customer_id,request_key,product,payment,revision,briefing
             FROM purchase_rehearsal.orders
             WHERE tenant=? AND customer_id=? ORDER BY id LIMIT 50'
        );
        $query->execute([$this->tenant, $this->customer]);
        return array_map($this->result(...), $query->fetchAll(PDO::FETCH_ASSOC));
    }

    public function payment(string $orderId, int $revision, string $outcome): array
    {
        if (!in_array($outcome, ['pending', 'declined', 'approved'], true)) {
            throw new InvalidArgumentException('Unknown simulated outcome.');
        }
        return $this->transaction(function () use ($orderId, $revision, $outcome): array {
            $row = $this->row($orderId, true);
            if ((int) $row['revision'] !== $revision || $row['briefing'] !== null) {
                throw new RuntimeException('Stale or completed sample payment.');
            }
            $q = $this->db->prepare(
                'UPDATE purchase_rehearsal.orders SET payment=?, revision=revision+1
                 WHERE id=? AND tenant=? AND customer_id=? AND revision=? RETURNING *'
            );
            $q->execute([$outcome, $orderId, $this->tenant, $this->customer, $revision]);
            $updated = $q->fetch(PDO::FETCH_ASSOC);
            if (!is_array($updated)) throw new RuntimeException('Owner-scoped payment denied.');
            return $this->result($updated);
        });
    }

    public function briefing(string $orderId, int $revision, array $data): array
    {
        $allowed = ['business','goal','audience','materials'];
        $keys = array_keys($data);
        sort($keys, SORT_STRING);
        $sorted = $allowed;
        sort($sorted, SORT_STRING);
        if ($keys !== $sorted) throw new InvalidArgumentException('Four sample fields required.');
        foreach ($allowed as $field) {
            if (!is_string($data[$field]) || trim($data[$field]) === ''
                || strlen($data[$field]) > 800) {
                throw new InvalidArgumentException('Invalid synthetic briefing.');
            }
            $data[$field] = trim($data[$field]);
        }
        return $this->transaction(function () use ($orderId, $revision, $data): array {
            $row = $this->row($orderId, true);
            if ($row['payment'] !== 'approved' || (int) $row['revision'] !== $revision
                || $row['briefing'] !== null) {
                throw new RuntimeException('Sample payment/briefing state denied.');
            }
            $query = $this->db->prepare(
                'UPDATE purchase_rehearsal.orders
                 SET briefing=?::jsonb, revision=revision+1
                 WHERE id=? AND tenant=? AND customer_id=? AND revision=?
                 RETURNING *'
            );
            $query->execute([
                json_encode($data, JSON_THROW_ON_ERROR),
                $orderId, $this->tenant, $this->customer, $revision
            ]);
            $updated = $query->fetch(PDO::FETCH_ASSOC);
            if (!is_array($updated)) throw new RuntimeException('Owner-scoped briefing denied.');
            return $this->result($updated);
        });
    }

    private function result(array $row): array
    {
        if ($row['tenant'] !== $this->tenant
            || !hash_equals($row['customer_id'] ?? '', $this->customer)) {
            throw new RuntimeException('Synthetic owner mismatch.');
        }
        return [
            'id' => $row['id'],
            'product' => $row['product'],
            'payment' => $row['payment'],
            'revision' => (int) $row['revision'],
            'briefing' => $row['briefing'] === null
                ? null : json_decode($row['briefing'], true, 16, JSON_THROW_ON_ERROR),
            'synthetic' => true,
            'real_payment' => false,
            'real_contract' => false,
            'authorizes_http' => false,
        ];
    }
}

$root = new PDO(
    'pgsql:host=127.0.0.1;port=5432;dbname=portal_purchase_rehearsal',
    'postgres',
    'synthetic-ci-only',
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
);
// The preceding CI step created the dedicated database, tenant RLS policy,
// sample legacy orders and two restricted login roles.
$root->exec(
    "ALTER TABLE purchase_rehearsal.orders ADD COLUMN customer_id text
     CHECK (customer_id IS NULL
       OR customer_id ~ '^test-customer-[a-z0-9-]{1,40}$')"
);
$root->exec('CREATE INDEX synthetic_owned_lookup_ci ON purchase_rehearsal.orders (tenant,customer_id,id) WHERE customer_id IS NOT NULL');
$connections = static function (string $role): PDO {
    $passwords = [
        'purchase_rehearsal_a' => 'ci-synthetic-a-only',
        'purchase_rehearsal_b' => 'ci-synthetic-b-only',
    ];
    if (!isset($passwords[$role])) throw new InvalidArgumentException('Unknown CI role.');
    return new PDO(
        'pgsql:host=127.0.0.1;port=5432;dbname=portal_purchase_rehearsal',
        $role, $passwords[$role],
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );
};

$checks = 0;
$check = static function (bool $value, string $label) use (&$checks): void {
    if (!$value) throw new RuntimeException('OWN-02 denied check: '.$label);
    ++$checks;
};
$denied = static function (callable $operation) use ($check): void {
    try {
        $operation();
    } catch (RuntimeException|InvalidArgumentException $e) {
        $check(true, 'Rejected expected synthetic access');
        return;
    }
    throw new RuntimeException('Expected owner-scoped denial, received success.');
};

$a1 = new OwnedPurchasePg18Laboratory($connections('purchase_rehearsal_a'), 'test-customer-a');
$a2 = new OwnedPurchasePg18Laboratory($connections('purchase_rehearsal_a'), 'test-customer-b');
$b1 = new OwnedPurchasePg18Laboratory($connections('purchase_rehearsal_b'), 'test-customer-a');
$denied(static fn() => new OwnedPurchasePg18Laboratory($connections('purchase_rehearsal_a'), 'administrador.teste'));

$key = 'TEST-client-owned-replay-0001';
$first = $a1->create($key, 'onepage');
$check($first['payment'] === 'pending' && $first['authorizes_http'] === false, 'Initial state');
$check($a1->create($key, 'onepage') === $first, 'Exact same customer replay');
$denied(static fn() => $a1->create($key, 'performance'));
$second = $a2->create($key, 'onepage');
$third = $b1->create($key, 'onepage');
$check(count(array_unique([$first['id'], $second['id'], $third['id']])) === 3, 'Same raw idempotency key scoped by owner and tenant');
$check(count($a1->rows()) === 1 && count($a2->rows()) === 1 && count($b1->rows()) === 1, 'Separate owner views');
$check($a1->rows()[0]['id'] === $first['id'], 'Client A only own order');
$check($a2->rows()[0]['id'] === $second['id'], 'Client B only own order');
$check($b1->rows()[0]['id'] === $third['id'], 'Organization B only own order');

foreach ([$a2, $b1] as $wrong) {
    $denied(static fn() => $wrong->read($first['id']));
    $denied(static fn() => $wrong->payment($first['id'], 0, 'approved'));
    $denied(static fn() => $wrong->briefing($first['id'], 0, [
        'business'=>'Fictícia','goal'=>'Amostra','audience'=>'Teste','materials'=>'Simulado'
    ]));
}

$legacy = $root->query(
    "SELECT id FROM purchase_rehearsal.orders WHERE tenant='test-org-a' AND customer_id IS NULL ORDER BY id LIMIT 1"
)->fetchColumn();
$check(is_string($legacy) && $legacy !== '', 'Legacy fixture exists');
$denied(static fn() => $a1->read($legacy));
$denied(static fn() => $a2->read($legacy));
$denied(static fn() => $a1->payment($legacy, 0, 'approved'));

$approved = $a1->payment($first['id'], 0, 'approved');
$denied(static fn() => $a1->payment($first['id'], 0, 'declined'));
$briefing = [
    'business'=>'Organização fictícia',
    'goal'=>'Página de exemplo',
    'audience'=>'Público sintético',
    'materials'=>'Material não real',
];
$complete = $a1->briefing($first['id'], $approved['revision'], $briefing);
$check($complete['briefing'] === $briefing, 'Owned briefing saved');
$denied(static fn() => $a1->payment($first['id'], $complete['revision'], 'declined'));
$denied(static fn() => $a1->briefing($first['id'], $complete['revision'], $briefing));
$again = new OwnedPurchasePg18Laboratory(
    $connections('purchase_rehearsal_a'), 'test-customer-a'
);
$check($again->read($first['id']) === $complete, 'Owner and briefing survive new PostgreSQL connection');
$check($a2->read($second['id'])['payment'] === 'pending', 'Neighbor customer unchanged');
$check($b1->read($third['id'])['payment'] === 'pending', 'Neighbor tenant unchanged');

$storeRow = $root->prepare(
    'SELECT customer_id, tenant, request_key FROM purchase_rehearsal.orders WHERE id=?'
);
$storeRow->execute([$first['id']]);
$dbRow = $storeRow->fetch(PDO::FETCH_ASSOC);
$check(($dbRow['customer_id'] ?? null) === 'test-customer-a'
    && ($dbRow['tenant'] ?? null) === 'test-org-a'
    && str_starts_with($dbRow['request_key'] ?? '', 'TEST-'), 'Owner committed atomically with order');

echo "PASS OWN-02: $checks owner+tenant/idempotency/payment/briefing/legacy checks in disposable PG18.\n";
echo "NO-GO: same-tenant direct SQL remains possible with a shared DB role; this fixture is NOT customer IAM or release-ready RLS.\n";
