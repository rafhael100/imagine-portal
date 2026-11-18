<?php
declare(strict_types=1);
namespace App\Domain\Commerce\D0;
use App\Domain\SitesIntegration\PortalSettlementEvidence;
final class UnavailableEntryStore implements EntryStore
{
 public function inspect(string $session,string $order):?array{return null;}
 public function admit(string $session,string $order,array $expected,PortalSettlementEvidence $proof,string $observedAt):string{return 'unavailable';}
}
