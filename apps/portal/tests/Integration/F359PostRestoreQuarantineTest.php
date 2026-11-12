<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Domain\Organizations\OrganizationId;
use App\Labs\F24\F24LabSessionProof;
use App\Labs\F24\F24SyntheticAuthorizedReader;
use PDO;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * F359 is invoked only after real pg_dump/pg_restore of the fictitious F24
 * database and a CI-only ALL-session SQL quarantine. There is no HTTP route.
 */
final class F359PostRestoreQuarantineTest extends TestCase
{
    private const NOW = 1800000030;

    protected function setUp(): void
    {
        parent::setUp();
        if (getenv('F22_ENABLE_LAB') !== 'NON_OPERATIONAL_F22'
            || getenv('F31_ENABLE_LAB') !== 'INDEPENDENT_DISPOSABLE_WITNESS'
            || getenv('F359_QUARANTINED') !== 'ALL_FIXTURE_SESSIONS_REVOKED') {
            self::markTestSkipped('F359 requires the explicitly quarantined disposable PG18 fixture.');
        }
    }

    private function connect(bool $witness = false, string $tenant = 'a'): PDO
    {
        if (!in_array($tenant, ['a', 'b'], true)) {
            throw new RuntimeException('Unknown synthetic tenant.');
        }
        $host = $witness ? getenv('F31_PG_HOST') : getenv('F22_PG_HOST');
        $port = $witness ? getenv('F31_PG_PORT') : getenv('F22_PG_PORT');
        $user = $witness ? 'f31_witness_reader' : 'f21_org_'.$tenant;
        $password = $witness ? getenv('F31_PG_PASSWORD_READER')
            : getenv($tenant === 'a' ? 'F22_PG_PASSWORD_A' : 'F22_PG_PASSWORD_B');
        if ($host !== '127.0.0.1'
            || !is_string($port) || preg_match('/\A[0-9]{4,5}\z/D', $port) !== 1
            || (int) $port > 65535
            || !is_string($password) || preg_match('/\A[a-f0-9]{64}\z/D', $password) !== 1
            || getenv('F31_PG_PORT') === getenv('F22_PG_PORT')) {
            throw new RuntimeException('Only separate disposable PostgreSQL roles are allowed.');
        }
        return new PDO(
            'pgsql:host=127.0.0.1;port='.$port.';dbname=postgres;connect_timeout=3;sslmode=disable',
            $user, $password,
            [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
            ],
        );
    }

    private function token(string $key, string $org, string $actor, string $session,
        int $sessionRev, int $membershipRev): string
    {
        return F24LabSessionProof::signFixture([
            'aud' => 'portal-f24-synthetic-read',
            'exp' => 1800000900,
            'iat' => 1800000000,
            'iss' => 'imagine-portal-f24-lab-only',
            'mfa' => 1800000000,
            'mrev' => $membershipRev,
            'nonce' => str_repeat('f', 32),
            'org' => $org,
            'sid' => $session,
            'srev' => $sessionRev,
            'sub' => $actor,
            'ver' => 1,
        ], $key);
    }

