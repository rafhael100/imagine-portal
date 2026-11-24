<?php
declare(strict_types=1);
namespace Tests\Integration;

use App\Domain\Commerce\OnepageAcceptedOrder;
use App\Infrastructure\Commerce\PostgresOnepageOrders;
use PDO;

/** Runs the original native order/PSP-ledger chain plus the pinned REAL Sites receiver. */
final class NativeOnepageSitesPairPgTest extends NativeOnepageOrdersPgTest
{
    private ?string $admittedSiteRef = null;

    protected function afterAcceptedOrder(PDO $bootstrap,PostgresOnepageOrders $orders,OnepageAcceptedOrder $order,string $reference):void
    {
        $this->admittedSiteRef = NativeOnepageSitesPairAssertions::run($bootstrap,$orders,$order,$reference);
    }

    protected function afterAcceptedOrderAccessCases(PostgresOnepageOrders $orders, OnepageAcceptedOrder $order, string $reference, array $deniedReferences): void
    {
        self::assertNotNull($this->admittedSiteRef);
        NativeOnepageSitesPairAssertions::assertBriefBoundaryDenied($orders,$order,$reference,$this->admittedSiteRef,true);
        foreach ($deniedReferences as $deniedReference) {
            NativeOnepageSitesPairAssertions::assertBriefBoundaryDenied($orders,$order,$deniedReference,$this->admittedSiteRef,false);
        }
    }

    protected function afterRevokedOrder(PostgresOnepageOrders $orders, OnepageAcceptedOrder $order, string $reference): void
    {
        self::assertNotNull($this->admittedSiteRef);
        NativeOnepageSitesPairAssertions::assertBriefBoundaryDenied($orders,$order,$reference,$this->admittedSiteRef,false);
    }
}
