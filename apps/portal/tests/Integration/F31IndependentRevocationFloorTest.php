<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Domain\ClientOverview\DemoServiceRelationPage;
use App\Domain\Organizations\OrganizationId;
use App\Labs\F24\F24LabSessionProof;
use App\Labs\F24\F24SyntheticAuthorizedReader;
use App\Labs\F31\F31ExternalWitnessReader;
use App\Labs\F31\F31RollbackAwareLabReader;
use PDO;
use PDOException;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Runs only when the runner has TWO independent disposable PG18 containers.
 * Old-state restoration is simulated by SQL row rewrites, not pg_restore.
 * Results do NOT establish operational anti-rollback or recovery guarantees.
 */
final class F31IndependentRevocationFloorTest extends TestCase
{
    private const NOW = 1800000030;

    protected function setUp(): void
    {
        parent::setUp();
        if (getenv('F22_ENABLE_LAB') !== 'NON_OPERATIONAL_F22'
            || getenv('F31_ENABLE_LAB') !== 'INDEPENDENT_DISPOSABLE_WITNESS') {
            self::markTestSkipped('F31 needs two disposable PostgreSQL 18 containers.');
        }
    }

    private function connect(string $cluster, string $role): PDO
    {
        $primary = [
            'reader' => ['f21_org_a', getenv('F22_PG_PASSWORD_A')],
            'reader-b' => ['f21_org_b', getenv('F22_PG_PASSWORD_B')],
            'admin' => ['postgres', getenv('F22_PG_PASSWORD_ADMIN')],
        ];
        $witness = [
            'reader' => ['f31_witness_reader', getenv('F31_PG_PASSWORD_READER')],
            'admin' => ['postgres', getenv('F31_PG_PASSWORD_ADMIN')],
        ];
        $port = $cluster === 'primary' ? getenv('F22_PG_PORT') : getenv('F31_PG_PORT');
        $set = $cluster === 'primary' ? $primary : $witness;
        $creds = $set[$role] ?? null;

        if (!in_array($cluster, ['primary','witness'], true)
            || $creds === null || getenv('F22_PG_HOST') !== '127.0.0.1'
            || getenv('F31_PG_HOST') !== '127.0.0.1'
            || getenv('F22_PG_PORT') === getenv('F31_PG_PORT')
            || !is_string($port)
            || preg_match('/\A[0-9]{4,5}\z/D', $port) !== 1
            || (int) $port > 65535
            || preg_match('/\A[a-f0-9]{64}\z/D', (string) $creds[1]) !== 1) {
            throw new RuntimeException('F31 requires separate synthetic SQL endpoints.');
        }

        return new PDO(
            'pgsql:host=127.0.0.1;port='.$port.';dbname=postgres;connect_timeout=3;sslmode=disable',
            $creds[0], $creds[1],
            [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
            ],
        );
    }

    private function token(string $key, string $role = 'a', array $overrides = []): string
    {
        return F24LabSessionProof::signFixture(array_replace([
            'aud' => 'portal-f24-synthetic-read',
            'exp' => 1800000900,
            'iat' => 1800000000,
            'iss' => 'imagine-portal-f24-lab-only',
            'mfa' => 1800000000,
            'mrev' => 1,
            'nonce' => str_repeat('b', 32),
            'org' => $role === 'a' ? 'org-a' : 'org-b',
            'sid' => $role === 'a' ? 'DEMO-SESSION-A' : 'DEMO-SESSION-B',
            'srev' => 1,
            'sub' => $role === 'a' ? 'DEMO-USER-A' : 'DEMO-USER-B',
            'ver' => 1,
        ], $overrides), $key);
    }

    private function org(string $role = 'a'): OrganizationId
    {
        return new OrganizationId($role === 'a' ? 'org-a' : 'org-b');
    }

    private function reader(string $key, string $role = 'a', string $witnessRole = 'reader'): F31RollbackAwareLabReader
    {
        return new F31RollbackAwareLabReader(
            new F24SyntheticAuthorizedReader(
                $this->connect('primary', $role === 'a' ? 'reader' : 'reader-b'),
                $key,
            ),
            new F31ExternalWitnessReader($this->connect('witness', $witnessRole)),
            $key,
        );
    }