    public function test_all_restored_sessions_are_revoked_with_advanced_revisions(): void
    {
        // A restricted tenant role sees only its own fixture rows through RLS.
        $pdo = $this->connect();
        $sessions = $pdo->query('SELECT session_id, mock_state, session_revision
                                  FROM f24.session_fixture ORDER BY session_id')->fetchAll();
        self::assertCount(1, $sessions);
        self::assertSame('DEMO-SESSION-A', $sessions[0]['session_id']);
        self::assertSame('revoked', $sessions[0]['mock_state']);
        self::assertSame(2, (int) $sessions[0]['session_revision']);

        $memberships = $pdo->query('SELECT actor_id, mock_state, membership_revision, can_view_services
                                     FROM f24.membership_fixture ORDER BY actor_id')->fetchAll();
        self::assertCount(1, $memberships);
        self::assertSame('DEMO-USER-A', $memberships[0]['actor_id']);
        self::assertSame('revoked', $memberships[0]['mock_state']);
        self::assertSame(2, (int) $memberships[0]['membership_revision']);
        self::assertContains($memberships[0]['can_view_services'], ['f', false, '0', 0]);
    }

    public function test_old_proofs_and_even_new_matching_revision_proofs_cannot_read(): void
    {
        $key = random_bytes(32);
        $reader = new F24SyntheticAuthorizedReader($this->connect(), $key);
        foreach ([[1, 1], [2, 2]] as [$sessionRevision, $membershipRevision]) {
            $result = $reader->read(
                new OrganizationId('org-a'),
                $this->token($key, 'org-a', 'DEMO-USER-A', 'DEMO-SESSION-A',
                    $sessionRevision, $membershipRevision),
                self::NOW,
            );
            self::assertSame('revoked_lab_identity', $result->reason);
            self::assertFalse($result->isPreparedPreview());
            self::assertSame([], $result->rows);
            self::assertSame(0, $result->totalServices);
            self::assertSame(0, $result->totalContracts);
            self::assertSame(0, $result->totalRequests);
            self::assertFalse($result->mayExposeCustomerData());
            self::assertFalse($result->authorizesServiceAccess());
            self::assertFalse($result->authorizesContractOrPayment());
        }
    }

    public function test_tenant_b_cannot_reuse_old_or_new_session_proof_after_restore(): void
    {
        // Tenant-B is accessed with its own restricted SQL identity, never A.
        $pdo = $this->connect(false, 'b');
        $sessions = $pdo->query('SELECT organization_id,session_id,session_revision,mock_state
                                  FROM f24.session_fixture ORDER BY session_id')->fetchAll();
        self::assertCount(1, $sessions);
        self::assertSame('org-b', $sessions[0]['organization_id']);
        self::assertSame('DEMO-SESSION-B', $sessions[0]['session_id']);
        self::assertSame('revoked', $sessions[0]['mock_state']);
        self::assertSame(2, (int) $sessions[0]['session_revision']);

        $memberships = $pdo->query(
            'SELECT organization_id,actor_id,membership_revision,mock_state,can_view_services
               FROM f24.membership_fixture'
        )->fetchAll();
        self::assertCount(1, $memberships);
        self::assertSame('org-b', $memberships[0]['organization_id']);
        self::assertSame('DEMO-USER-B', $memberships[0]['actor_id']);
        self::assertSame(2, (int) $memberships[0]['membership_revision']);
        self::assertSame('revoked', $memberships[0]['mock_state']);
        self::assertContains($memberships[0]['can_view_services'], ['f', false, '0', 0]);

        $key = random_bytes(32);
        $reader = new F24SyntheticAuthorizedReader($pdo, $key);
        foreach ([[1, 1], [2, 2]] as [$sessionRevision, $membershipRevision]) {
            $blocked = $reader->read(
                new OrganizationId('org-b'),
                $this->token($key, 'org-b', 'DEMO-USER-B', 'DEMO-SESSION-B',
                    $sessionRevision, $membershipRevision),
                self::NOW,
            );
            self::assertSame('revoked_lab_identity', $blocked->reason);
            self::assertFalse($blocked->isPreparedPreview());
            self::assertSame([], $blocked->rows);
            self::assertFalse($blocked->mayExposeCustomerData());
            self::assertFalse($blocked->authorizesServiceAccess());
            self::assertFalse($blocked->authorizesContractOrPayment());
        }
    }

    public function test_witness_was_not_restored_or_modified_by_quarantine(): void
    {
        $rows = $this->connect(true)->query(
            'SELECT organization_id,session_floor,membership_floor,sequence_number
               FROM f31.revocation_floor ORDER BY organization_id'
        )->fetchAll();
        self::assertCount(2, $rows);
        self::assertSame('org-a', $rows[0]['organization_id']);
        self::assertSame(2, (int) $rows[0]['session_floor']);
        self::assertSame(1, (int) $rows[0]['membership_floor']);
        self::assertSame(2, (int) $rows[0]['sequence_number']);
        self::assertSame('org-b', $rows[1]['organization_id']);
        self::assertSame(1, (int) $rows[1]['session_floor']);
        self::assertSame(1, (int) $rows[1]['membership_floor']);
        self::assertSame(1, (int) $rows[1]['sequence_number']);
    }
}
