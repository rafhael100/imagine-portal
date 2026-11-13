<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Organizations\OrganizationId;
use App\Domain\RestoreSafety\DenyingPortalRevocationPublicationTransport;
use App\Domain\RestoreSafety\PortalRevocationPublicationProbe;
use App\Domain\RestoreSafety\PortalRevocationPublicationTransport;
use App\Domain\RestoreSafety\PortalRevocationReceiptVerifier;
use App\Domain\RestoreSafety\PortalSignedRevocationClaim;
use Closure;
use RuntimeException;
use Tests\TestCase;

final class PortalRevocationPublicationProbeTest extends TestCase
{
    private const EVENT = 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa';
    private const PRIMARY = 'demo-primary-z1';
    private string $publicKey;
    private string $privateKey;

    protected function setUp(): void
    {
        parent::setUp();
        $pair = sodium_crypto_sign_keypair();
        $this->privateKey = sodium_crypto_sign_secretkey($pair);
        $this->publicKey = sodium_crypto_sign_publickey($pair);
    }

    private function tenant(): OrganizationId
    {
        return new OrganizationId('demo-org-a');
    }

    /** @return array<string,mixed> */
    private function payload(OrganizationId $org, string $event, string $challenge): array
    {
        return [
            'version' => 1,
            'purpose' => 'imagine-portal/revocation-ack/v1',
            'challenge' => $challenge,
            'eventId' => $event,
            'tenant' => $org->value,
            'cluster' => 'demo-remote-witness',
            'sequence' => 5,
            'coveredBackupSequence' => 6,
            'sessionFloor' => 7,
            'membershipFloor' => 7,
        ];
    }

