<?php
declare(strict_types=1);
namespace App\Domain\Commerce\D0;
use App\Domain\SitesIntegration\PortalSettlementEvidence;
interface EntryStore
{
    /** Trusted native authorization + expected binding. Never browser data. */
    public function inspect(string $session, string $order): ?array;
    public function admit(string $session, string $order, array $expected, PortalSettlementEvidence $proof, string $observedAt): string;
}
