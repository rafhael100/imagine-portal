<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Domain\ClientOverview\DemoServiceRelationPage;
use App\Domain\Organizations\OrganizationId;
use App\Labs\F24\F24LabSessionProof;
use App\Labs\F24\F24SyntheticAuthorizedReader;
use PDO;
use PDOException;
use PHPUnit\Framework\TestCase;

/**
 * ONLY executable in disposable F22/F24 PG18 CI, never in the normal
 * Laravel test suite. Tests use fake users, timestamps, tenants, and keys.
 */
final class F24SessionRevocationIntegrationTest extends TestCase
{
    private const NOW = 1800000030;

    protected function setUp(): void
    {
        parent::setUp();
        if (getenv('F22_ENABLE_LAB') !== 'NON_OPERATIONAL_F22') {
            self::markTestSkipped('F24 requires isolated disposable PostgreSQL.');
        }
    }

    private function db(string $role): PDO
    {
        $names = [
            'a' => ['f21_org_a', getenv('F22_PG_PASSWORD_A')],
            'b' => ['f21_org_b', getenv('F22_PG_PASSWORD_B')],
            'admin' => ['postgres', getenv('F22_PG_PASSWORD_ADMIN')],
        ];
        if (!isset($names[$role])) {
            throw new \InvalidArgumentException('Unknown synthetic database identity.');
        }
        $port = getenv('F22_PG_PORT');
        if (getenv('F22_PG_HOST') !== '127.0.0.1'
            || !is_string($port)
            || preg_match('/\A[0-9]{4,5}\z/D', $port) !== 1
            || (int) $port > 65535
            || !is_string($names[$role][1])
            || preg_match('/\A[a-f0-9]{64}\z/D', $names[$role][1]) !== 1) {
            throw new \RuntimeException('Non-disposable database endpoint or credentials.');
        }
        return new PDO(
            'pgsql:host=127.0.0.1;port='.$port.';dbname=postgres;connect_timeout=3;sslmode=disable',
            $names[$role][0],
            $names[$role][1],
            [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
            ],
        );
    }

    /** @return array<string,mixed> */
    private function claims(string $role = 'a'): array
    {
        return [
            'aud' => 'portal-f24-synthetic-read',
            'exp' => 1800000900,
            'iat' => 1800000000,
            'iss' => 'imagine-portal-f24-lab-only',
            'mfa' => 1800000000,
            'mrev' => 1,
            'nonce' => str_repeat('a', 32),
            'org' => $role === 'a' ? 'org-a' : 'org-b',
            'sid' => $role === 'a' ? 'DEMO-SESSION-A' : 'DEMO-SESSION-B',
            'srev' => 1,
            'sub' => $role === 'a' ? 'DEMO-USER-A' : 'DEMO-USER-B',
            'ver' => 1,
        ];
    }

    private function token(string $key, string $role = 'a', array $overrides = []): string
    {
        return F24LabSessionProof::signFixture(
            array_replace($this->claims($role), $overrides), $key,
        );
    }

    private function reader(string $role, string $key): F24SyntheticAuthorizedReader
    {
        return new F24SyntheticAuthorizedReader($this->db($role), $key);
    }

    private function org(string $role): OrganizationId
    {
        return new OrganizationId($role === 'a' ? 'org-a' : 'org-b');
    }

