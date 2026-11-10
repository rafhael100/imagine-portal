<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Domain\SitesIntegration\CandidatePortalSitesSigningMaterial;
use App\Domain\SitesIntegration\LabPortalSitesVersionedIssuer;
use App\Domain\SitesIntegration\PortalSiteContractSource;
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
            public function findAuthorizedSiteOrder(string $orderRef): ?SiteContractSnapshot
            {
                if ($orderRef!=='order-f113-portal')return null;
                return new SiteContractSnapshot(
                    'tenant-f113-portal','order-f113-portal',
                    'contract-f113-portal',2,'onepage'
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
        return [new LabPortalSitesVersionedIssuer(
            new SitesCreateProjectEncoder($orders),$material
        ),$material];
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
