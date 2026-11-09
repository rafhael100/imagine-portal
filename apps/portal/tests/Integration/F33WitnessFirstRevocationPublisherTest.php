<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Domain\ClientOverview\DemoServiceRelationPage;
use App\Domain\Organizations\OrganizationId;
use App\Labs\F24\F24LabSessionProof;
use App\Labs\F24\F24SyntheticAuthorizedReader;
use App\Labs\F31\F31ExternalWitnessReader;
use App\Labs\F31\F31RollbackAwareLabReader;
use App\Labs\F33\F33SyntheticRevocationEvent;
use App\Labs\F33\F33WitnessFirstRevocationPublisher;
use PDO;
use PDOException;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * F33 tests are deliberately isolated from all real IAM/revocation systems.
 * A second disposable DB is not an independently operated trust domain.
 */
final class F33WitnessFirstRevocationPublisherTest extends TestCase
{
    private const NOW = 1800000030;

    protected function setUp(): void
    {
        parent::setUp();
        if (getenv('F22_ENABLE_LAB') !== 'NON_OPERATIONAL_F22'
            || getenv('F31_ENABLE_LAB') !== 'INDEPENDENT_DISPOSABLE_WITNESS'
            || getenv('F33_ENABLE_LAB') !== 'WITNESS_FIRST_SYNTHETIC_ONLY') {
            self::markTestSkipped('F33 requires two disposable PG18 instances and restricted publisher.');
        }
    }

    protected function tearDown(): void
    {
        // Only the fixture admin is permitted to disable monotonicity for
        // cleanup. This explicitly DOES NOT model durable production trust.
        if (getenv('F33_ENABLE_LAB') === 'WITNESS_FIRST_SYNTHETIC_ONLY') {
            $this->resetFixtures();
        }
        parent::tearDown();
    }

    private function db(string $cluster, string $role): PDO
    {
        $primary = [
            'a' => ['f21_org_a', getenv('F22_PG_PASSWORD_A')],
            'b' => ['f21_org_b', getenv('F22_PG_PASSWORD_B')],
            'admin' => ['postgres', getenv('F22_PG_PASSWORD_ADMIN')],
        ];
        $external = [
            'reader' => ['f31_witness_reader', getenv('F31_PG_PASSWORD_READER')],
            'publisher' => ['f33_revocation_publisher', getenv('F33_PG_PASSWORD_PUBLISHER')],
            'admin' => ['postgres', getenv('F31_PG_PASSWORD_ADMIN')],
        ];
        $port = $cluster === 'primary' ? getenv('F22_PG_PORT') : getenv('F31_PG_PORT');
        $login = ($cluster === 'primary' ? $primary : $external)[$role] ?? null;

        if (!in_array($cluster, ['primary', 'witness'], true)
            || $login === null
            || getenv('F22_PG_HOST') !== '127.0.0.1'
            || getenv('F31_PG_HOST') !== '127.0.0.1'
            || getenv('F22_PG_PORT') === getenv('F31_PG_PORT')
            || !is_string($port)
            || preg_match('/\A[0-9]{4,5}\z/D', $port) !== 1
            || (int) $port > 65535
            || !is_string($login[1])
            || preg_match('/\A[a-f0-9]{64}\z/D', $login[1]) !== 1) {
            throw new RuntimeException('F33 must use distinct ephemeral localhost database identities.');
        }
        return new PDO(
            'pgsql:host=127.0.0.1;port='.$port.';dbname=postgres;connect_timeout=3;sslmode=disable',
            $login[0], $login[1],
            [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
            ],
        );
    }

    private function org(string $tenant = 'a'): OrganizationId
    {
        return new OrganizationId($tenant === 'a' ? 'org-a' : 'org-b');
    }

    private function command(
        string $eventId,
        string $kind = 'session',
        string $tenant = 'a',
        int $sessionFloor = 2,
        int $membershipFloor = 1,
    ): F33SyntheticRevocationEvent {
        $suffix = strtoupper($tenant);
        return new F33SyntheticRevocationEvent(
            $this->org($tenant),
            $eventId, $kind,
            $kind === 'session' ? 'DEMO-SESSION-'.$suffix : 'DEMO-USER-'.$suffix,
            $sessionFloor, $membershipFloor, 1800000031,
        );
    }