    private function denied(DemoServiceRelationPage $page, string $reason): void
    {
        self::assertSame($reason, $page->reason);
        self::assertFalse($page->isPreparedPreview());
        self::assertSame([], $page->rows);
        self::assertSame(0, $page->totalServices);
        self::assertSame(0, $page->totalRequests);
        self::assertSame(0, $page->totalContracts);
        self::assertNull($page->nextCursor);
        self::assertFalse($page->authorizesServiceAccess());
        self::assertFalse($page->authorizesContractOrPayment());
        self::assertFalse($page->mayExposeCustomerData());
        self::assertFalse($page->grantsUniversityBenefit());
    }

    private function baseline(): void
    {
        $key = random_bytes(32);
        $first = $this->reader($key)->read($this->org(), $this->token($key), self::NOW);
        self::assertTrue($first->isPreparedPreview());
        self::assertSame(4, $first->totalServices);
        self::assertFalse($first->mayExposeCustomerData());
        $other = $this->reader($key, 'b')->read($this->org('b'), $this->token($key, 'b'), self::NOW);
        self::assertTrue($other->isPreparedPreview());
        self::assertSame(1, $other->totalServices);
        self::assertFalse($other->authorizesServiceAccess());
    }

    /**
     * A privileged teardown only for the disposable runner. This explicitly
     * demonstrates why an admin controlling the witness is NOT an independent
     * production anti-rollback authority.
     */
    private function resetWitnessFixture(): void
    {
        $db = $this->connect('witness', 'admin');
        $db->exec('ALTER TABLE f31.revocation_floor DISABLE TRIGGER f31_floor_monotonic');
        try {
            $db->exec("UPDATE f31.revocation_floor
                         SET session_floor=1, membership_floor=1,
                             sequence_number=1, changed_at=1800000000
                       WHERE organization_id='org-a'");
        } finally {
            $db->exec('ALTER TABLE f31.revocation_floor ENABLE TRIGGER f31_floor_monotonic');
        }
    }

    public function test_two_separate_sql_containers_allow_only_non_authoritative_preview(): void
    {
        $this->baseline();
    }

    public function test_older_primary_session_snapshot_cannot_undo_external_revocation_floor(): void
    {
        $key = random_bytes(32);
        $token1 = $this->token($key);
        $reader = $this->reader($key);
        $admin = $this->connect('primary', 'admin');
        $witness = $this->connect('witness', 'admin');

        self::assertTrue($reader->read($this->org(), $token1, self::NOW)->isPreparedPreview());
        try {
            // First commit a revocation in the authority database, and
            // advance a completely separate witness SQL server afterward.
            $admin->exec("UPDATE f24.session_fixture
                        SET mock_state='revoked', session_revision=2
                        WHERE organization_id='org-a' AND session_id='DEMO-SESSION-A'");
            $witness->exec("UPDATE f31.revocation_floor
                        SET session_floor=2, sequence_number=2, changed_at=1800000031
                        WHERE organization_id='org-a'");

            $this->denied(
                $reader->read($this->org(), $token1, self::NOW),
                'stale_external_witness',
            );

            // Simulate restoring the old primary DB row without restoring
            // the independent witness DB. This is NOT an actual pg_restore.
            $admin->exec("UPDATE f24.session_fixture
                        SET mock_state='active', session_revision=1
                        WHERE organization_id='org-a' AND session_id='DEMO-SESSION-A'");

            // Demonstrate vulnerability of the original purely internal
            // laboratory proof (do NOT treat this as an operational reader).
            $primaryOnly = new F24SyntheticAuthorizedReader(
                $this->connect('primary', 'reader'), $key,
            );
            self::assertTrue(
                $primaryOnly->read($this->org(), $token1, self::NOW)->isPreparedPreview(),
                'The intentionally unguarded F24 lab proof is replayable after restore.',
            );

            // But the F31 synthetic facade still blocks the SAME old proof.
            $this->denied(
                $reader->read($this->org(), $token1, self::NOW),
                'stale_external_witness',
            );

            // Repair primary state with new revision, issue new TEST proof.
            $admin->exec("UPDATE f24.session_fixture
                        SET mock_state='active', session_revision=2
                        WHERE organization_id='org-a' AND session_id='DEMO-SESSION-A'");
            $token2 = $this->token($key, 'a', ['srev' => 2]);
            $recovered = $reader->read($this->org(), $token2, self::NOW);
            self::assertTrue($recovered->isPreparedPreview());
            self::assertFalse($recovered->authorizesServiceAccess());
        } finally {
            $admin->exec("UPDATE f24.session_fixture
                        SET mock_state='active', session_revision=1
                        WHERE organization_id='org-a' AND session_id='DEMO-SESSION-A'");
            $this->resetWitnessFixture();
        }
        $this->baseline();
    }

    public function test_restored_old_membership_revision_is_denied_by_second_database(): void
    {
        $key = random_bytes(32);
        $token1 = $this->token($key);
        $reader = $this->reader($key);
        $admin = $this->connect('primary', 'admin');
        $witness = $this->connect('witness', 'admin');

        try {
            $admin->exec("UPDATE f24.membership_fixture
                        SET mock_state='revoked', membership_revision=2
                        WHERE organization_id='org-a' AND actor_id='DEMO-USER-A'");
            $witness->exec("UPDATE f31.revocation_floor
                        SET membership_floor=2, sequence_number=2, changed_at=1800000032
                        WHERE organization_id='org-a'");
            $admin->exec("UPDATE f24.membership_fixture
                        SET mock_state='active', membership_revision=1
                        WHERE organization_id='org-a' AND actor_id='DEMO-USER-A'");
            $this->denied(
                $reader->read($this->org(), $token1, self::NOW),
                'stale_external_witness',
            );

            $admin->exec("UPDATE f24.membership_fixture
                        SET mock_state='active', membership_revision=2
                        WHERE organization_id='org-a' AND actor_id='DEMO-USER-A'");
            $token2 = $this->token($key, 'a', ['mrev' => 2]);
            self::assertTrue($reader->read($this->org(), $token2, self::NOW)->isPreparedPreview());
        } finally {
            $admin->exec("UPDATE f24.membership_fixture
                        SET mock_state='active', membership_revision=1
                        WHERE organization_id='org-a' AND actor_id='DEMO-USER-A'");
            $this->resetWitnessFixture();
        }
        $this->baseline();
    }

    public function test_witness_unavailable_or_wrong_sql_role_denies_without_retrying_as_admin(): void
    {
        $key = random_bytes(32);
        $admin = $this->connect('witness', 'admin');
        $admin->exec('REVOKE SELECT ON f31.revocation_floor FROM f31_witness_reader');
        try {
            $this->denied(
                $this->reader($key)->read($this->org(), $this->token($key), self::NOW),
                'unavailable_external_witness',
            );
        } finally {
            $admin->exec('GRANT SELECT ON f31.revocation_floor TO f31_witness_reader');
        }
        $this->denied(
            $this->reader($key, witnessRole: 'admin')->read($this->org(), $this->token($key), self::NOW),
            'unavailable_external_witness',
        );
        $this->baseline();
    }

    public function test_disabled_witness_monotonic_trigger_is_fail_closed(): void
    {
        $key = random_bytes(32);
        $admin = $this->connect('witness', 'admin');
        $admin->exec('ALTER TABLE f31.revocation_floor DISABLE TRIGGER f31_floor_monotonic');
        try {
            $this->denied(
                $this->reader($key)->read($this->org(), $this->token($key), self::NOW),
                'unavailable_external_witness',
            );
        } finally {
            $admin->exec('ALTER TABLE f31.revocation_floor ENABLE TRIGGER f31_floor_monotonic');
        }
        $this->baseline();
    }

    public function test_ordinary_sql_writer_cannot_decrease_monotonic_floor(): void
    {
        $admin = $this->connect('witness', 'admin');
        try {
            $admin->exec("UPDATE f31.revocation_floor
                        SET session_floor=2, sequence_number=2, changed_at=1800000031
                        WHERE organization_id='org-a'");
            try {
                $admin->exec("UPDATE f31.revocation_floor
                            SET session_floor=1, sequence_number=3, changed_at=1800000032
                            WHERE organization_id='org-a'");
                self::fail('Synthetic witness allowed an ordinary decreasing revision.');
            } catch (PDOException) {
                self::addToAssertionCount(1);
            }
            $this->denied(
                $this->reader($key = random_bytes(32))->read(
                    $this->org(), $this->token($key), self::NOW,
                ),
                'stale_external_witness',
            );
        } finally {
            $this->resetWitnessFixture();
        }
        $this->baseline();
    }

    public function test_wrong_organization_or_tampered_signature_cannot_bypass_witness(): void
    {
        $key = random_bytes(32);
        $reader = $this->reader($key);
        $this->denied(
            $reader->read($this->org('b'), $this->token($key), self::NOW),
            'invalid_lab_identity_evidence',
        );
        $this->denied(
            $reader->read($this->org(), 'invalid.token', self::NOW),
            'invalid_lab_identity_evidence',
        );
        $this->baseline();
    }
}
