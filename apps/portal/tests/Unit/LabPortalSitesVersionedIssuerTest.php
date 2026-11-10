<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Domain\SitesIntegration\CandidatePortalSitesSigningMaterial;
use App\Domain\SitesIntegration\LabPortalSitesVersionedIssuer;
use App\Domain\SitesIntegration\PortalSiteContractSource;
use App\Domain\SitesIntegration\PortalSiteCommercialEpochSource;
use App\Domain\SitesIntegration\PortalSitesSigningMaterialSource;
use App\Domain\SitesIntegration\SiteContractSnapshot;
use App\Domain\SitesIntegration\SitesCreateProjectEncoder;
use App\Domain\SitesIntegration\SyntheticSitesVersionedEnvelopeSigner;
use DomainException;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class LabPortalSitesVersionedIssuerTest extends TestCase
{
    private const KEY='DO-NOT-USE-F1-13-PORTAL-SYNTHETIC-TEST-KEY-123456789';

    private function candidate(?CandidatePortalSitesSigningMaterial $initial=null): array
    {
        $orders=new class implements PortalSiteContractSource {
            public int $calls=0;
            public bool $eligible=true;
            public int $contractRevision=2;
            public string $tenantRef='tenant-f113-portal';

            public function findAuthorizedSiteOrder(string $orderRef): ?SiteContractSnapshot
            {
                ++$this->calls;
                if (!$this->eligible || $orderRef!=='order-f113-portal')return null;
                return new SiteContractSnapshot(
                    $this->tenantRef,'order-f113-portal',
                    'contract-f113-portal',$this->contractRevision,'onepage'
                );
            }
        };
        $material=new class($initial) implements PortalSitesSigningMaterialSource {
            public int $calls=0;
            public bool $fail=false;
            public ?\Closure $onRead=null;

            public function __construct(
                public ?CandidatePortalSitesSigningMaterial $value
            ) {}
            public function currentFor(string $issuer,string $audience):?CandidatePortalSitesSigningMaterial
            {
                ++$this->calls;
                if ($this->onRead !== null) ($this->onRead)();
                if ($this->fail)throw new RuntimeException('F1-13 synthetic source down');
                return $issuer==='imagine-portal' && $audience==='imagine-sites'
                    ? $this->value : null;
            }
        };
        $epochs=new class implements PortalSiteCommercialEpochSource {
            public ?int $epoch=5;
            public bool $offline=false;
            public int $reads=0;
            public function currentEpochFor(string $orderRef):?int
            {
                ++$this->reads;
                if ($this->offline)throw new RuntimeException('Synthetic commercial epoch source offline');
                return $orderRef==='order-f113-portal' ? $this->epoch : null;
            }
        };
        return [new LabPortalSitesVersionedIssuer(
            new SitesCreateProjectEncoder($orders),$material,$epochs
        ),$material,$orders,$epochs];
    }

    public function test_default_signer_and_commercial_authority_both_deny():void
    {
        $noOrder=new LabPortalSitesVersionedIssuer();
        try {
            $noOrder->issue(
                'order-f113-portal','operation-f113','idempotency-f113',
                'nonce-f113-0001',1790000000,1790000060
            );
            self::fail('A default instance cannot authorize a project.');
        } catch (DomainException) {
            self::assertTrue(true);
        }

        [$issuer,$source]=$this->candidate();
        $this->expectException(DomainException::class);
        $issuer->issue(
            'order-f113-portal','operation-f113','idempotency-f113',
            'nonce-f113-0001',1790000000,1790000060
        );
    }

    public function test_trusted_fixture_material_yields_same_v2_bytes_as_independent_signer():void
    {
        [$issuer,$source]=$this->candidate(new CandidatePortalSitesSigningMaterial(
            'imagine-portal','imagine-sites',self::KEY,7
        ));
        $result=$issuer->issue(
            'order-f113-portal','operation-f113','idempotency-f113',
            'nonce-f113-0002',1790000000,1790000060
        );
        $expected=SyntheticSitesVersionedEnvelopeSigner::sign(
            [
                'schemaVersion'=>'1',
                'operationId'=>'operation-f113',
                'tenantRef'=>'tenant-f113-portal',
                'orderRef'=>'order-f113-portal',
                'contractRef'=>'contract-f113-portal',
                'contractRevision'=>2,
                'productCode'=>'onepage',
                'idempotencyKey'=>'idempotency-f113',
            ],self::KEY,'nonce-f113-0002',1790000000,1790000060,7
        );
        self::assertSame($expected,$result);
        self::assertSame(2,$source->calls);
        self::assertSame(7,$result['keyEpoch']);
        self::assertArrayNotHasKey('secret',$result);
        self::assertStringNotContainsString(self::KEY,(string)json_encode($result));
    }

    public function test_revocation_or_error_fail_closed_without_issuing():void
    {
        [$issuer,$source]=$this->candidate(new CandidatePortalSitesSigningMaterial(
            'imagine-portal','imagine-sites',self::KEY,7
        ));
        $source->value=null;
        try {
            $issuer->issue('order-f113-portal','operation-f113','idempotency-f113',
                'nonce-f113-0003',1790000000,1790000060);
            self::fail('Null signing authority must deny.');
        } catch (DomainException) {
            self::assertTrue(true);
        }
        $source->fail=true;
        $this->expectException(DomainException::class);
        $issuer->issue('order-f113-portal','operation-f113','idempotency-f113',
            'nonce-f113-0004',1790000000,1790000060);
    }

    public function test_aba_generation_change_between_two_reads_denies_even_when_key_matches():void
    {
        [$issuer,$source]=$this->candidate(new CandidatePortalSitesSigningMaterial(
            'imagine-portal','imagine-sites',self::KEY,7
        ));
        $source->onRead=static function() use($source):void {
            if ($source->calls===2) {
                $source->value=new CandidatePortalSitesSigningMaterial(
                    'imagine-portal','imagine-sites',self::KEY,8
                );
            }
        };
        $this->expectException(DomainException::class);
        $issuer->issue('order-f113-portal','operation-f113','idempotency-f113',
            'nonce-f113-0005',1790000000,1790000060);
    }

    public function test_key_change_without_epoch_increment_is_also_denied():void
    {
        [$issuer,$source]=$this->candidate(new CandidatePortalSitesSigningMaterial(
            'imagine-portal','imagine-sites',self::KEY,7
        ));
        $source->onRead=static function() use($source):void {
            if ($source->calls===2) {
                $source->value=new CandidatePortalSitesSigningMaterial(
                    'imagine-portal','imagine-sites',
                    'DO-NOT-USE-F1-13-ROTATED-SYNTHETIC-KEY-123456789',7
                );
            }
        };
        $this->expectException(DomainException::class);
        $issuer->issue('order-f113-portal','operation-f113','idempotency-f113',
            'nonce-f113-0006',1790000000,1790000060);
    }

    public function test_commercial_revocation_during_signing_denies_final_envelope():void
    {
        [$issuer,$keySource,$orderSource]=$this->candidate(
            new CandidatePortalSitesSigningMaterial(
                'imagine-portal','imagine-sites',self::KEY,7
            )
        );
        $keySource->onRead=static function() use($keySource,$orderSource):void {
            if ($keySource->calls===2) $orderSource->eligible=false;
        };
        try {
            $issuer->issue('order-f113-portal','operation-f113','idempotency-f113',
                'nonce-f114-revoked-before-return',1790000000,1790000060);
            self::fail('Revoked contract must not yield an envelope.');
        } catch (DomainException) {
            self::assertSame(2,$keySource->calls);
            self::assertSame(2,$orderSource->calls);
        }
        // A later independently eligible snapshot may issue a fresh
        // message; the previously denied call never returned a token.
        $keySource->onRead=null;
        $orderSource->eligible=true;
        $valid=$issuer->issue('order-f113-portal','operation-f113','idempotency-f113',
            'nonce-f114-fresh-after-restore',1790000000,1790000060);
        self::assertSame('2',$valid['schemaVersion']);
        self::assertSame(7,$valid['keyEpoch']);
    }

    public function test_commercial_revision_change_during_signing_denies_stale_payload():void
    {
        [$issuer,$keySource,$orderSource]=$this->candidate(
            new CandidatePortalSitesSigningMaterial(
                'imagine-portal','imagine-sites',self::KEY,7
            )
        );
        $keySource->onRead=static function() use($keySource,$orderSource):void {
            if ($keySource->calls===2) ++$orderSource->contractRevision;
        };
        $this->expectException(DomainException::class);
        $issuer->issue('order-f113-portal','operation-f113','idempotency-f113',
            'nonce-f114-stale-revision',1790000000,1790000060);
    }

    public function test_commercial_tenant_change_during_signing_denies_cross_tenant_payload():void
    {
        [$issuer,$keySource,$orderSource]=$this->candidate(
            new CandidatePortalSitesSigningMaterial(
                'imagine-portal','imagine-sites',self::KEY,7
            )
        );
        $keySource->onRead=static function() use($keySource,$orderSource):void {
            if ($keySource->calls===2) $orderSource->tenantRef='tenant-f114-other';
        };
        $this->expectException(DomainException::class);
        $issuer->issue('order-f113-portal','operation-f113','idempotency-f113',
            'nonce-f114-tenant-changed',1790000000,1790000060);
    }

    public function test_commercial_aba_revocation_and_restoration_with_identical_payload_is_denied():void
    {
        [$issuer,$keys,$orders,$epochs]=$this->candidate(
            new CandidatePortalSitesSigningMaterial('imagine-portal','imagine-sites',self::KEY,7)
        );
        $keys->onRead=static function() use($keys,$orders,$epochs):void {
            if ($keys->calls===2) {
                $orders->eligible=false;
                $orders->eligible=true;
                ++$epochs->epoch;
            }
        };
        try {
            $issuer->issue('order-f113-portal','operation-f115','idempotency-f115',
                'nonce-f115-commercial-aba-0001',1790000000,1790000060);
            self::fail('Same contract bytes with changed grant generation must be denied.');
        } catch (DomainException) {
            self::assertSame(2,$orders->calls);
            self::assertSame(2,$epochs->reads);
            self::assertSame(2,$keys->calls);
            self::assertTrue($orders->eligible);
        }
        $keys->onRead=null;
        $result=$issuer->issue('order-f113-portal','operation-f115','idempotency-f115',
            'nonce-f115-commercial-aba-retry-0002',1790000000,1790000060);
        self::assertSame('2',$result['schemaVersion']);
    }

    public function test_missing_and_unavailable_commercial_epoch_denies_before_key_lookup():void
    {
        [$issuer,$keys,$orders,$epochs]=$this->candidate(
            new CandidatePortalSitesSigningMaterial('imagine-portal','imagine-sites',self::KEY,7)
        );
        $epochs->epoch=null;
        try {
            $issuer->issue('order-f113-portal','operation-f115','idempotency-f115',
                'nonce-f115-no-epoch-0003',1790000000,1790000060);
            self::fail('Missing independent commercial epoch must deny.');
        } catch (DomainException) {
            self::assertSame(0,$keys->calls);
        }
        $epochs->offline=true;
        $this->expectException(DomainException::class);
        $issuer->issue('order-f113-portal','operation-f115','idempotency-f115',
            'nonce-f115-offline-epoch-0004',1790000000,1790000060);
    }

    public function test_unknown_order_is_denied_before_reading_any_key():void
    {
        [$issuer,$source]=$this->candidate(new CandidatePortalSitesSigningMaterial(
            'imagine-portal','imagine-sites',self::KEY,7
        ));
        try {
            $issuer->issue('order-not-authorized','operation-f113','idempotency-f113',
                'nonce-f113-0007',1790000000,1790000060);
            self::fail('Unrecognized order must never sign.');
        } catch (DomainException) {
            self::assertSame(0,$source->calls);
        }
    }
}