    private function publisher(): F33WitnessFirstRevocationPublisher
    {
        return new F33WitnessFirstRevocationPublisher(
            $this->db('witness', 'publisher'),
            $this->db('primary', 'admin'),
        );
    }

    private function token(string $key, string $tenant = 'a', int $sessionRev = 1, int $memberRev = 1): string
    {
        $suffix = strtoupper($tenant);
        return F24LabSessionProof::signFixture([
            'aud' => 'portal-f24-synthetic-read',
            'exp' => 1800000900,
            'iat' => 1800000000,
            'iss' => 'imagine-portal-f24-lab-only',
            'mfa' => 1800000000,
            'mrev' => $memberRev,
            'nonce' => str_repeat('8', 32),
            'org' => 'org-'.$tenant,
            'sid' => 'DEMO-SESSION-'.$suffix,
            'srev' => $sessionRev,
            'sub' => 'DEMO-USER-'.$suffix,
            'ver' => 1,
        ], $key);
    }

    private function witnessAware(string $key, string $tenant = 'a'): F31RollbackAwareLabReader
    {
        return new F31RollbackAwareLabReader(
            new F24SyntheticAuthorizedReader($this->db('primary', $tenant), $key),
            new F31ExternalWitnessReader($this->db('witness', 'reader')),
            $key,
        );
    }

