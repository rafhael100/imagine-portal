<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Organizations\OrganizationId;
use App\Domain\RestoreSafety\DenyingPortalWitnessChallengeTransport;
use App\Domain\RestoreSafety\LabExternalWitnessFloor;
use App\Domain\RestoreSafety\LabQuarantineEvidence;
use App\Domain\RestoreSafety\LabRestoreServingGate;
use App\Domain\RestoreSafety\LabRestoreSnapshot;
use App\Domain\RestoreSafety\PortalSignedWitnessReader;
use App\Domain\RestoreSafety\PortalWitnessAttestationVerifier;
use App\Domain\RestoreSafety\PortalWitnessChallengeTransport;
use Illuminate\Support\Facades\Route;
use RuntimeException;
use Tests\Support\DisposableWitnessAttestationSigner;
use Tests\TestCase;

final class PortalWitnessSafetyBindingsTest extends TestCase
{
    private function tenant(): OrganizationId
    {
        return new OrganizationId('demo-org-a');
    }

    private function snapshot(): LabRestoreSnapshot
    {
        return new LabRestoreSnapshot(
            $this->tenant(), 'demo-primary-z1', 4, 1, 1, false, false
        );
    }

    private function evidence(): LabQuarantineEvidence
    {
        return new LabQuarantineEvidence(true, true, true, true, true, true, true);
    }

    private function signer(): DisposableWitnessAttestationSigner
    {
        $private = sodium_crypto_sign_secretkey(sodium_crypto_sign_keypair());
        return new DisposableWitnessAttestationSigner(
            $private,
            new LabExternalWitnessFloor(
                $this->tenant(), 'demo-independent-witness', 2, 5, 2, 2
            ),
        );
    }

    public function test_runtime_binding_is_a_denial_that_cannot_fetch_any_remote_witness(): void
    {
        $transport = $this->app->make(PortalWitnessChallengeTransport::class);
        self::assertInstanceOf(DenyingPortalWitnessChallengeTransport::class, $transport);
        self::assertNull($transport->fetchAttestation(
            $this->tenant(), bin2hex(random_bytes(32))
        ));
        self::assertFalse(method_exists($transport, 'allowServing'));
        self::assertFalse(method_exists($transport, 'authorizeSession'));
    }

    public function test_no_witness_means_refusal_even_with_quarantine_flags_claimed_true(): void
    {
        $reader = new PortalSignedWitnessReader(
            $this->app->make(PortalWitnessChallengeTransport::class),
            sodium_crypto_sign_publickey(sodium_crypto_sign_keypair()),
            $this->app->make(PortalWitnessAttestationVerifier::class),
        );
        self::assertNull($reader->observe($this->tenant()));
        $result = (new LabRestoreServingGate())->inspectFromReader(
            $this->snapshot(), $reader, $this->evidence(),
        );
        self::assertSame('external_witness_unavailable', $result->reason);
        self::assertFalse($result->canAuthorizeHttp());
        self::assertFalse($result->canReleaseProductionServing());
        self::assertFalse($result->canReissueCustomerSessions());
        self::assertFalse($reader->canAuthorizeServing());
    }

    public function test_test_only_signed_response_still_cannot_make_customer_session_available(): void
    {
        $signer = $this->signer();
        $transport = new class($signer) implements PortalWitnessChallengeTransport {
            public function __construct(
                private DisposableWitnessAttestationSigner $signer,
            ) {}

            public function fetchAttestation(
                OrganizationId $organization, string $challenge,
            ): ?string {
                return $this->signer->respond($organization, $challenge);
            }
        };
        $reader = new PortalSignedWitnessReader(
            $transport, $signer->publicKey(),
            new PortalWitnessAttestationVerifier()
        );
        $claim = $reader->observe($this->tenant());
        self::assertInstanceOf(LabExternalWitnessFloor::class, $claim);
        self::assertSame(5, $claim->coveredBackupSequence);
        $verdict = (new LabRestoreServingGate())->inspectFromReader(
            $this->snapshot(), $reader, $this->evidence(),
        );
        self::assertSame('synthetic_recovery_candidate_only', $verdict->reason);
        self::assertTrue($verdict->syntheticChecksPassed);
        self::assertFalse($verdict->canAuthorizeHttp());
        self::assertFalse($verdict->canReleaseProductionServing());
        self::assertFalse($verdict->canReissueCustomerSessions());
    }

    public function test_exception_wrong_key_and_missing_credential_fail_closed(): void
    {
        $signer = $this->signer();
        $transport = new class implements PortalWitnessChallengeTransport {
            public function fetchAttestation(
                OrganizationId $organization, string $challenge,
            ): ?string {
                throw new RuntimeException('internal signer secret must not leak');
            }
        };
        $reader = new PortalSignedWitnessReader(
            $transport, $signer->publicKey(), new PortalWitnessAttestationVerifier(),
        );
        self::assertNull($reader->observe($this->tenant()));
        $result = (new LabRestoreServingGate())->inspectFromReader(
            $this->snapshot(), $reader, $this->evidence()
        );
        self::assertSame('external_witness_unavailable', $result->reason);
        self::assertStringNotContainsString('secret', $result->reason);

        $wrong = new PortalSignedWitnessReader(
            $transport, 'not-a-public-key', new PortalWitnessAttestationVerifier()
        );
        self::assertNull($wrong->observe($this->tenant()));
    }

    public function test_witness_registration_does_not_add_customer_or_recovery_routes(): void
    {
        $urls = array_map(static fn ($route) => $route->uri(), iterator_to_array(Route::getRoutes()));
        self::assertSame(['up'], $urls);
        foreach (['/portal/recovery','/auth/witness','/api/session/unlock','/login'] as $path) {
            $this->get($path)->assertNotFound();
        }
    }
}
