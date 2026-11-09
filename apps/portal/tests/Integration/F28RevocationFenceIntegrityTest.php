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
 * F28 adversarial lab only. Attacker changes are performed as the disposable
 * PostgreSQL fixture owner, NEVER in a real Portal database.
 */
final class F28RevocationFenceIntegrityTest extends TestCase
{
    private const NOW = 1800000030;

    protected function setUp(): void
    {
        parent::setUp();
        if (getenv('F22_ENABLE_LAB') !== 'NON_OPERATIONAL_F22') {
            self::markTestSkipped('F28 requires disposable PostgreSQL CI.');
        }
    }

    private function connect(string $role): PDO
    {
        $users = [
            'a' => ['f21_org_a', getenv('F22_PG_PASSWORD_A')],
            'b' => ['f21_org_b', getenv('F22_PG_PASSWORD_B')],
            'admin' => ['postgres', getenv('F22_PG_PASSWORD_ADMIN')],
        ];
        $port = getenv('F22_PG_PORT');
        if (!isset($users[$role])
            || getenv('F22_PG_HOST') !== '127.0.0.1'
            || !is_string($port)
            || preg_match('/\A[0-9]{4,5}\z/D', $port) !== 1
            || (int) $port > 65535
            || preg_match('/\A[a-f0-9]{64}\z/D', (string) $users[$role][1]) !== 1) {
            throw new RuntimeException('F28 requires a local ephemeral fixture database.');
        }
        return new PDO(
            'pgsql:host=127.0.0.1;port='.$port.';dbname=postgres;connect_timeout=3;sslmode=disable',
            $users[$role][0], $users[$role][1],
            [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
            ],
        );
    }

    private function signedToken(string $key, string $role = 'a'): string
    {
        return F24LabSessionProof::signFixture([
            'aud' => 'portal-f24-synthetic-read',
            'exp' => 1800000900,
            'iat' => 1800000000,
            'iss' => 'imagine-portal-f24-lab-only',
            'mfa' => 1800000000,
            'mrev' => 1,
            'nonce' => str_repeat('d', 32),
            'org' => $role === 'a' ? 'org-a' : 'org-b',
            'sid' => $role === 'a' ? 'DEMO-SESSION-A' : 'DEMO-SESSION-B',
            'srev' => 1,
            'sub' => $role === 'a' ? 'DEMO-USER-A' : 'DEMO-USER-B',
            'ver' => 1,
        ], $key);
    }

    private function runPreview(
        string $role = 'a',
        ?string $key = null,
        ?string $token = null,
    ): DemoServiceRelationPage {
        $key ??= random_bytes(32);
        return (new F24SyntheticAuthorizedReader($this->connect($role), $key))
            ->read(
                new OrganizationId($role === 'a' ? 'org-a' : 'org-b'),
                $token ?? $this->signedToken($key, $role),
                self::NOW,
            );
    }

    private function assertBlocked(DemoServiceRelationPage $page): void
    {
        self::assertSame('unsafe_revocation_fence', $page->reason);
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

    private function assertRecovered(): void
    {
        $page = $this->runPreview();
        self::assertTrue($page->isPreparedPreview());
        self::assertSame(4, $page->totalServices);
        self::assertFalse($page->mayExposeCustomerData());
        self::assertFalse($page->authorizesServiceAccess());
    }

    public function test_expected_triggers_allow_only_fictional_previews_on_both_tenants(): void
    {
        $this->assertRecovered();
        $b = $this->runPreview('b');
        self::assertTrue($b->isPreparedPreview());
        self::assertSame('DEMO-SVC-PRIVATE-B', $b->rows[0]['reference']);
        self::assertFalse($b->mayExposeCustomerData());
        self::assertFalse($b->authorizesServiceAccess());
    }

    public function test_disabling_session_trigger_blocks_read_even_though_signed_token_is_valid(): void
    {
        $admin = $this->connect('admin');
        $admin->exec('ALTER TABLE f24.session_fixture DISABLE TRIGGER f25_session_write');
        try {
            $this->assertBlocked($this->runPreview());
        } finally {
            $admin->exec('ALTER TABLE f24.session_fixture ENABLE TRIGGER f25_session_write');
        }
        $this->assertRecovered();
    }

    public function test_disabling_membership_trigger_blocks_read_for_otherwise_active_user(): void
    {
        $admin = $this->connect('admin');
        $admin->exec('ALTER TABLE f24.membership_fixture DISABLE TRIGGER f25_membership_write');
        try {
            $this->assertBlocked($this->runPreview());
        } finally {
            $admin->exec('ALTER TABLE f24.membership_fixture ENABLE TRIGGER f25_membership_write');
        }
        $this->assertRecovered();
    }

    public function test_trigger_execution_mode_change_to_replica_is_not_silently_accepted(): void
    {
        $admin = $this->connect('admin');
        $admin->exec('ALTER TABLE f24.session_fixture ENABLE REPLICA TRIGGER f25_session_write');
        try {
            $this->assertBlocked($this->runPreview());
        } finally {
            $admin->exec('ALTER TABLE f24.session_fixture ENABLE TRIGGER f25_session_write');
        }
        $this->assertRecovered();
    }

    public function test_replacing_function_with_noop_is_detected_even_when_trigger_name_is_unchanged(): void
    {
        $admin = $this->connect('admin');
        $original = $admin->query(
            "SELECT pg_catalog.pg_get_functiondef('f24.synthetic_revocation_writer_gate()'::regprocedure)"
        )->fetchColumn();
        self::assertIsString($original);
        $admin->exec(
            'CREATE OR REPLACE FUNCTION f24.synthetic_revocation_writer_gate()
             RETURNS trigger LANGUAGE plpgsql AS $replaced$
             BEGIN RETURN NEW; END
             $replaced$'
        );
        try {
            $this->assertBlocked($this->runPreview());
        } finally {
            $admin->exec($original);
        }
        $this->assertRecovered();
    }

    public function test_security_definer_switch_rejects_fence_function(): void
    {
        $admin = $this->connect('admin');
        $admin->exec('ALTER FUNCTION f24.synthetic_revocation_writer_gate() SECURITY DEFINER');
        try {
            $this->assertBlocked($this->runPreview());
        } finally {
            $admin->exec('ALTER FUNCTION f24.synthetic_revocation_writer_gate() SECURITY INVOKER');
        }
        $this->assertRecovered();
    }

    public function test_dropped_membership_trigger_rejects_read_and_can_be_repaired_in_lab(): void
    {
        $admin = $this->connect('admin');
        $admin->exec('DROP TRIGGER f25_membership_write ON f24.membership_fixture');
        try {
            $this->assertBlocked($this->runPreview());
        } finally {
            $admin->exec(
                'CREATE TRIGGER f25_membership_write
                 BEFORE INSERT OR UPDATE OR DELETE ON f24.membership_fixture
                 FOR EACH ROW EXECUTE FUNCTION f24.synthetic_revocation_writer_gate()'
            );
        }
        $this->assertRecovered();
    }

    public function test_unexpected_extra_trigger_fails_closed_even_if_named_triggers_remain(): void
    {
        $admin = $this->connect('admin');
        $admin->exec(
            'CREATE TRIGGER f28_unreviewed_writer
             BEFORE UPDATE ON f24.session_fixture
             FOR EACH ROW EXECUTE FUNCTION f24.synthetic_revocation_writer_gate()'
        );
        try {
            $this->assertBlocked($this->runPreview());
        } finally {
            $admin->exec('DROP TRIGGER f28_unreviewed_writer ON f24.session_fixture');
        }
        $this->assertRecovered();
    }
}
