<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Domain\Organizations\OrganizationId;
use App\Labs\F24\F24LabSessionProof;
use App\Labs\F24\F24SyntheticAuthorizedReader;
use App\Labs\F31\F31ExternalWitnessReader;
use App\Labs\F31\F31RollbackAwareLabReader;
use PDO;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Executed explicitly AFTER a real pg_dump -Fc and pg_restore --clean
 * of the synthetic PRIMARY PostgreSQL container. The WITNESS container
 * must not be restored. Neither database is persistent or production.
 */
final class F32ActualPgRestoreWitnessTest extends TestCase
{
    private const NOW = 1800000030;

    protected function setUp(): void
    {
        parent::setUp();
        if (getenv('F22_ENABLE_LAB') !== 'NON_OPERATIONAL_F22'
            || getenv('F31_ENABLE_LAB') !== 'INDEPENDENT_DISPOSABLE_WITNESS'
            || getenv('F32_AFTER_RESTORE') !== 'DUMP_RESTORE_COMPLETED') {
            self::markTestSkipped('F32 only runs after a real synthetic primary pg_restore.');
        }
    }

    private function connect(string $instance, string $role = 'reader'): PDO
    {
        $primary = [
            'reader' => ['f21_org_a', getenv('F22_PG_PASSWORD_A')],
            'admin' => ['postgres', getenv('F22_PG_PASSWORD_ADMIN')],
        ];
        $witness = [
            'reader' => ['f31_witness_reader', getenv('F31_PG_PASSWORD_READER')],
        ];
        $credentials = ($instance === 'primary' ? $primary : $witness)[$role] ?? null;
        $port = $instance === 'primary' ? getenv('F22_PG_PORT') : getenv('F31_PG_PORT');

        if (!in_array($instance, ['primary', 'witness'], true)
            || $credentials === null
            || getenv('F22_PG_HOST') !== '127.0.0.1'
            || getenv('F31_PG_HOST') !== '127.0.0.1'
            || getenv('F22_PG_PORT') === getenv('F31_PG_PORT')
            || !is_string($port)
            || preg_match('/\A[0-9]{4,5}\z/D', $port) !== 1
            || (int) $port > 65535
            || preg_match('/\A[a-f0-9]{64}\z/D', (string) $credentials[1]) !== 1) {
            throw new RuntimeException('F32 requires two distinct disposable PostgreSQL endpoints.');
        }
        return new PDO(
            'pgsql:host=127.0.0.1;port='.$port.';dbname=postgres;connect_timeout=3;sslmode=disable',
            $credentials[0], $credentials[1],
            [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
            ],
        );
    }

    private function token(string $key, int $sessionRevision): string
    {
        return F24LabSessionProof::signFixture([
            'aud' => 'portal-f24-synthetic-read',
            'exp' => 1800000900,
            'iat' => 1800000000,
            'iss' => 'imagine-portal-f24-lab-only',
            'mfa' => 1800000000,
            'mrev' => 1,
            'nonce' => str_repeat('f', 32),
            'org' => 'org-a',
            'sid' => 'DEMO-SESSION-A',
            'srev' => $sessionRevision,
            'sub' => 'DEMO-USER-A',
            'ver' => 1,
        ], $key);
    }

