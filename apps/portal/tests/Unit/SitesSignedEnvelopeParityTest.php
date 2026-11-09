<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Domain\SitesIntegration\PortalSiteContractSource;
use App\Domain\SitesIntegration\SiteContractSnapshot;
use App\Domain\SitesIntegration\SitesCreateProjectEncoder;
use App\Domain\SitesIntegration\SyntheticSitesEnvelopeSigner;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class SitesSignedEnvelopeParityTest extends TestCase
{
    private const FAKE_KEY = 'DO-NOT-USE-SYNTHETIC-TEST-KEY-IN-PRODUCTION-123456789';

    private function fictionalAuthorizedPayload(): array
    {
        $source = new class implements PortalSiteContractSource {
            public function findAuthorizedSiteOrder(string $orderRef): ?SiteContractSnapshot
            {
                return new SiteContractSnapshot('tenant-demo-001','order-demo-001','contract-demo-001',1,'onepage');
            }
        };
        return (new SitesCreateProjectEncoder($source))->build('order-demo-001','op-demo-001','idem-demo-001');
    }

    public function test_portal_emits_byte_compatible_envelope_for_sites_v1_fixture(): void
    {
        $actual = SyntheticSitesEnvelopeSigner::sign($this->fictionalAuthorizedPayload(),
            self::FAKE_KEY,'nonce-portal-contract-01',1790000000,1790000060);
        $expected = json_decode(file_get_contents(
            __DIR__.'/../Fixtures/sites-create-project-v1.signed.synthetic.json'
        ), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame($expected, $actual);
        self::assertSame('26f8178091c1033d2f36c76f43ec8c77d46bfed4da41ee4e08a567b09b9fe706', $actual['mac']);
        self::assertStringNotContainsString(self::FAKE_KEY, json_encode($actual, JSON_THROW_ON_ERROR));
    }

    public function test_field_order_mutation_is_refused_before_signing(): void
    {
        $payload = $this->fictionalAuthorizedPayload();
        $payload['adminOverride'] = true;
        $this->expectException(InvalidArgumentException::class);
        SyntheticSitesEnvelopeSigner::sign($payload,self::FAKE_KEY,'nonce-portal-contract-01',1790000000,1790000060);
    }

    public function test_cross_tenant_reference_traversal_is_rejected(): void
    {
        $payload=$this->fictionalAuthorizedPayload();
        $payload['tenantRef']='../../other-account';
        $this->expectException(InvalidArgumentException::class);
        SyntheticSitesEnvelopeSigner::sign($payload,self::FAKE_KEY,'nonce-portal-contract-01',1790000000,1790000060);
    }

    public function test_excessive_ttl_is_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        SyntheticSitesEnvelopeSigner::sign($this->fictionalAuthorizedPayload(),
            self::FAKE_KEY,'nonce-portal-contract-01',1790000000,1790000121);
    }

    public function test_weak_key_is_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        SyntheticSitesEnvelopeSigner::sign($this->fictionalAuthorizedPayload(),
            'short','nonce-portal-contract-01',1790000000,1790000060);
    }
}