    private function floor(string $tenant = 'a'): array
    {
        $stmt = $this->db('witness', 'admin')->prepare(
            'SELECT session_floor, membership_floor, sequence_number
               FROM f31.revocation_floor WHERE organization_id=:org'
        );
        $stmt->execute(['org' => 'org-'.$tenant]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row)) {
            throw new RuntimeException('Missing synthetic witness row.');
        }
        return array_map('intval', $row);
    }

    private function primarySession(string $tenant = 'a'): array
    {
        $stmt = $this->db('primary', 'admin')->prepare(
            'SELECT session_revision, mock_state
               FROM f24.session_fixture
              WHERE organization_id=:org AND session_id=:sid'
        );
        $stmt->execute(['org' => 'org-'.$tenant, 'sid' => 'DEMO-SESSION-'.strtoupper($tenant)]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row)) {
            throw new RuntimeException('Missing synthetic primary session.');
        }
        return ['revision' => (int) $row['session_revision'], 'state' => $row['mock_state']];
    }

    private function block(DemoServiceRelationPage $page, string $reason): void
    {
        self::assertSame($reason, $page->reason);
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

    private function resetFixtures(): void
    {
        $primary = $this->db('primary', 'admin');
        $primary->exec("UPDATE f24.session_fixture SET mock_state='active',session_revision=1");
        $primary->exec("UPDATE f24.membership_fixture
                        SET mock_state='active',membership_revision=1,can_view_services=true");

        $witness = $this->db('witness', 'admin');
        $witness->exec('DELETE FROM f33.publication_ledger');
        $witness->exec('ALTER TABLE f31.revocation_floor DISABLE TRIGGER f31_floor_monotonic');
        try {
            $witness->exec("UPDATE f31.revocation_floor
                              SET session_floor=1,membership_floor=1,
                                  sequence_number=1,changed_at=1800000000");
        } finally {
            $witness->exec('ALTER TABLE f31.revocation_floor ENABLE TRIGGER f31_floor_monotonic');
        }
    }

    public function test_baseline_fixture_has_no_published_revocation(): void
    {
        self::assertSame(
            ['session_floor' => 1,'membership_floor' => 1,'sequence_number' => 1],
            $this->floor(),
        );
        $key = random_bytes(32);
        $page = $this->witnessAware($key)->read($this->org(), $this->token($key), self::NOW);
        self::assertTrue($page->isPreparedPreview());
        self::assertFalse($page->mayExposeCustomerData());
        self::assertFalse($page->authorizesServiceAccess());
    }

    public function test_published_witness_intent_blocks_old_session_before_primary_write_and_after_crash(): void
    {
        $command = $this->command('F33-TEST-SESSION-FIRST');
        $publisher = $this->publisher();
        $key = random_bytes(32);
        $old = $this->token($key);

        self::assertSame('published', $publisher->publishWitness($command));
        self::assertSame(['revision' => 1,'state' => 'active'], $this->primarySession());
        self::assertSame(2, $this->floor()['session_floor']);

        // Crash simulation: intentionally stop BEFORE touching the primary.
        $this->block(
            $this->witnessAware($key)->read($this->org(), $old, self::NOW),
            'stale_external_witness',
        );

        // The older primary-only lab remains replayable; the F31 path is not.
        $primaryOnly = new F24SyntheticAuthorizedReader($this->db('primary', 'a'), $key);
        self::assertTrue($primaryOnly->read($this->org(), $old, self::NOW)->isPreparedPreview());

        self::assertTrue($publisher->finishPrimary($command));
        self::assertSame(['revision' => 2,'state' => 'revoked'], $this->primarySession());
        $this->block(
            $this->witnessAware($key)->read($this->org(), $old, self::NOW),
            'stale_external_witness',
        );
        $this->block(
            $this->witnessAware($key)->read($this->org(), $this->token($key, sessionRev: 2), self::NOW),
            'revoked_lab_identity',
        );
    }

    public function test_exact_event_retry_does_not_double_advance_sequence_or_overwrite_primary(): void
    {
        $command = $this->command('F33-TEST-IDEMPOTENCE');
        $publisher = $this->publisher();
        self::assertSame('published', $publisher->publishWitness($command));
        self::assertSame('replayed', $publisher->publishWitness($command));
        self::assertTrue($publisher->finishPrimary($command));
        self::assertTrue($publisher->finishPrimary($command));
        self::assertSame(2, $this->floor()['sequence_number']);
        self::assertSame(2, $this->floor()['session_floor']);
        self::assertSame(['revision' => 2,'state' => 'revoked'], $this->primarySession());
        $count = $this->db('witness', 'admin')->query(
            "SELECT count(*) FROM f33.publication_ledger
              WHERE event_id='F33-TEST-IDEMPOTENCE'"
        )->fetchColumn();
        self::assertSame(1, (int) $count);
    }

    public function test_same_id_cannot_be_reused_for_conflicting_target_or_identity(): void
    {
        $publisher = $this->publisher();
        self::assertSame('published', $publisher->publishWitness(
            $this->command('F33-TEST-ID-REUSE'),
        ));
        self::assertNull($publisher->publishWitness(
            $this->command('F33-TEST-ID-REUSE', sessionFloor: 3),
        ));
        self::assertNull($publisher->publishWitness(
            $this->command('F33-TEST-ID-REUSE', kind: 'membership', sessionFloor: 1, membershipFloor: 2),
        ));
        self::assertSame(2, $this->floor()['session_floor']);
        self::assertSame(2, $this->floor()['sequence_number']);
        self::assertSame(['revision' => 1,'state' => 'active'], $this->primarySession());
    }

    public function test_witness_publisher_role_cannot_directly_lower_floor_or_delete_event_ledger(): void
    {
        $pdo = $this->db('witness', 'publisher');
        foreach ([
            "UPDATE f31.revocation_floor SET session_floor=1 WHERE organization_id='org-a'",
            "DELETE FROM f31.revocation_floor WHERE organization_id='org-a'",
            "DELETE FROM f33.publication_ledger",
            "INSERT INTO f33.publication_ledger(event_id) VALUES ('F33-TEST-BYPASS')",
            "ALTER TABLE f31.revocation_floor DISABLE TRIGGER f31_floor_monotonic",
        ] as $sql) {
            try {
                $pdo->exec($sql);
                self::fail('Publisher obtained unexpected direct SQL privileges.');
            } catch (PDOException) {
                self::addToAssertionCount(1);
            }
        }
        self::assertSame(1, $this->floor()['session_floor']);
    }

    public function test_unavailable_publish_permission_blocks_primary_revocation(): void
    {
        $witnessAdmin = $this->db('witness', 'admin');
        $publisher = $this->publisher();
        $command = $this->command('F33-TEST-WITNESS-UNAVAILABLE');

        $witnessAdmin->exec('REVOKE EXECUTE ON FUNCTION f33.publish_revocation(
            text,text,text,text,integer,integer,bigint) FROM f33_revocation_publisher');
        try {
            self::assertNull($publisher->publishWitness($command));
            self::assertFalse($publisher->finishPrimary($command));
            self::assertSame(['revision' => 1,'state' => 'active'], $this->primarySession());
            self::assertSame(1, $this->floor()['session_floor']);
        } finally {
            $witnessAdmin->exec('GRANT EXECUTE ON FUNCTION f33.publish_revocation(
                text,text,text,text,integer,integer,bigint) TO f33_revocation_publisher');
        }
        self::assertTrue($publisher->finishPrimary($command));
        self::assertSame(['revision' => 2,'state' => 'revoked'], $this->primarySession());
    }

    public function test_primary_writer_lock_timeout_after_witness_publication_leaves_old_token_denied(): void
    {
        $command = $this->command('F33-TEST-PRIMARY-TIMEOUT');
        $publisher = $this->publisher();
        $holder = $this->db('primary', 'a');
        $key = random_bytes(32);
        $old = $this->token($key);
        $holder->query('SELECT pg_catalog.pg_advisory_lock_shared(9402400001)')->closeCursor();
        try {
            self::assertFalse($publisher->finishPrimary($command));
            self::assertSame(['revision' => 1,'state' => 'active'], $this->primarySession());
            self::assertSame(2, $this->floor()['session_floor']);
            $this->block(
                $this->witnessAware($key)->read($this->org(), $old, self::NOW),
                'stale_external_witness',
            );
        } finally {
            $holder->query('SELECT pg_catalog.pg_advisory_unlock_shared(9402400001)')->closeCursor();
        }
        self::assertTrue($publisher->finishPrimary($command));
        self::assertSame(['revision' => 2,'state' => 'revoked'], $this->primarySession());
    }

    public function test_membership_revocation_publication_precedes_primary_and_is_retryable(): void
    {
        $command = $this->command(
            'F33-TEST-MEMBER-FIRST', kind: 'membership',
            sessionFloor: 1, membershipFloor: 2,
        );
        $publisher = $this->publisher();
        $key = random_bytes(32);
        self::assertSame('published', $publisher->publishWitness($command));
        self::assertSame(2, $this->floor()['membership_floor']);
        $this->block(
            $this->witnessAware($key)->read($this->org(), $this->token($key), self::NOW),
            'stale_external_witness',
        );
        self::assertTrue($publisher->finishPrimary($command));
        $member = $this->db('primary', 'admin')->query(
            "SELECT membership_revision,mock_state,can_view_services
               FROM f24.membership_fixture
              WHERE organization_id='org-a' AND actor_id='DEMO-USER-A'"
        )->fetch(PDO::FETCH_ASSOC);
        self::assertIsArray($member);
        self::assertSame(2, (int) $member['membership_revision']);
        self::assertSame('revoked', $member['mock_state']);
        self::assertSame(false, $member['can_view_services']);
        self::assertTrue($publisher->finishPrimary($command));
    }

    public function test_revocation_of_org_b_does_not_change_org_a_floor_or_primary_session(): void
    {
        $command = $this->command('F33-TEST-TENANT-B', tenant: 'b');
        $publisher = $this->publisher();
        self::assertSame('published', $publisher->publishWitness($command));
        self::assertSame(2, $this->floor('b')['session_floor']);
        self::assertSame(1, $this->floor('a')['session_floor']);
        $key = random_bytes(32);
        $this->block(
            $this->witnessAware($key, 'b')->read(
                $this->org('b'), $this->token($key, 'b'), self::NOW,
            ), 'stale_external_witness',
        );
        self::assertTrue(
            $this->witnessAware($key, 'a')->read(
                $this->org('a'), $this->token($key, 'a'), self::NOW,
            )->isPreparedPreview(),
        );
        self::assertTrue($publisher->finishPrimary($command));
        self::assertSame(['revision' => 2,'state' => 'revoked'], $this->primarySession('b'));
        self::assertSame(['revision' => 1,'state' => 'active'], $this->primarySession('a'));
    }

    public function test_invalid_fictional_subject_is_rejected_before_touching_any_database(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new F33SyntheticRevocationEvent(
            $this->org('a'), 'F33-TEST-INVALID', 'session',
            'DEMO-SESSION-B', 2, 1, 1800000031,
        );
    }
}
