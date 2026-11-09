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
 * F30: controlled changes ONLY within the disposable PostgreSQL 18 runner.
 * Never use these ALTER/CREATE commands against a real Imagine database.
 */
final class F30ViewSchemaIntegrityTest extends TestCase
{
    private const NOW = 1800000030;

    protected function setUp(): void
    {
        parent::setUp();
        if (getenv('F22_ENABLE_LAB') !== 'NON_OPERATIONAL_F22') {
            self::markTestSkipped('F30 only runs with disposable PostgreSQL.');
        }
    }

    private function db(string $role): PDO
    {
        $roles = [
            'a' => ['f21_org_a', getenv('F22_PG_PASSWORD_A')],
            'b' => ['f21_org_b', getenv('F22_PG_PASSWORD_B')],
            'admin' => ['postgres', getenv('F22_PG_PASSWORD_ADMIN')],
        ];
        $port = getenv('F22_PG_PORT');
        if (!isset($roles[$role]) || getenv('F22_PG_HOST') !== '127.0.0.1'
            || !is_string($port)
            || preg_match('/\A[0-9]{4,5}\z/D', $port) !== 1
            || (int) $port > 65535
            || preg_match('/\A[a-f0-9]{64}\z/D', (string) $roles[$role][1]) !== 1) {
            throw new RuntimeException('F30 refuses non-disposable PostgreSQL.');
        }
        return new PDO(
            'pgsql:host=127.0.0.1;port='.$port.';dbname=postgres;connect_timeout=3;sslmode=disable',
            $roles[$role][0], $roles[$role][1],
            [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
            ],
        );
    }

    private function preview(string $role = 'a'): DemoServiceRelationPage
    {
        $key = random_bytes(32);
        $token = F24LabSessionProof::signFixture([
            'aud' => 'portal-f24-synthetic-read',
            'exp' => 1800000900,
            'iat' => 1800000000,
            'iss' => 'imagine-portal-f24-lab-only',
            'mfa' => 1800000000,
            'mrev' => 1,
            'nonce' => str_repeat('f', 32),
            'org' => $role === 'a' ? 'org-a' : 'org-b',
            'sid' => $role === 'a' ? 'DEMO-SESSION-A' : 'DEMO-SESSION-B',
            'srev' => 1,
            'sub' => $role === 'a' ? 'DEMO-USER-A' : 'DEMO-USER-B',
            'ver' => 1,
        ], $key);

        return (new F24SyntheticAuthorizedReader($this->db($role), $key))
            ->read(new OrganizationId($role === 'a' ? 'org-a' : 'org-b'), $token, self::NOW);
    }

