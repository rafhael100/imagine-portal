<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Domain\ClientOverview\DemoServiceRelationPage;
use App\Domain\Organizations\OrganizationId;
use App\Labs\F22\SyntheticPgServiceReadAdapter;
use PDO;
use PDOException;
use PHPUnit\Framework\TestCase;

/**
 * Disabled during standard Laravel CI.
 * Enabled ONLY with a new, isolated PostgreSQL fixture + ephemeral credentials.
 */
final class F22PgServiceReadIntegrationTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        if (getenv('F22_ENABLE_LAB') !== 'NON_OPERATIONAL_F22') {
            self::markTestSkipped('F22 requires the isolated PostgreSQL CI job.');
        }
    }

    private function connect(string $role = 'a', ?string $passwordOverride = null): PDO
    {
        if (!in_array($role, ['a', 'b', 'postgres'], true)) {
            throw new \InvalidArgumentException('Unknown fictional SQL identity.');
        }
        $host = getenv('F22_PG_HOST');
        $port = getenv('F22_PG_PORT');
        if ($host !== '127.0.0.1'
            || !is_string($port)
            || preg_match('/\A[0-9]{2,5}\z/D', $port) !== 1
            || (int) $port < 1024
            || (int) $port > 65535) {
            throw new \RuntimeException('F22 only permits a disposable loopback PostgreSQL port.');
        }

        $credentials = match ($role) {
            'a' => ['f21_org_a', getenv('F22_PG_PASSWORD_A')],
            'b' => ['f21_org_b', getenv('F22_PG_PASSWORD_B')],
            'postgres' => ['postgres', getenv('F22_PG_PASSWORD_ADMIN')],
        };
        if (!is_string($credentials[1]) || strlen($credentials[1]) !== 64
            || preg_match('/\A[a-f0-9]{64}\z/D', $credentials[1]) !== 1) {
            throw new \RuntimeException('F22 missing ephemeral credentials.');
        }
        return new PDO(
            'pgsql:host='.$host.';port='.$port.';dbname=postgres;connect_timeout=3;sslmode=disable',
            $credentials[0],
            $passwordOverride ?? $credentials[1],
            [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_EMULATE_PREPARES => false,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            ],
        );
    }

    private function blocked(DemoServiceRelationPage $result, string $reason): void
    {
        self::assertFalse($result->isPreparedPreview());
        self::assertSame($reason, $result->reason);
        self::assertSame([], $result->rows);
        self::assertSame(0, $result->totalServices);
        self::assertSame(0, $result->totalRequests);
        self::assertSame(0, $result->totalContracts);
        self::assertNull($result->nextCursor);
        self::assertFalse($result->mayExposeCustomerData());
        self::assertFalse($result->authorizesServiceAccess());
        self::assertFalse($result->authorizesContractOrPayment());
        self::assertFalse($result->grantsUniversityBenefit());
    }

    public function test_actual_pdo_pgsql_session_a_reads_only_a_synthetic_service_summary(): void
    {
        $pdo = $this->connect('a');
        self::assertSame('pgsql', $pdo->getAttribute(PDO::ATTR_DRIVER_NAME));
        $reader = new SyntheticPgServiceReadAdapter($pdo);
        $page = $reader->read(new OrganizationId('org-a'));
        self::assertTrue($page->isPreparedPreview());
        self::assertSame(4, $page->totalServices);
        self::assertSame(3, $page->totalRequests);
        self::assertSame(3, $page->totalContracts);
        self::assertSame([
            'DEMO-SVC-LANDING', 'DEMO-SVC-ONEPAGE',
            'DEMO-SVC-PERFORMANCE', 'DEMO-SVC-ZERO',
        ], array_column($page->rows, 'reference'));
        $byRef = array_column($page->rows, null, 'reference');
        self::assertSame(2, $byRef['DEMO-SVC-ONEPAGE']['request_count']);
        self::assertSame(1, $byRef['DEMO-SVC-ONEPAGE']['contract_count']);
        self::assertSame(0, $byRef['DEMO-SVC-LANDING']['request_count']);
        self::assertSame(2, $byRef['DEMO-SVC-LANDING']['contract_count']);
        self::assertSame(0, $byRef['DEMO-SVC-ZERO']['request_count']);
        self::assertSame(0, $byRef['DEMO-SVC-ZERO']['contract_count']);
        self::assertNull($page->nextCursor);
        self::assertFalse($page->mayExposeCustomerData());
        self::assertFalse($page->authorizesServiceAccess());
        self::assertFalse($page->authorizesContractOrPayment());
        self::assertFalse($page->grantsUniversityBenefit());
    }

    public function test_role_b_can_see_its_single_fixture_but_not_a(): void
    {
        $reader = new SyntheticPgServiceReadAdapter($this->connect('b'));
        $page = $reader->read(new OrganizationId('org-b'));
        self::assertTrue($page->isPreparedPreview());
        self::assertSame(1, $page->totalServices);
        self::assertSame(1, $page->totalRequests);
        self::assertSame(1, $page->totalContracts);
        self::assertSame('DEMO-SVC-PRIVATE-B', $page->rows[0]['reference']);
        self::assertSame(1, $page->rows[0]['request_count']);
        self::assertSame(1, $page->rows[0]['contract_count']);
        self::assertFalse($page->authorizesServiceAccess());
    }

    public function test_forged_tenant_a_against_session_b_or_b_against_a_fails_closed(): void
    {
        $a = new SyntheticPgServiceReadAdapter($this->connect('a'));
        $b = new SyntheticPgServiceReadAdapter($this->connect('b'));
        $this->blocked($a->read(new OrganizationId('org-b')), 'untrusted_db_session');
        $this->blocked($b->read(new OrganizationId('org-a')), 'untrusted_db_session');
        $this->blocked($a->read(new OrganizationId('org-x')), 'untrusted_db_session');
        $this->blocked($b->read(null), 'missing_organization');
    }

    public function test_superuser_fixture_or_changed_effective_role_is_never_a_client_authorizer(): void
    {
        $admin = new SyntheticPgServiceReadAdapter($this->connect('postgres'));
        $this->blocked($admin->read(new OrganizationId('org-a')), 'untrusted_db_session');
        $this->blocked($admin->read(new OrganizationId('org-b')), 'untrusted_db_session');
    }

    public function test_guc_tenant_spoofing_does_not_override_pgsql_session_identity(): void
    {
        $pdo = $this->connect('a');
        $pdo->exec("SET app.tenant_id = 'org-b'");
        $reader = new SyntheticPgServiceReadAdapter($pdo);
        $page = $reader->read(new OrganizationId('org-a'));
        self::assertTrue($page->isPreparedPreview());
        self::assertSame(4, $page->totalServices);
        $this->blocked($reader->read(new OrganizationId('org-b')), 'untrusted_db_session');
    }

    public function test_stable_cursor_pagination_over_the_same_bounded_pgsql_view(): void
    {
        $reader = new SyntheticPgServiceReadAdapter($this->connect('a'));
        $org = new OrganizationId('org-a');
        $all = [];
        $cursor = null;
        for ($i = 0; $i < 4; $i++) {
            $page = $reader->read($org, 1, $cursor);
            self::assertTrue($page->isPreparedPreview());
            self::assertSame(4, $page->totalServices);
            $all[] = $page->rows[0]['reference'];
            $cursor = $page->nextCursor;
        }
        self::assertSame([
            'DEMO-SVC-LANDING', 'DEMO-SVC-ONEPAGE',
            'DEMO-SVC-PERFORMANCE', 'DEMO-SVC-ZERO',
        ], $all);
        self::assertNull($cursor);
        $this->blocked($reader->read($org, after: 'DEMO-SVC-ZERO'), 'unknown_cursor');
        $this->blocked($reader->read($org, after: 'DEMO-SVC-UNKNOWN'), 'unknown_cursor');
        $this->blocked($reader->read($org, after: 'DEMO-SVC-ONEPAGE/../other'), 'invalid_pagination');
        $this->blocked($reader->read($org, limit: 21), 'invalid_pagination');
        $this->blocked($reader->read($org, limit: 0), 'invalid_pagination');
    }

    public function test_wrong_password_is_rejected_by_postgres_before_reading_any_rows(): void
    {
        $this->expectException(PDOException::class);
        $this->connect('b', str_repeat('f', 64));
    }

    public function test_read_only_database_role_cannot_write_or_disable_rls(): void
    {
        $pdo = $this->connect('a');
        foreach ([
            "INSERT INTO f21.service VALUES ('org-a','DEMO-SVC-ATTACK','Bad')",
            "DELETE FROM f21.request WHERE organization_id='org-a'",
            "UPDATE f21.contract SET mock_state='paid' WHERE organization_id='org-b'",
            "ALTER TABLE f21.service DISABLE ROW LEVEL SECURITY",
            "SET ROLE f21_org_b",
            "SET SESSION AUTHORIZATION f21_org_b",
        ] as $statement) {
            try {
                $pdo->exec($statement);
                self::fail('Unprivileged role executed a forbidden SQL command.');
            } catch (PDOException) {
                self::addToAssertionCount(1);
            }
        }
        $page = (new SyntheticPgServiceReadAdapter($pdo))->read(new OrganizationId('org-a'));
        self::assertTrue($page->isPreparedPreview());
        self::assertSame(4, $page->totalServices);
    }

    public function test_reader_refuses_ambient_transaction_and_never_commits_it(): void
    {
        $pdo = $this->connect('a');
        $pdo->beginTransaction();
        $reader = new SyntheticPgServiceReadAdapter($pdo);
        $this->blocked($reader->read(new OrganizationId('org-a')), 'source_unavailable');
        self::assertTrue($pdo->inTransaction());
        $pdo->rollBack();
        self::assertTrue($reader->read(new OrganizationId('org-a'))->isPreparedPreview());
    }

    public function test_missing_view_permission_or_db_availability_does_not_emit_partial_rows(): void
    {
        $pdo = $this->connect('a');
        $reader = new SyntheticPgServiceReadAdapter($pdo);
        self::assertTrue($reader->read(new OrganizationId('org-a'))->isPreparedPreview());

        // A permitted SELECT is disrupted by a forced connection failure;
        // do not retry with admin or switch tenant.
        $pdo = null;
        // Disruption of actual DB host is tested in the disposable CI runner
        // after the PHPUnit test suite, not from within a shared live fixture.
        self::assertFalse($reader->read(new OrganizationId('org-b'))->mayExposeCustomerData());
    }
}
