<?php
declare(strict_types=1);
namespace App\Domain\Commerce;

interface OnepageOrderReader
{
    /** Revalidate the native session, buyer, company and publication on every read. */
    public function findOwnedAcceptedOrder(string $businessReference, string $orderId): ?OnepageAcceptedOrder;
}
