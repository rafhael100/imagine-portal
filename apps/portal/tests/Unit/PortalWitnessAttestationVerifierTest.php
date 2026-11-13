<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Domain\Organizations\OrganizationId;
use App\Domain\RestoreSafety\LabExternalWitnessFloor;
use App\Domain\RestoreSafety\PortalWitnessAttestationVerifier;
use PHPUnit\Framework\TestCase;
use Tests\Support\DisposableWitnessAttestationSigner;

final class PortalWitnessAttestationVerifierTest extends TestCase
{
    private const CLUSTER = 'demo-pinned-witness';
    private OrganizationId $tenant;
    private DisposableWitnessAttestationSigner $signer;
    private PortalWitnessAttestationVerifier $verifier;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tenant = new OrganizationId('demo-org-a');
        $keypair = sodium_crypto_sign_keypair();
        $this->signer = new DisposableWitnessAttestationSigner(
            sodium_crypto_sign_secretkey($keypair),
            new LabExternalWitnessFloor(
                $this->tenant, self::CLUSTER, 2, 5, 2, 2
            ),
        );
        $this->verifier = new PortalWitnessAttestationVerifier();
    }

    /** @return array{raw:string,challenge:string,pub:string} */
    private function signed(): array
    {
        $nonce = bin2hex(random_bytes(32));
        $signed = $this->signer->respond($this->tenant, $nonce);
        self::assertIsString($signed);
        return ['raw'=>$signed, 'challenge'=>$nonce, 'pub'=>$this->signer->publicKey()];
    }

    private function verify(array $proof, ?OrganizationId $tenant = null): ?LabExternalWitnessFloor
    {
        return $this->verifier->verify(
            $proof['raw'],
            $tenant ?? $this->tenant,
            $proof['challenge'],
            $proof['pub']
        );
    }

    public function test_signed_scope_is_parsed_but_never_unlocks_production(): void
    {
        $proof = $this->signed();
        $floor = $this->verify($proof);
        self::assertInstanceOf(LabExternalWitnessFloor::class, $floor);
        self::assertSame(2, $floor->sequence);
        self::assertSame(5, $floor->coveredBackupSequence);
        self::assertSame(2, $floor->sessionFloor);
        self::assertSame(2, $floor->membershipFloor);
        self::assertSame(self::CLUSTER, $floor->witnessClusterId);
        self::assertFalse($this->verifier->canAuthorizeProductionServing());
        self::assertFalse(method_exists($this->verifier,'issueSession'));
        self::assertFalse(method_exists($this->verifier,'allowRequest'));
    }

    public function test_nonce_replay_wrong_tenant_and_mismatched_key_fail_closed(): void
    {
        $proof = $this->signed();
        $proof['challenge'] = bin2hex(random_bytes(32));
        self::assertNull($this->verify($proof));

        $proof = $this->signed();
        $proof['pub'] = sodium_crypto_sign_publickey(sodium_crypto_sign_keypair());
        self::assertNull($this->verify($proof));
        self::assertNull($this->verify($this->signed(), new OrganizationId('demo-org-b')));
    }

    public function test_payload_tamper_without_resigning_is_denied_for_every_authority_field(): void
    {
        $proof = $this->signed();
        foreach ([
            'version'=>2,
            'challenge'=>bin2hex(random_bytes(32)),
            'tenant'=>'demo-org-b',
            'cluster'=>'demo-other-witness',
            'sequence'=>3,
            'coveredBackupSequence'=>4,
            'sessionFloor'=>3,
            'membershipFloor'=>3,
        ] as $field=>$altered) {
            $changed = $proof;
            $object = json_decode($proof['raw'], true, 8, JSON_THROW_ON_ERROR);
            $object['payload'][$field] = $altered;
            $changed['raw'] = json_encode($object, JSON_THROW_ON_ERROR);
            self::assertNull($this->verify($changed), $field);
        }
    }

    public function test_schema_changes_are_refused_even_when_signed_by_the_fixture(): void
    {
        $proof = $this->signed();
        foreach ([
            ['payload'=>[], 'signature'=>''],
            ['signature'=>'', 'payload'=>[]],
            ['payload'=>[], 'signature'=>'', 'unexpected'=>1],
            ['payload'=>'string', 'signature'=>''],
            ['payload'=>false, 'signature'=>''],
            ['payload'=>[], 'signature'=>123],
        ] as $object) {
            $changed = $proof;
            $changed['raw'] = json_encode($object, JSON_THROW_ON_ERROR);
            self::assertNull($this->verify($changed));
        }
        foreach ([
            '',
            'null',
            '[]',
            'not-json',
            str_repeat('a',2049),
            '{"payload":{"version":1},"signature":"invalid"}',
        ] as $malformed) {
            $changed = $proof;
            $changed['raw'] = $malformed;
            self::assertNull($this->verify($changed));
        }
    }

    public function test_broken_signature_and_invalid_key_or_challenge_are_denied(): void
    {
        $proof = $this->signed();
        foreach (['invalid','',str_repeat('Z',90),str_repeat('A',88)] as $signature) {
            $changed = $proof;
            $decoded = json_decode($proof['raw'], true, 8, JSON_THROW_ON_ERROR);
            $decoded['signature'] = $signature;
            $changed['raw'] = json_encode($decoded, JSON_THROW_ON_ERROR);
            self::assertNull($this->verify($changed));
        }
        foreach (['', 'bad',strtoupper($proof['challenge']),str_repeat('f',65)] as $nonce) {
            $changed = $proof;
            $changed['challenge'] = $nonce;
            self::assertNull($this->verify($changed));
        }
        $proof['pub'] = 'short';
        self::assertNull($this->verify($proof));
    }

    public function test_valid_signature_of_invalid_floor_values_does_not_create_a_witness(): void
    {
        $keypair = sodium_crypto_sign_keypair();
        $private = sodium_crypto_sign_secretkey($keypair);
        $pub = sodium_crypto_sign_publickey($keypair);
        $nonce = bin2hex(random_bytes(32));
        $base = [
            'version'=>1,
            'challenge'=>$nonce,
            'tenant'=>'demo-org-a',
            'cluster'=>self::CLUSTER,
            'sequence'=>2,
            'coveredBackupSequence'=>5,
            'sessionFloor'=>2,
            'membershipFloor'=>2,
        ];
        foreach ([
            ['sequence'=>0],
            ['sessionFloor'=>-1],
            ['coveredBackupSequence'=>1_000_000_001],
            ['membershipFloor'=>'2'],
            ['cluster'=>'../secret'],
            ['tenant'=>'demo-org-b'],
        ] as $change) {
            $payload = array_replace($base,$change);
            $message = json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
            $raw = json_encode([
                'payload'=>$payload,
                'signature'=>base64_encode(sodium_crypto_sign_detached($message,$private)),
            ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
            self::assertNull(
                $this->verifier->verify($raw,$this->tenant,$nonce,$pub),
                (string) array_key_first($change),
            );
        }
    }

    public function test_live_signed_but_stale_floor_is_not_proof_of_durability(): void
    {
        // A restored signer is still free to sign its old floor with a fresh
        // challenge. This cryptographic primitive cannot detect rollback.
        $proof = $this->signed();
        self::assertNotNull($this->verify($proof));
        self::assertFalse($this->verifier->canAuthorizeProductionServing());
    }
}
