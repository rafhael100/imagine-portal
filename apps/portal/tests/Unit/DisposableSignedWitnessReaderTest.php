<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Domain\Organizations\OrganizationId;
use App\Domain\RestoreSafety\LabExternalWitnessFloor;
use App\Domain\RestoreSafety\LabQuarantineEvidence;
use App\Domain\RestoreSafety\LabRestoreServingGate;
use App\Domain\RestoreSafety\LabRestoreSnapshot;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Tests\Support\DisposableSignedWitnessReader;
use Tests\Support\DisposableWitnessAttestationSigner;

final class DisposableSignedWitnessReaderTest extends TestCase
{
    private OrganizationId $tenant;
    private string $secretKey;
    private DisposableWitnessAttestationSigner $signer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tenant = new OrganizationId('demo-org-a');
        $this->secretKey = sodium_crypto_sign_secretkey(sodium_crypto_sign_keypair());
        $this->signer = new DisposableWitnessAttestationSigner(
            $this->secretKey, $this->floor()
        );
    }

    private function floor(
        int $sequence = 2,
        int $coverage = 5,
        int $session = 2,
        int $membership = 2,
    ): LabExternalWitnessFloor {
        return new LabExternalWitnessFloor(
            $this->tenant,'demo-detached-signer', $sequence,
            $coverage, $session, $membership
        );
    }

    private function reader(?DisposableWitnessAttestationSigner $signer = null,
                            ?string $pinnedPublic = null): DisposableSignedWitnessReader
    {
        $authority = $signer ?? $this->signer;
        return new DisposableSignedWitnessReader(
            $pinnedPublic ?? $this->signer->publicKey(),
            fn (OrganizationId $org, string $nonce): ?string => $authority->respond($org, $nonce),
        );
    }

    private function snapshot(bool $active = false): LabRestoreSnapshot
    {
        return new LabRestoreSnapshot(
            $this->tenant,'demo-primary-z1',4,1,1,$active,false
        );
    }

    private function quarantine(): LabQuarantineEvidence
    {
        return new LabQuarantineEvidence(true,true,true,true,true,true,true);
    }

    public function test_two_distinct_challenges_get_signed_observations_but_never_release(): void
    {
        $nonces = [];
        $reader = new DisposableSignedWitnessReader(
            $this->signer->publicKey(),
            function (OrganizationId $org, string $nonce) use (&$nonces): ?string {
                self::assertMatchesRegularExpression('/^[a-f0-9]{64}$/D', $nonce);
                $nonces[] = $nonce;
                return $this->signer->respond($org,$nonce);
            }
        );
        $decision = (new LabRestoreServingGate())->inspectFromReader(
            $this->snapshot(),$reader,$this->quarantine()
        );
        self::assertCount(2,$nonces);
        self::assertNotSame($nonces[0],$nonces[1]);
        self::assertTrue($decision->syntheticChecksPassed);
        self::assertSame('synthetic_recovery_candidate_only',$decision->reason);
        self::assertFalse($decision->canAuthorizeHttp());
        self::assertFalse($decision->canReleaseProductionServing());
        self::assertFalse($decision->canReissueCustomerSessions());
    }

    public function test_different_signer_and_key_rotation_without_pinning_are_rejected(): void
    {
        $other = new DisposableWitnessAttestationSigner(
            sodium_crypto_sign_secretkey(sodium_crypto_sign_keypair()), $this->floor()
        );
        self::assertNull($this->reader($other)->observe($this->tenant));
        self::assertNull($this->reader(pinnedPublic: random_bytes(32))->observe($this->tenant));
        self::assertNull($this->reader(pinnedPublic: 'bad')->observe($this->tenant));
        self::assertSame('external_witness_unavailable',
            (new LabRestoreServingGate())->inspectFromReader(
                $this->snapshot(), $this->reader($other), $this->quarantine()
            )->reason
        );
    }

    public function test_recorded_signature_cannot_be_replayed_to_a_fresh_challenge(): void
    {
        $oldSigned = $this->signer->respond($this->tenant,bin2hex(random_bytes(32)));
        self::assertIsString($oldSigned);
        $reader = new DisposableSignedWitnessReader(
            $this->signer->publicKey(),
            fn (OrganizationId $org, string $nonce): ?string => $oldSigned
        );
        self::assertNull($reader->observe($this->tenant));
        self::assertSame('external_witness_unavailable',
            (new LabRestoreServingGate())->inspectFromReader(
                $this->snapshot(),$reader,$this->quarantine()
            )->reason
        );
    }

    public function test_payload_tampering_breaks_detached_signature(): void
    {
        foreach (['coveredBackupSequence','sequence','sessionFloor',
            'membershipFloor','cluster','tenant'] as $field) {
            $reader = new DisposableSignedWitnessReader(
                $this->signer->publicKey(),
                function (OrganizationId $org,string $nonce) use ($field): ?string {
                    $att = json_decode(
                        $this->signer->respond($org,$nonce),true,8,JSON_THROW_ON_ERROR
                    );
                    $att['payload'][$field] = $field === 'tenant'
                        ? 'demo-org-b' : ($field === 'cluster' ? 'other-cluster' : 999);
                    return json_encode($att,JSON_THROW_ON_ERROR);
                }
            );
            self::assertNull($reader->observe($this->tenant));
        }
    }

    public function test_forged_schema_invalid_signature_foreign_tenant_and_disconnected_reader_fail(): void
    {
        $foreigner = new OrganizationId('demo-org-b');
        self::assertNull($this->reader()->observe($foreigner));
        foreach ([
            fn (): ?string => null,
            fn (): ?string => '',
            fn (): ?string => 'not-json',
            fn (): ?string => str_repeat('x',2049),
            fn (): ?string => '{"payload":{},"signature":"bad"}',
            fn (): ?string => '{"payload":[],"signature":""}',
        ] as $bad) {
            $reader = new DisposableSignedWitnessReader(
                $this->signer->publicKey(),
                fn (OrganizationId $org,string $nonce): ?string => $bad()
            );
            self::assertNull($reader->observe($this->tenant));
        }
        $throwing = new DisposableSignedWitnessReader(
            $this->signer->publicKey(),
            static function (OrganizationId $org,string $nonce): ?string {
                throw new RuntimeException('secret bearer should not be surfaced');
            },
        );
        self::assertNull($throwing->observe($this->tenant));
    }

    public function test_signer_advances_only_monotonically_for_the_same_tenant_and_cluster(): void
    {
        $this->signer->advance($this->floor(3,6,3,3));
        $floor = $this->reader()->observe($this->tenant);
        self::assertSame(3,$floor?->sequence);
        self::assertSame(6,$floor?->coveredBackupSequence);
        foreach ([
            $this->floor(3,7,4,4),
            $this->floor(4,5,4,4),
            $this->floor(4,7,2,4),
            $this->floor(4,7,4,2),
            new LabExternalWitnessFloor(
                new OrganizationId('demo-org-b'),
                'demo-detached-signer',4,7,4,4
            ),
            new LabExternalWitnessFloor(
                $this->tenant,'foreign-cluster',4,7,4,4
            ),
        ] as $wrong) {
            try {
                $this->signer->advance($wrong);
                self::fail('Volatile signer accepted non-monotonic fixture.');
            } catch (RuntimeException) {
                self::addToAssertionCount(1);
            }
        }
        self::assertSame(3,$this->reader()->observe($this->tenant)?->sequence);
    }

    public function test_witness_mutation_between_signed_reads_is_fail_closed(): void
    {
        $reads = 0;
        $reader = new DisposableSignedWitnessReader(
            $this->signer->publicKey(),
            function (OrganizationId $org,string $nonce) use (&$reads): ?string {
                $response = $this->signer->respond($org,$nonce);
                if (++$reads === 1) {
                    $this->signer->advance($this->floor(3,6,3,3));
                }
                return $response;
            },
        );
        $out = (new LabRestoreServingGate())->inspectFromReader(
            $this->snapshot(),$reader,$this->quarantine()
        );
        self::assertSame(2,$reads);
        self::assertSame('witness_changed_during_rehearsal',$out->reason);
        self::assertFalse($out->canReleaseProductionServing());
    }

    public function test_signed_but_rolled_back_signer_state_is_not_a_real_antireplay_proof(): void
    {
        // A restored signing process still has its REAL private key. It can
        // answer fresh nonces while unknowingly serving an old signed floor.
        // Signature verification DOES NOT prove durability or freshness of
        // its data; the synthetic gate may only suggest an offline candidate.
        $rolledBack = new DisposableWitnessAttestationSigner(
            $this->secretKey, $this->floor(2,5,2,2)
        );
        $result = (new LabRestoreServingGate())->inspectFromReader(
            $this->snapshot(),$this->reader($rolledBack),$this->quarantine()
        );
        self::assertTrue($result->syntheticChecksPassed);
        self::assertFalse($result->canAuthorizeHttp());
        self::assertFalse($result->canReleaseProductionServing());
        self::assertFalse($result->canReissueCustomerSessions());
        $active = (new LabRestoreServingGate())->inspectFromReader(
            $this->snapshot(true),$this->reader($rolledBack),$this->quarantine()
        );
        self::assertSame('restored_identity_still_active',$active->reason);
    }
}