    private function sign(array $payload): string
    {
        $message = json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        return json_encode([
            'payload' => $payload,
            'signature' => base64_encode(sodium_crypto_sign_detached($message, $this->privateKey)),
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
    }

    /** @param Closure(OrganizationId,string,int,int,int,string):(?string) $callback */
    private function transport(Closure $callback): PortalRevocationPublicationTransport
    {
        return new class($callback) implements PortalRevocationPublicationTransport {
            public function __construct(private Closure $callback) {}
            public function publishForReview(
                OrganizationId $tenant, string $eventId, int $primaryBackupSequence,
                int $minimumSessionFloor, int $minimumMembershipFloor,
                string $challenge,
            ): ?string {
                return ($this->callback)(
                    $tenant, $eventId, $primaryBackupSequence,
                    $minimumSessionFloor, $minimumMembershipFloor, $challenge,
                );
            }
        };
    }

    private function probe(?PortalRevocationPublicationTransport $transport = null,
                           ?string $public = null): PortalRevocationPublicationProbe
    {
        return new PortalRevocationPublicationProbe(
            $transport ?? $this->app->make(PortalRevocationPublicationTransport::class),
            new PortalRevocationReceiptVerifier(),
            $public ?? $this->publicKey,
        );
    }

    private function inspect(PortalRevocationPublicationProbe $probe): ?PortalSignedRevocationClaim
    {
        return $probe->inspect($this->tenant(), self::EVENT, self::PRIMARY, 4, 6, 6);
    }

    public function test_laravel_runtime_binding_denies_publication_without_an_external_witness(): void
    {
        $transport = $this->app->make(PortalRevocationPublicationTransport::class);
        self::assertInstanceOf(DenyingPortalRevocationPublicationTransport::class, $transport);
        self::assertNull($this->inspect($this->probe()));
        self::assertFalse($this->probe()->canConfirmDurableRevocation());
        self::assertNull($transport->publishForReview(
            $this->tenant(), self::EVENT, 4, 6, 6, bin2hex(random_bytes(32))
        ));
    }

    public function test_event_bound_signed_claim_is_observable_but_never_revocation_authority(): void
    {
        $nonces = [];
        $transport = $this->transport(function (
            OrganizationId $org, string $id, int $backup, int $session,
            int $membership, string $nonce,
        ) use (&$nonces): string {
            self::assertSame(self::EVENT, $id);
            self::assertSame(4, $backup);
            self::assertSame(6, $session);
            self::assertSame(6, $membership);
            self::assertMatchesRegularExpression('/^[a-f0-9]{64}$/D', $nonce);
            $nonces[] = $nonce;
            return $this->sign($this->payload($org, $id, $nonce));
        });
        $probe = $this->probe($transport);
        $claim = $this->inspect($probe);
        self::assertInstanceOf(PortalSignedRevocationClaim::class, $claim);
        self::assertSame(self::EVENT, $claim->eventId);
        self::assertSame(5, $claim->floor->sequence);
        self::assertSame(6, $claim->floor->coveredBackupSequence);
        self::assertFalse($claim->canAcknowledgeDurableRevocation());
        self::assertFalse($claim->canAuthorizeServing());
        self::assertFalse($probe->canConfirmDurableRevocation());
        self::assertNotNull($this->inspect($probe));
        self::assertCount(2, $nonces);
        self::assertNotSame($nonces[0], $nonces[1]);
    }

    public function test_old_event_or_replayed_nonce_and_foreign_tenant_are_denied(): void
    {
        $stale = $this->sign($this->payload($this->tenant(), self::EVENT, bin2hex(random_bytes(32))));
        self::assertNull($this->inspect($this->probe(
            $this->transport(static fn (): string => $stale)
        )));
        $invalidEvent = $this->transport(fn (
            OrganizationId $org, string $id, int $b, int $s, int $m, string $nonce,
        ): string => $this->sign($this->payload(
            $org, 'bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbbb', $nonce
        )));
        self::assertNull($this->inspect($this->probe($invalidEvent)));

        $foreign = $this->transport(fn (
            OrganizationId $org, string $id, int $b, int $s, int $m, string $nonce,
        ): string => $this->sign($this->payload(
            new OrganizationId('demo-org-b'), $id, $nonce
        )));
        self::assertNull($this->inspect($this->probe($foreign)));
    }

    public function test_wrong_signer_insufficient_floors_or_unseparated_cluster_fail_closed(): void
    {
        $signer = $this->transport(fn (
            OrganizationId $org, string $id, int $b, int $s, int $m, string $nonce,
        ): string => $this->sign($this->payload($org, $id, $nonce)));
        self::assertNull($this->inspect($this->probe($signer, sodium_crypto_sign_publickey(
            sodium_crypto_sign_keypair(),
        ))));
        foreach ([
            ['coveredBackupSequence' => 4],
            ['sessionFloor' => 5],
            ['membershipFloor' => 5],
            ['cluster' => self::PRIMARY],
        ] as $mutation) {
            $transport = $this->transport(fn (
                OrganizationId $org, string $id, int $b, int $s, int $m, string $nonce,
            ): string => $this->sign(array_replace($this->payload($org, $id, $nonce), $mutation)));
            self::assertNull($this->inspect($this->probe($transport)));
        }
    }

    public function test_malformed_receipts_and_transport_exceptions_never_expose_or_unlock(): void
    {
        foreach (['', 'null', '{}', '{"payload":[]}', str_repeat('x', 4097)] as $raw) {
            $transport = $this->transport(static fn (): string => $raw);
            self::assertNull($this->inspect($this->probe($transport)));
        }
        $throwing = $this->transport(static function (): string {
            throw new RuntimeException('sensitive remote transport diagnostic');
        });
        self::assertNull($this->inspect($this->probe($throwing)));
        self::assertNull($this->inspect($this->probe($throwing, 'bad-key')));

        $valid = $this->transport(fn (
            OrganizationId $org, string $id, int $b, int $s, int $m, string $nonce,
        ): string => $this->sign($this->payload($org, $id, $nonce)));
        foreach ([
            [0,6,6], [1000000000,6,6], [4,0,6],
            [4,1000000001,6], [4,6,0], [4,6,1000000001],
        ] as [$backup, $session, $membership]) {
            self::assertNull($this->probe($valid)->inspect(
                $this->tenant(), self::EVENT, self::PRIMARY, $backup, $session, $membership
            ));
        }
        self::assertNull($this->probe($valid)->inspect(
            $this->tenant(), self::EVENT, 'bad', 4, 6, 6
        ));
    }

    public function test_malformed_schema_or_unsigned_field_change_is_denied(): void
    {
        foreach ([
            ['eventId' => 'bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbbb'],
            ['purpose' => 'imagine-portal/login-ack/v1'],
            ['version' => 2],
            ['sequence' => '5'],
            ['sequence' => 0],
            ['membershipFloor' => -1],
            ['cluster' => '../witness-key'],
        ] as $mutation) {
            $transport = $this->transport(fn (
                OrganizationId $org, string $id, int $b, int $s, int $m, string $nonce,
            ): string => $this->sign(array_replace($this->payload($org, $id, $nonce), $mutation)));
            self::assertNull($this->inspect($this->probe($transport)));
        }
        $transport = $this->transport(function (
            OrganizationId $org, string $id, int $b, int $s, int $m, string $nonce,
        ): string {
            $json = json_decode($this->sign($this->payload($org, $id, $nonce)), true);
            $json['payload']['membershipFloor'] = 888; // no new signature
            return json_encode($json, JSON_THROW_ON_ERROR);
        });
        self::assertNull($this->inspect($this->probe($transport)));
    }
}