    public function test_real_pg_restore_recovers_old_primary_session_but_external_floor_blocks_old_token(): void
    {
        $org = new OrganizationId('org-a');
        $primaryAdmin = $this->connect('primary', 'admin');
        $restoredRevision = $primaryAdmin->query(
            "SELECT session_revision FROM f24.session_fixture
             WHERE organization_id='org-a' AND session_id='DEMO-SESSION-A'"
        )->fetchColumn();
        $restoredState = $primaryAdmin->query(
            "SELECT mock_state FROM f24.session_fixture
             WHERE organization_id='org-a' AND session_id='DEMO-SESSION-A'"
        )->fetchColumn();
        self::assertSame(1, (int) $restoredRevision);
        self::assertSame('active', $restoredState);

        $witness = $this->connect('witness');
        $floor = $witness->query(
            "SELECT session_floor, sequence_number FROM f31.revocation_floor
             WHERE organization_id='org-a'"
        )->fetch(PDO::FETCH_ASSOC);
        self::assertIsArray($floor);
        self::assertSame(2, (int) $floor['session_floor']);
        self::assertSame(2, (int) $floor['sequence_number']);

        $key = random_bytes(32);
        $oldProof = $this->token($key, 1);
        $primary = new F24SyntheticAuthorizedReader($this->connect('primary'), $key);
        $replayable = $primary->read($org, $oldProof, self::NOW);
        self::assertTrue(
            $replayable->isPreparedPreview(),
            'F24 alone intentionally shows the old fixture state after restore.',
        );
        self::assertFalse($replayable->authorizesServiceAccess());

        $facade = new F31RollbackAwareLabReader(
            new F24SyntheticAuthorizedReader($this->connect('primary'), $key),
            new F31ExternalWitnessReader($this->connect('witness')),
            $key,
        );
        $blocked = $facade->read($org, $oldProof, self::NOW);
        self::assertSame('stale_external_witness', $blocked->reason);
        self::assertFalse($blocked->isPreparedPreview());
        self::assertSame([], $blocked->rows);
        self::assertSame(0, $blocked->totalServices);
        self::assertSame(0, $blocked->totalRequests);
        self::assertSame(0, $blocked->totalContracts);
        self::assertNull($blocked->nextCursor);
        self::assertFalse($blocked->mayExposeCustomerData());
        self::assertFalse($blocked->authorizesServiceAccess());
        self::assertFalse($blocked->authorizesContractOrPayment());
        self::assertFalse($blocked->grantsUniversityBenefit());
    }

    public function test_unavailable_external_witness_never_falls_back_to_restored_session(): void
    {
        $key = random_bytes(32);
        $organization = new OrganizationId('org-a');
        $witnessPdo = $this->connect('witness');
        $witnessReader = new F31ExternalWitnessReader($witnessPdo);

        // Force a deterministic witness-unavailable condition without
        // touching the shared witness service or its monotonic floor.
        $witnessPdo->beginTransaction();
        try {
            $reader = new F31RollbackAwareLabReader(
                new F24SyntheticAuthorizedReader($this->connect('primary'), $key),
                $witnessReader,
                $key,
            );
            $result = $reader->read($organization, $this->token($key, 1), self::NOW);
            self::assertSame('unavailable_external_witness', $result->reason);
            self::assertFalse($result->isPreparedPreview());
            self::assertSame([], $result->rows);
            self::assertSame(0, $result->totalServices);
            self::assertSame(0, $result->totalRequests);
            self::assertSame(0, $result->totalContracts);
            self::assertNull($result->nextCursor);
            self::assertFalse($result->mayExposeCustomerData());
            self::assertFalse($result->authorizesServiceAccess());
            self::assertFalse($result->authorizesContractOrPayment());
        } finally {
            $witnessPdo->rollBack();
        }
    }

    public function test_proof_matching_witness_floor_is_still_denied_by_restored_primary_revision(): void
    {
        $key = random_bytes(32);
        $org = new OrganizationId('org-a');
        $reader = new F31RollbackAwareLabReader(
            new F24SyntheticAuthorizedReader($this->connect('primary'), $key),
            new F31ExternalWitnessReader($this->connect('witness')),
            $key,
        );
        $newProof = $this->token($key, 2);
        $result = $reader->read($org, $newProof, self::NOW);
        self::assertFalse($result->isPreparedPreview());
        self::assertSame('revoked_lab_identity', $result->reason);
        self::assertSame([], $result->rows);
        self::assertFalse($result->authorizesServiceAccess());
    }
}
