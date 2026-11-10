<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Domain\ClientOverview\DemoServiceRelationPage;
use App\Domain\Organizations\OrganizationId;
use App\Labs\F24\F24LabSessionProof;
use App\Labs\F24\F24SyntheticAuthorizedReader;
use PDO;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * F29: adversarial catalog changes only on per-job disposable PostgreSQL.
 * Not production authentication, RLS certification or independent witness.
 */
final class F29RlsPolicyGrantDriftTest extends TestCase
{
    private const NOW = 1800000030;

    protected function setUp(): void
    {
        parent::setUp();
        if (getenv('F22_ENABLE_LAB') !== 'NON_OPERATIONAL_F22') {
            self::markTestSkipped('F29 requires a disposable PG18 runner.');
        }
    }

    private function db(string $role = 'a'): PDO
    {
        $credentials = [
            'a' => ['f21_org_a', getenv('F22_PG_PASSWORD_A')],
            'b' => ['f21_org_b', getenv('F22_PG_PASSWORD_B')],
            'admin' => ['postgres', getenv('F22_PG_PASSWORD_ADMIN')],
        ];
        $port = getenv('F22_PG_PORT');
        if (!isset($credentials[$role])
            || getenv('F22_PG_HOST') !== '127.0.0.1'
            || !is_string($port)
            || preg_match('/\A[0-9]{4,5}\z/D', $port) !== 1
            || (int) $port > 65535
            || preg_match('/\A[a-f0-9]{64}\z/D', (string) $credentials[$role][1]) !== 1) {
            throw new RuntimeException('F29 refuses non-ephemeral PostgreSQL.');
        }
        return new PDO(
            'pgsql:host=127.0.0.1;port='.$port.';dbname=postgres;connect_timeout=3;sslmode=disable',
            $credentials[$role][0], $credentials[$role][1],
            [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
            ],
        );
    }

    private function token(string $key, string $role): string
    {
        return F24LabSessionProof::signFixture([
            'aud' => 'portal-f24-synthetic-read',
            'exp' => 1800000900,
            'iat' => 1800000000,
            'iss' => 'imagine-portal-f24-lab-only',
            'mfa' => 1800000000,
            'mrev' => 1,
            'nonce' => str_repeat('e', 32),
            'org' => $role === 'a' ? 'org-a' : 'org-b',
            'sid' => $role === 'a' ? 'DEMO-SESSION-A' : 'DEMO-SESSION-B',
            'srev' => 1,
            'sub' => $role === 'a' ? 'DEMO-USER-A' : 'DEMO-USER-B',
            'ver' => 1,
        ], $key);
    }

    private function read(string $role = 'a'): DemoServiceRelationPage
    {
        $key = random_bytes(32);
        $reader = new F24SyntheticAuthorizedReader($this->db($role), $key);
        return $reader->read(
            new OrganizationId($role === 'a' ? 'org-a' : 'org-b'),
            $this->token($key, $role), self::NOW,
        );
    }

    private function blocked(DemoServiceRelationPage $page): void
    {
        self::assertSame('unsafe_rls_policy_contract', $page->reason);
        self::assertFalse($page->isPreparedPreview());
        self::assertSame([], $page->rows);
        self::assertSame(0, $page->totalServices);
        self::assertSame(0, $page->totalRequests);
        self::assertSame(0, $page->totalContracts);
        self::assertNull($page->nextCursor);
        self::assertFalse($page->mayExposeCustomerData());
        self::assertFalse($page->authorizesServiceAccess());
        self::assertFalse($page->authorizesContractOrPayment());
        self::assertFalse($page->grantsUniversityBenefit());
    }

    private function restored(): void
    {
        $a = $this->read();
        self::assertTrue($a->isPreparedPreview());
        self::assertSame(4, $a->totalServices);
        self::assertFalse($a->mayExposeCustomerData());
        $b = $this->read('b');
        self::assertTrue($b->isPreparedPreview());
        self::assertSame(1, $b->totalServices);
        self::assertFalse($b->authorizesServiceAccess());
    }