    private function denied(DemoServiceRelationPage $page): void
    {
        self::assertSame('unsafe_service_view_schema', $page->reason);
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

    private function syntheticDiagnostics(): string
    {
        $admin = $this->db('admin');
        $schemas = $admin->query(
            "SELECT nspname, nspacl::text AS acl, pg_get_userbyid(nspowner) AS owner
               FROM pg_namespace WHERE nspname IN ('f21','f24') ORDER BY nspname"
        )->fetchAll(PDO::FETCH_ASSOC);
        $view = $admin->query(
            "SELECT relname, relacl::text AS acl, pg_get_userbyid(relowner) AS owner,
                    md5(pg_get_viewdef(oid, false)) AS view_md5
               FROM pg_class WHERE oid='f21.service_summary'::regclass"
        )->fetchAll(PDO::FETCH_ASSOC);
        return json_encode(['schemas' => $schemas, 'view' => $view]) ?: 'fixture diagnostic error';
    }

    private function healthy(): void
    {
        $a = $this->preview('a');
        self::assertTrue($a->isPreparedPreview(), 'After restore: '.$a->reason.'; '.$this->syntheticDiagnostics());
        self::assertSame(4, $a->totalServices);
        self::assertSame(3, $a->totalRequests);
        self::assertSame(3, $a->totalContracts);
        self::assertFalse($a->mayExposeCustomerData());

        $b = $this->preview('b');
        self::assertTrue($b->isPreparedPreview());
        self::assertSame('DEMO-SVC-PRIVATE-B', $b->rows[0]['reference']);
        self::assertFalse($b->authorizesServiceAccess());
    }

    public function test_baseline_pg18_view_definition_and_schema_privileges_are_expected(): void
    {
        $this->healthy();
    }

    public function test_replacing_service_view_with_zero_counts_is_rejected_even_with_invoker_enabled(): void
    {
        $admin = $this->db('admin');
        $original = $admin->query(
            "SELECT pg_catalog.pg_get_viewdef('f21.service_summary'::regclass, false)"
        )->fetchColumn();
        self::assertIsString($original);

        $admin->exec(
            'CREATE OR REPLACE VIEW f21.service_summary WITH (security_invoker=true) AS
             SELECT s.organization_id,s.service_reference,s.label,
                    0::bigint AS requests_count, 0::bigint AS contracts_count
               FROM f21.service s'
        );
        try {
            $this->denied($this->preview('a'));
        } finally {
            $admin->exec(
                'CREATE OR REPLACE VIEW f21.service_summary WITH (security_invoker=true) AS '
                .$original
            );
        }
        $this->healthy();
    }

    public function test_replacing_service_view_with_tenant_filter_is_rejected(): void
    {
        $admin = $this->db('admin');
        $original = $admin->query(
            "SELECT pg_catalog.pg_get_viewdef('f21.service_summary'::regclass, false)"
        )->fetchColumn();
        self::assertIsString($original);

        $admin->exec(
            "CREATE OR REPLACE VIEW f21.service_summary WITH (security_invoker=true) AS
             SELECT s.organization_id,s.service_reference,s.label,
                    0::bigint AS requests_count, 0::bigint AS contracts_count
               FROM f21.service s WHERE s.organization_id='org-a'"
        );
        try {
            $this->denied($this->preview('b'));
        } finally {
            $admin->exec(
                'CREATE OR REPLACE VIEW f21.service_summary WITH (security_invoker=true) AS '
                .$original
            );
        }
        $this->healthy();
    }

    public function test_schema_create_grant_to_read_role_is_rejected(): void
    {
        $admin = $this->db('admin');
        $admin->exec('GRANT CREATE ON SCHEMA f21 TO f21_org_a');
        try {
            $this->denied($this->preview());
        } finally {
            $admin->exec('REVOKE CREATE ON SCHEMA f21 FROM f21_org_a');
        }
        $this->healthy();
    }

    public function test_schema_public_usage_grant_is_rejected(): void
    {
        $admin = $this->db('admin');
        $admin->exec('GRANT USAGE ON SCHEMA f24 TO PUBLIC');
        try {
            $this->denied($this->preview());
        } finally {
            $admin->exec('REVOKE USAGE ON SCHEMA f24 FROM PUBLIC');
        }
        $this->healthy();
    }

    public function test_missing_schema_usage_for_second_role_is_rejected(): void
    {
        $admin = $this->db('admin');
        $admin->exec('REVOKE USAGE ON SCHEMA f24 FROM f21_org_b');
        try {
            $this->denied($this->preview('a'));
        } finally {
            $admin->exec('GRANT USAGE ON SCHEMA f24 TO f21_org_b');
        }
        $this->healthy();
    }

    public function test_schema_owner_change_to_reader_is_rejected(): void
    {
        $admin = $this->db('admin');
        $admin->exec('ALTER SCHEMA f21 OWNER TO f21_org_a');
        try {
            $this->denied($this->preview('a'));
        } finally {
            $admin->exec('ALTER SCHEMA f21 OWNER TO postgres');
        }
        $this->healthy();
    }

    public function test_view_owner_change_to_reader_is_rejected(): void
    {
        $admin = $this->db('admin');
        $admin->exec('ALTER VIEW f21.service_summary OWNER TO f21_org_b');
        try {
            $this->denied($this->preview('a'));
        } finally {
            $admin->exec('ALTER VIEW f21.service_summary OWNER TO postgres');
        }
        $this->healthy();
    }
}