    private function deny(DemoServiceRelationPage $result, string $expected): void
    {
        self::assertFalse($result->isPreparedPreview());
        self::assertSame($expected, $result->reason);
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

    public function test_valid_signed_fixture_still_exposes_only_synthetic_non_authoritative_rows(): void
    {
        $key = random_bytes(32);
        $a = $this->reader('a', $key)->read(
            $this->org('a'), $this->token($key), self::NOW,
        );
        self::assertTrue($a->isPreparedPreview());
        self::assertSame(4, $a->totalServices);
        self::assertSame(3, $a->totalRequests);
        self::assertSame(3, $a->totalContracts);
        self::assertFalse($a->mayExposeCustomerData());
        self::assertFalse($a->authorizesServiceAccess());
        self::assertFalse($a->authorizesContractOrPayment());
        self::assertFalse($a->grantsUniversityBenefit());

        $b = $this->reader('b', $key)->read(
            $this->org('b'), $this->token($key, 'b'), self::NOW,
        );
        self::assertTrue($b->isPreparedPreview());
        self::assertSame(1, $b->totalServices);
        self::assertSame('DEMO-SVC-PRIVATE-B', $b->rows[0]['reference']);
        self::assertFalse($b->mayExposeCustomerData());
    }

    public function test_missing_invalid_signature_and_wrong_ephemeral_key_are_denied(): void
    {
        $key = random_bytes(32);
        $reader = $this->reader('a', $key);
        foreach ([
            null,
            '',
            'malformed.payload',
            $this->token($key).'extra',
            $this->token(random_bytes(32)),
            substr($this->token($key), 0, -1).'X',
        ] as $invalid) {
            $this->deny($reader->read($this->org('a'), $invalid, self::NOW),
                'invalid_lab_identity_evidence');
        }
    }

    public function test_claim_organization_cannot_impersonate_database_role_or_selected_tenant(): void
    {
        $key = random_bytes(32);
        $a = $this->reader('a', $key);
        $b = $this->reader('b', $key);
        $this->deny($a->read($this->org('b'), $this->token($key, 'a'), self::NOW),
            'invalid_lab_identity_evidence');
        $this->deny($a->read($this->org('b'), $this->token($key, 'b'), self::NOW),
            'untrusted_db_session');
        $this->deny($b->read($this->org('a'), $this->token($key, 'a'), self::NOW),
            'untrusted_db_session');
        $this->deny($a->read(null, $this->token($key), self::NOW),
            'missing_organization');
    }

    public function test_expiration_future_issuance_stale_mfa_and_claim_schema_are_denied(): void
    {
        $key = random_bytes(32);
        $reader = $this->reader('a', $key);
        $invalidClaims = [
            ['exp' => self::NOW],
            ['iat' => self::NOW + 1],
            ['iat' => self::NOW - 1000, 'exp' => self::NOW + 1],
            ['mfa' => self::NOW - 601],
            ['mfa' => self::NOW + 1],
            ['aud' => 'crm-identity'],
            ['iss' => 'another-issuer'],
            ['ver' => 2],
            ['nonce' => 'bad'],
            ['srev' => 0],
            ['mrev' => -1],
            ['extra' => 'admin'],
        ];
        foreach ($invalidClaims as $override) {
            $token = $this->token($key, 'a', $override);
            $this->deny($reader->read($this->org('a'), $token, self::NOW),
                'invalid_lab_identity_evidence');
        }
        $this->deny($reader->read($this->org('a'), $this->token($key), 1800000900),
            'invalid_lab_identity_evidence');
    }

    public function test_revoking_existing_session_blocks_same_previously_valid_signature(): void
    {
        $key = random_bytes(32);
        $reader = $this->reader('a', $key);
        $token = $this->token($key);
        $admin = $this->db('admin');
        self::assertTrue($reader->read($this->org('a'), $token, self::NOW)->isPreparedPreview());
        $admin->exec("UPDATE f24.session_fixture SET mock_state='revoked'
            WHERE organization_id='org-a' AND session_id='DEMO-SESSION-A'");
        try {
            $this->deny($reader->read($this->org('a'), $token, self::NOW), 'revoked_lab_identity');
        } finally {
            $admin->exec("UPDATE f24.session_fixture SET mock_state='active'
                WHERE organization_id='org-a' AND session_id='DEMO-SESSION-A'");
        }
        self::assertTrue($reader->read($this->org('a'), $token, self::NOW)->isPreparedPreview());
    }

    public function test_suspending_membership_blocks_even_valid_session_signature(): void
    {
        $key = random_bytes(32);
        $reader = $this->reader('a', $key);
        $token = $this->token($key);
        $admin = $this->db('admin');
        $admin->exec("UPDATE f24.membership_fixture SET mock_state='suspended'
            WHERE organization_id='org-a' AND actor_id='DEMO-USER-A'");
        try {
            $this->deny($reader->read($this->org('a'), $token, self::NOW), 'revoked_lab_identity');
        } finally {
            $admin->exec("UPDATE f24.membership_fixture SET mock_state='active'
                WHERE organization_id='org-a' AND actor_id='DEMO-USER-A'");
        }
    }

    public function test_removed_view_permission_revokes_access_until_restore(): void
    {
        $key = random_bytes(32);
        $reader = $this->reader('a', $key);
        $token = $this->token($key);
        $admin = $this->db('admin');
        $admin->exec("UPDATE f24.membership_fixture SET can_view_services=false
            WHERE organization_id='org-a' AND actor_id='DEMO-USER-A'");
        try {
            $this->deny($reader->read($this->org('a'), $token, self::NOW), 'revoked_lab_identity');
        } finally {
            $admin->exec("UPDATE f24.membership_fixture SET can_view_services=true
                WHERE organization_id='org-a' AND actor_id='DEMO-USER-A'");
        }
    }

    public function test_session_revision_invalidation_requires_new_proof(): void
    {
        $key = random_bytes(32);
        $reader = $this->reader('a', $key);
        $old = $this->token($key);
        $admin = $this->db('admin');
        $admin->exec("UPDATE f24.session_fixture SET session_revision=2
            WHERE organization_id='org-a' AND session_id='DEMO-SESSION-A'");
        try {
            $this->deny($reader->read($this->org('a'), $old, self::NOW), 'revoked_lab_identity');
            $next = $this->token($key, 'a', ['srev' => 2]);
            self::assertTrue($reader->read($this->org('a'), $next, self::NOW)->isPreparedPreview());
        } finally {
            $admin->exec("UPDATE f24.session_fixture SET session_revision=1
                WHERE organization_id='org-a' AND session_id='DEMO-SESSION-A'");
        }
    }

    public function test_membership_revision_invalidation_blocks_old_proof(): void
    {
        $key = random_bytes(32);
        $reader = $this->reader('a', $key);
        $token = $this->token($key);
        $admin = $this->db('admin');
        $admin->exec("UPDATE f24.membership_fixture SET membership_revision=2
            WHERE organization_id='org-a' AND actor_id='DEMO-USER-A'");
        try {
            $this->deny($reader->read($this->org('a'), $token, self::NOW), 'revoked_lab_identity');
            $next = $this->token($key, 'a', ['mrev' => 2]);
            self::assertTrue($reader->read($this->org('a'), $next, self::NOW)->isPreparedPreview());
        } finally {
            $admin->exec("UPDATE f24.membership_fixture SET membership_revision=1
                WHERE organization_id='org-a' AND actor_id='DEMO-USER-A'");
        }
    }

    public function test_disabled_auth_source_rls_blocks_reads_and_returns_no_partial_rows(): void
    {
        $key = random_bytes(32);
        $reader = $this->reader('a', $key);
        $admin = $this->db('admin');
        $admin->exec('ALTER TABLE f24.membership_fixture NO FORCE ROW LEVEL SECURITY');
        try {
            $this->deny(
                $reader->read($this->org('a'), $this->token($key), self::NOW),
                'revoked_lab_identity',
            );
        } finally {
            $admin->exec('ALTER TABLE f24.membership_fixture FORCE ROW LEVEL SECURITY');
        }
        self::assertTrue($reader->read($this->org('a'), $this->token($key), self::NOW)->isPreparedPreview());
    }

    public function test_readonly_role_cannot_reinstate_revoked_session_or_self_assign_membership(): void
    {
        $pdo = $this->db('a');
        foreach ([
            "UPDATE f24.session_fixture SET mock_state='active'
                WHERE organization_id='org-a'",
            "UPDATE f24.membership_fixture SET can_view_services=true
                WHERE organization_id='org-a'",
            "INSERT INTO f24.membership_fixture VALUES ('org-b','DEMO-USER-A',1,'active',true)",
        ] as $sql) {
            try {
                $pdo->exec($sql);
                self::fail('Unprivileged user modified synthetic auth table.');
            } catch (PDOException) {
                self::addToAssertionCount(1);
            }
        }
    }

    public function test_pagination_still_requires_valid_session_proof_for_every_page(): void
    {
        $key = random_bytes(32);
        $reader = $this->reader('a', $key);
        $token = $this->token($key);
        $first = $reader->read($this->org('a'), $token, self::NOW, 1);
        self::assertTrue($first->isPreparedPreview());
        self::assertSame('DEMO-SVC-LANDING', $first->nextCursor);
        $second = $reader->read($this->org('a'), $token, self::NOW, 1, $first->nextCursor);
        self::assertTrue($second->isPreparedPreview());
        self::assertSame('DEMO-SVC-ONEPAGE', $second->rows[0]['reference']);
        $this->deny(
            $reader->read($this->org('a'), $token.'tamper', self::NOW, 1, $first->nextCursor),
            'invalid_lab_identity_evidence',
        );
    }

    public function test_f25_read_holds_no_advisory_session_lock_after_successful_return(): void
    {
        $key = random_bytes(32);
        $pdo = $this->db('a');
        $reader = new F24SyntheticAuthorizedReader($pdo, $key);
        self::assertTrue($reader->read($this->org('a'), $this->token($key), self::NOW)->isPreparedPreview());
        $locks = $pdo->query(
            "SELECT count(*) FROM pg_catalog.pg_locks
              WHERE locktype='advisory' AND pid=pg_catalog.pg_backend_pid()"
        )->fetchColumn();
        self::assertSame(0, (int) $locks);
        self::assertFalse($pdo->inTransaction());
    }

    public function test_f25_writer_cannot_commit_session_revocation_while_matching_shared_fence_is_held(): void
    {
        $key = random_bytes(32);
        $org = $this->org('a');
        $token = $this->token($key);
        $reader = $this->reader('a', $key);
        $holder = $this->db('a');
        $admin = $this->db('admin');

        self::assertTrue($reader->read($org, $token, self::NOW)->isPreparedPreview());
        $holder->query('SELECT pg_catalog.pg_advisory_lock_shared(9402400001)')->closeCursor();
        try {
            $admin->exec("SET lock_timeout='250ms'");
            try {
                $admin->exec("UPDATE f24.session_fixture SET mock_state='revoked'
                    WHERE organization_id='org-a' AND session_id='DEMO-SESSION-A'");
                self::fail('A revocation writer bypassed the active shared fence.');
            } catch (PDOException) {
                self::addToAssertionCount(1);
            }
        } finally {
            $holder->query('SELECT pg_catalog.pg_advisory_unlock_shared(9402400001)')->closeCursor();
            $admin->exec('RESET lock_timeout');
        }

        // No pending revocation was committed on timeout.
        self::assertTrue($reader->read($org, $token, self::NOW)->isPreparedPreview());
        $admin->exec("UPDATE f24.session_fixture SET mock_state='revoked'
            WHERE organization_id='org-a' AND session_id='DEMO-SESSION-A'");
        try {
            $this->deny($reader->read($org, $token, self::NOW), 'revoked_lab_identity');
        } finally {
            $admin->exec("UPDATE f24.session_fixture SET mock_state='active'
                WHERE organization_id='org-a' AND session_id='DEMO-SESSION-A'");
        }
    }

    public function test_f25_membership_change_is_blocked_while_shared_fence_is_held(): void
    {
        $admin = $this->db('admin');
        $holder = $this->db('a');
        $holder->query('SELECT pg_catalog.pg_advisory_lock_shared(9402400001)')->closeCursor();
        try {
            $admin->exec("SET lock_timeout='250ms'");
            try {
                $admin->exec("UPDATE f24.membership_fixture SET membership_revision=2
                    WHERE organization_id='org-a' AND actor_id='DEMO-USER-A'");
                self::fail('Membership write bypassed the shared fence.');
            } catch (PDOException) {
                self::addToAssertionCount(1);
            }
        } finally {
            $holder->query('SELECT pg_catalog.pg_advisory_unlock_shared(9402400001)')->closeCursor();
            $admin->exec('RESET lock_timeout');
        }
        $state = $admin->query(
            "SELECT membership_revision FROM f24.membership_fixture
              WHERE organization_id='org-a' AND actor_id='DEMO-USER-A'"
        )->fetchColumn();
        self::assertSame(1, (int) $state);
    }

    public function test_f25_invalid_proof_and_revoked_proof_leave_no_shared_locks(): void
    {
        $key = random_bytes(32);
        $pdo = $this->db('a');
        $reader = new F24SyntheticAuthorizedReader($pdo, $key);

        $this->deny($reader->read($this->org('a'), 'broken', self::NOW), 'invalid_lab_identity_evidence');
        self::assertSame(0, (int) $pdo->query(
            "SELECT count(*) FROM pg_catalog.pg_locks
              WHERE locktype='advisory' AND pid=pg_catalog.pg_backend_pid()"
        )->fetchColumn());

        $admin = $this->db('admin');
        $admin->exec("UPDATE f24.session_fixture SET mock_state='revoked'
            WHERE organization_id='org-a' AND session_id='DEMO-SESSION-A'");
        try {
            $this->deny($reader->read($this->org('a'), $this->token($key), self::NOW), 'revoked_lab_identity');
            self::assertSame(0, (int) $pdo->query(
                "SELECT count(*) FROM pg_catalog.pg_locks
                  WHERE locktype='advisory' AND pid=pg_catalog.pg_backend_pid()"
            )->fetchColumn());
        } finally {
            $admin->exec("UPDATE f24.session_fixture SET mock_state='active'
                WHERE organization_id='org-a' AND session_id='DEMO-SESSION-A'");
        }
    }
}