    public function test_exact_ten_policies_and_read_only_grants_pass_in_both_fake_tenants(): void
    {
        $this->restored();
    }

    public function test_service_rls_predicate_replaced_with_true_is_detected(): void
    {
        $admin = $this->db('admin');
        $admin->exec("ALTER POLICY f21_service_a ON f21.service USING (TRUE)");
        try {
            $this->blocked($this->read());
        } finally {
            $admin->exec("ALTER POLICY f21_service_a ON f21.service
                USING (organization_id='org-a' AND session_user='f21_org_a')");
        }
        $this->restored();
    }

    public function test_extra_permissive_rls_policy_is_detected_even_if_original_remains(): void
    {
        $admin = $this->db('admin');
        $admin->exec("CREATE POLICY f29_unreviewed_allow ON f21.service
            FOR SELECT TO f21_org_a USING (TRUE)");
        try {
            $this->blocked($this->read());
        } finally {
            $admin->exec("DROP POLICY f29_unreviewed_allow ON f21.service");
        }
        $this->restored();
    }

    public function test_session_policy_predicate_changed_to_true_blocks_valid_proof(): void
    {
        $admin = $this->db('admin');
        $admin->exec("ALTER POLICY f24_session_a ON f24.session_fixture USING (TRUE)");
        try {
            $this->blocked($this->read());
        } finally {
            $admin->exec("ALTER POLICY f24_session_a ON f24.session_fixture
                USING (organization_id='org-a' AND session_user='f21_org_a')");
        }
        $this->restored();
    }

    public function test_membership_rls_role_changed_to_public_denies(): void
    {
        $admin = $this->db('admin');
        $admin->exec("ALTER POLICY f24_member_a ON f24.membership_fixture TO PUBLIC");
        try {
            $this->blocked($this->read());
            $this->blocked($this->read('b'));
        } finally {
            $admin->exec("ALTER POLICY f24_member_a ON f24.membership_fixture TO f21_org_a");
        }
        $this->restored();
    }

    public function test_missing_request_policy_is_rejected(): void
    {
        $admin = $this->db('admin');
        $admin->exec("DROP POLICY f21_request_a ON f21.request");
        try {
            $this->blocked($this->read());
        } finally {
            $admin->exec("CREATE POLICY f21_request_a ON f21.request FOR SELECT TO f21_org_a
                USING (organization_id='org-a' AND session_user='f21_org_a')");
        }
        $this->restored();
    }

    public function test_unexpected_insert_grant_fails_even_without_an_insert_rls_policy(): void
    {
        $admin = $this->db('admin');
        $admin->exec("GRANT INSERT ON f21.service TO f21_org_a");
        try {
            $this->blocked($this->read());
        } finally {
            $admin->exec("REVOKE INSERT ON f21.service FROM f21_org_a");
        }
        $this->restored();
    }

    public function test_public_select_grant_fails_for_any_synthetic_client_session(): void
    {
        $admin = $this->db('admin');
        $admin->exec("GRANT SELECT ON f24.session_fixture TO PUBLIC");
        try {
            $this->blocked($this->read());
            $this->blocked($this->read('b'));
        } finally {
            $admin->exec("REVOKE SELECT ON f24.session_fixture FROM PUBLIC");
        }
        $this->restored();
    }

    public function test_role_inherit_changed_is_detected(): void
    {
        $admin = $this->db('admin');
        $admin->exec('ALTER ROLE f21_org_a INHERIT');
        try {
            $this->blocked($this->read());
        } finally {
            $admin->exec('ALTER ROLE f21_org_a NOINHERIT');
        }
        $this->restored();
    }

    public function test_cross_role_membership_is_rejected_without_expanding_access(): void
    {
        $admin = $this->db('admin');
        $admin->exec('GRANT f21_org_b TO f21_org_a WITH INHERIT FALSE, SET FALSE');
        try {
            $this->blocked($this->read());
        } finally {
            $admin->exec('REVOKE f21_org_b FROM f21_org_a');
        }
        $this->restored();
    }
}
