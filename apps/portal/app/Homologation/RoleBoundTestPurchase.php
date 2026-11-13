<?php

declare(strict_types=1);

namespace App\Homologation;

use PDO;
use RuntimeException;

/**
 * CI-only rehearsal adapter: the *authenticated PostgreSQL login* selects
 * its one synthetic organization. No client- or caller-supplied tenant.
 *
 * No HTTP routes, customer identity, business permissions or live purchases.
 */
final class RoleBoundTestPurchase
{
    private PersistedTestPurchase $store;

    private string $tenant;

    public function __construct(PDO $db)
    {
        $identity = $db->query('SELECT current_user AS db_role, session_user AS db_login')
            ->fetch(PDO::FETCH_ASSOC);

        if (!is_array($identity)
            || !is_string($identity['db_role'] ?? null)
            || ($identity['db_role'] !== ($identity['db_login'] ?? null))) {
            throw new RuntimeException('Database login and active role must match.');
        }

        $this->tenant = match ($identity['db_role']) {
            'purchase_rehearsal_a' => 'test-org-a',
            'purchase_rehearsal_b' => 'test-org-b',
            default => throw new RuntimeException('Only restricted synthetic logins allowed.'),
        };

        $roleMetadata = $db->prepare(
            'SELECT rolsuper, rolbypassrls, rolcreaterole, rolcreatedb, rolcanlogin
             FROM pg_roles WHERE rolname = ?'
        );
        $roleMetadata->execute([$identity['db_role']]);
        $privileges = $roleMetadata->fetch(PDO::FETCH_ASSOC);
        if (!is_array($privileges)
            || !self::pgFlag($privileges['rolcanlogin'] ?? null)
            || self::pgFlag($privileges['rolsuper'] ?? null)
            || self::pgFlag($privileges['rolbypassrls'] ?? null)
            || self::pgFlag($privileges['rolcreaterole'] ?? null)
            || self::pgFlag($privileges['rolcreatedb'] ?? null)) {
            throw new RuntimeException('Restricted PostgreSQL login role required.');
        }

        $isolation = $db->query(
            "SELECT relrowsecurity, relforcerowsecurity
             FROM pg_class WHERE oid = 'purchase_rehearsal.orders'::regclass"
        )->fetch(PDO::FETCH_ASSOC);
        if (!is_array($isolation)
            || !self::pgFlag($isolation['relrowsecurity'] ?? null)
            || !self::pgFlag($isolation['relforcerowsecurity'] ?? null)) {
            throw new RuntimeException('Database requires forced row-level security.');
        }

        $this->store = new PersistedTestPurchase($db);
    }

    private static function pgFlag(mixed $value): bool
    {
        return $value === true || $value === 't' || $value === 1 || $value === '1';
    }

    /** @return array<string, mixed> */
    public function create(string $requestKey, string $product): array
    {
        return $this->store->create($this->tenant, $requestKey, $product);
    }

    /** @return array<string, mixed> */
    public function read(string $orderId): array
    {
        return $this->store->read($this->tenant, $orderId);
    }

    /** @return array<string, mixed> */
    public function payment(string $orderId, int $revision, string $fakeOutcome): array
    {
        return $this->store->payment($this->tenant, $orderId, $revision, $fakeOutcome);
    }

    /** @param array<string, mixed> $briefing
     *  @return array<string, mixed>
     */
    public function briefing(string $orderId, int $revision, array $briefing): array
    {
        return $this->store->briefing($this->tenant, $orderId, $revision, $briefing);
    }

    public static function authorizesCustomerHttp(): bool
    {
        return false;
    }
}
