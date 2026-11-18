<?php
declare(strict_types=1);
namespace Tests\Integration;

use App\Domain\Commerce\OnepageAcceptedOrder;
use App\Infrastructure\Commerce\PostgresOnepageOrders;
use PDO;

/** Runs the original native order/PSP-ledger chain plus the pinned REAL Sites receiver. */
final class NativeOnepageSitesPairPgTest extends NativeOnepageOrdersPgTest
{
    protected function afterAcceptedOrder(PDO $bootstrap,PostgresOnepageOrders $orders,OnepageAcceptedOrder $order,string $reference):void
    {
        NativeOnepageSitesPairAssertions::run($bootstrap,$orders,$order,$reference);
    }
}
