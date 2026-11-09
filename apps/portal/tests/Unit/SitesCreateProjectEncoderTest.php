<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Domain\SitesIntegration\PortalSiteContractSource;
use App\Domain\SitesIntegration\SiteContractSnapshot;
use App\Domain\SitesIntegration\SitesCreateProjectEncoder;
use DomainException;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class SitesCreateProjectEncoderTest extends TestCase
{
    private function mockedSource(?SiteContractSnapshot $order): PortalSiteContractSource
    {
        return new class($order) implements PortalSiteContractSource {
            public function __construct(private readonly ?SiteContractSnapshot $order) {}
            public function findAuthorizedSiteOrder(string $orderRef): ?SiteContractSnapshot
            {
                return $this->order;
            }
        };
    }

    public function test_default_source_denies_without_verified_commercial_authority(): void
    {
        $this->expectException(DomainException::class);
        (new SitesCreateProjectEncoder())->build('order-demo-001','op-demo-001','idem-demo-001');
    }

    public function test_synthetic_payload_matches_sites_v1_fixture_exactly(): void
    {
        $snapshot = new SiteContractSnapshot('tenant-demo-001','order-demo-001','contract-demo-001',1,'onepage');
        $actual = (new SitesCreateProjectEncoder($this->mockedSource($snapshot)))->build(
            'order-demo-001','op-demo-001','idem-demo-001'
        );
        $expected = json_decode(file_get_contents(__DIR__.'/../Fixtures/sites-create-project-v1.synthetic.json'),true,512,JSON_THROW_ON_ERROR);
        self::assertSame($expected, $actual);
    }

    public function test_stale_or_mismatched_order_from_source_is_rejected(): void
    {
        $snapshot = new SiteContractSnapshot('tenant-demo-001','other-order','contract-demo-001',1,'onepage');
        $this->expectException(DomainException::class);
        (new SitesCreateProjectEncoder($this->mockedSource($snapshot)))->build(
            'order-demo-001','op-demo-001','idem-demo-001'
        );
    }

    public function test_invalid_id_and_product_are_rejected_even_in_mock(): void
    {
        $snapshot = new SiteContractSnapshot('tenant-demo-001','order-demo-001','contract-demo-001',1,'onepage');
        $encoder = new SitesCreateProjectEncoder($this->mockedSource($snapshot));
        try {
            $encoder->build('order-demo-001','../evil','idem-demo-001');
            self::fail('Invalid operation should be blocked.');
        } catch (InvalidArgumentException) {
            self::assertTrue(true);
        }
        $this->expectException(InvalidArgumentException::class);
        new SiteContractSnapshot('tenant-demo-001','order-demo-001','contract-demo-001',1,'unknown');
    }
}
