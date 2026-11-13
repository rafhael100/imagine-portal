<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Domain\Organizations\OrganizationId;
use App\Domain\RestoreSafety\DenyingPortalRevocationPublicationTransport;
use App\Domain\RestoreSafety\LabExternalWitnessFloor;
use App\Domain\RestoreSafety\LabExternalWitnessReader;
use App\Domain\RestoreSafety\PortalRevocationPublicationProbe;
use App\Domain\RestoreSafety\PortalRevocationPublicationTransport;
use App\Domain\RestoreSafety\PortalRevocationReadbackGate;
use App\Domain\RestoreSafety\PortalRevocationReceiptVerifier;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Tests\Support\DisposableSignedWitnessReader;
use Tests\Support\DisposableWitnessAttestationSigner;

final class PortalRevocationReadbackGateTest extends TestCase
{
    private const EVENT = 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa';

    private OrganizationId $tenant;
    private string $private;
    private string $public;
    private DisposableWitnessAttestationSigner $signer;
    private int $publishCalls;
    private int $readCalls;
    private array $nonces;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tenant = new OrganizationId('demo-org-a');
        $pair = sodium_crypto_sign_keypair();
        $this->private = sodium_crypto_sign_secretkey($pair);
        $this->public = sodium_crypto_sign_publickey($pair);
        $this->signer = new DisposableWitnessAttestationSigner(
            $this->private, $this->floor(),
        );
        $this->publishCalls = 0;
        $this->readCalls = 0;
        $this->nonces = [];
    }

    private function floor(int $seq=2, int $backup=5, int $session=2, int $member=2): LabExternalWitnessFloor
    {
        return new LabExternalWitnessFloor(
            $this->tenant, 'demo-independent-witness', $seq, $backup, $session, $member,
        );
    }

    private function acknowledge(
        OrganizationId $tenant, string $event, string $nonce,
        array $replacement=[],
    ): string {
        $floor = $this->floor();
        $p = array_replace([
            'version'=>1, 'purpose'=>'imagine-portal/revocation-ack/v1',
            'challenge'=>$nonce, 'eventId'=>$event,
            'tenant'=>$tenant->value, 'cluster'=>$floor->witnessClusterId,
            'sequence'=>$floor->sequence,
            'coveredBackupSequence'=>$floor->coveredBackupSequence,
            'sessionFloor'=>$floor->sessionFloor,
            'membershipFloor'=>$floor->membershipFloor,
        ],$replacement);
        $bytes = json_encode($p, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        return json_encode([
            'payload'=>$p,
            'signature'=>base64_encode(sodium_crypto_sign_detached($bytes,$this->private)),
        ],JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
    }

    /** @param array<string,mixed> $mutation */
    private function publisher(array $mutation=[]): PortalRevocationPublicationProbe
    {
        $transport = new class($this, $mutation) implements PortalRevocationPublicationTransport {
            public function __construct(private PortalRevocationReadbackGateTest $test,
                                         private array $mutation) {}
            public function publishForReview(
                OrganizationId $tenant, string $eventId, int $primaryBackupSequence,
                int $minimumSessionFloor, int $minimumMembershipFloor,
                string $challenge,
            ): ?string {
                return $this->test->recordPublication(
                    $tenant, $eventId, $challenge, $this->mutation,
                );
            }
        };
        return new PortalRevocationPublicationProbe(
            $transport, new PortalRevocationReceiptVerifier(), $this->public,
        );
    }

    /** @param array<string,mixed> $mutation */
    public function recordPublication(
        OrganizationId $tenant,string $event,string $nonce,array $mutation
    ): string {
        $this->publishCalls++;
        $this->nonces[] = $nonce;
        return $this->acknowledge($tenant,$event,$nonce,$mutation);
    }

    private function reader(): LabExternalWitnessReader
    {
        return new DisposableSignedWitnessReader(
            $this->public,
            function (OrganizationId $tenant, string $nonce): ?string {
                $this->readCalls++;
                $this->nonces[]=$nonce;
                return $this->signer->respond($tenant,$nonce);
            }
        );
    }

    private function inspect(
        ?PortalRevocationPublicationProbe $publication,
        ?LabExternalWitnessReader $reader,
    ): \App\Domain\RestoreSafety\PortalRevocationReadbackVerdict {
        return (new PortalRevocationReadbackGate())->inspect(
            $this->tenant, self::EVENT, 'demo-primary-z1', 4, 2, 2,
            $publication, $reader,
        );
    }

    public function test_one_published_event_and_two_fresh_consistent_readbacks_still_never_release(): void
    {
        $verdict = $this->inspect($this->publisher(),$this->reader());
        self::assertTrue($verdict->syntheticChecksPassed);
        self::assertSame('synthetic_publication_readback_only',$verdict->reason);
        self::assertSame(1,$this->publishCalls);
        self::assertSame(2,$this->readCalls);
        self::assertCount(3,$this->nonces);
        self::assertCount(3,array_unique($this->nonces));
        foreach ($this->nonces as $nonce) {
            self::assertMatchesRegularExpression('/^[a-f0-9]{64}$/D',$nonce);
        }
        self::assertFalse($verdict->canConfirmDurableRevocation());
        self::assertFalse($verdict->canAuthorizeHttp());
        self::assertFalse($verdict->canReleaseServing());
        self::assertFalse($verdict->canReissueSessions());
    }

    public function test_production_default_deny_and_missing_witness_never_pass(): void
    {
        $deny = new PortalRevocationPublicationProbe(
            new DenyingPortalRevocationPublicationTransport(),
            new PortalRevocationReceiptVerifier(),
            $this->public,
        );
        $verdict=$this->inspect($deny,$this->reader());
        self::assertFalse($verdict->syntheticChecksPassed);
        self::assertSame('publication_claim_unavailable',$verdict->reason);
        self::assertSame(0,$this->readCalls);
        self::assertSame('missing_witness_dependency',
            $this->inspect(null,$this->reader())->reason);
        self::assertSame('missing_witness_dependency',
            $this->inspect($this->publisher(),null)->reason);
    }

    public function test_unavailable_first_or_second_readback_fails_closed(): void
    {
        $unavailable = new class implements LabExternalWitnessReader {
            public function observe(OrganizationId $org): ?LabExternalWitnessFloor
            {
                return null;
            }
        };
        self::assertSame('witness_readback_unavailable',
            $this->inspect($this->publisher(),$unavailable)->reason);

        $firstThenDown = new class($this->floor()) implements LabExternalWitnessReader {
            private int $reads=0;
            public function __construct(private LabExternalWitnessFloor $floor) {}
            public function observe(OrganizationId $org): ?LabExternalWitnessFloor
            {
                return ++$this->reads===1 ? $this->floor : null;
            }
        };
        self::assertSame('witness_readback_unavailable',
            $this->inspect($this->publisher(),$firstThenDown)->reason);
        $throwing = new class implements LabExternalWitnessReader {
            public function observe(OrganizationId $org): ?LabExternalWitnessFloor
            {
                throw new RuntimeException('secret remote debug data');
            }
        };
        self::assertSame('readback_unavailable',
            $this->inspect($this->publisher(),$throwing)->reason);
    }

    public function test_readback_change_after_first_observation_is_rejected(): void
    {
        $changed = new class($this->floor(),$this->floor(3,6,3,3))
            implements LabExternalWitnessReader {
            private int $reads=0;
            public function __construct(
                private LabExternalWitnessFloor $before,
                private LabExternalWitnessFloor $after,
            ) {}
            public function observe(OrganizationId $org): ?LabExternalWitnessFloor
            {
                return ++$this->reads===1 ? $this->before : $this->after;
            }
        };
        $verdict=$this->inspect($this->publisher(),$changed);
        self::assertSame('readback_changed_during_check',$verdict->reason);
        self::assertFalse($verdict->canReleaseServing());
    }

    public function test_signed_readback_below_publication_claim_is_rejected(): void
    {
        foreach ([
            ['sequence'=>3],
            ['coveredBackupSequence'=>6],
            ['sessionFloor'=>3],
            ['membershipFloor'=>3],
            ['cluster'=>'foreign-witness'],
        ] as $mutation) {
            $verdict=$this->inspect($this->publisher($mutation),$this->reader());
            self::assertSame('readback_not_at_published_floor',$verdict->reason);
            self::assertFalse($verdict->syntheticChecksPassed);
        }
    }

    public function test_foreign_tenant_and_same_primary_cluster_never_pass_readback(): void
    {
        $foreign = new class(new LabExternalWitnessFloor(
            new OrganizationId('demo-org-b'),'demo-independent-witness',2,5,2,2,
        )) implements LabExternalWitnessReader {
            public function __construct(private LabExternalWitnessFloor $floor) {}
            public function observe(OrganizationId $org): ?LabExternalWitnessFloor
            {
                return $this->floor;
            }
        };
        self::assertSame('readback_changed_during_check',
            $this->inspect($this->publisher(),$foreign)->reason);

        $primary = new class(new LabExternalWitnessFloor(
            $this->tenant,'demo-primary-z1',2,5,2,2,
        )) implements LabExternalWitnessReader {
            public function __construct(private LabExternalWitnessFloor $floor) {}
            public function observe(OrganizationId $org): ?LabExternalWitnessFloor
            {
                return $this->floor;
            }
        };
        self::assertSame('readback_not_at_published_floor',
            $this->inspect($this->publisher(),$primary)->reason);
    }

    public function test_invalid_publication_nonce_or_wrong_event_stops_before_reads(): void
    {
        $verdict=$this->inspect(
            $this->publisher(['eventId'=>'bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbbb']),
            $this->reader(),
        );
        self::assertSame('publication_claim_unavailable',$verdict->reason);
        self::assertSame(0,$this->readCalls);
        self::assertFalse($verdict->canConfirmDurableRevocation());
    }
}
