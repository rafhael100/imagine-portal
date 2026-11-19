<?php
declare(strict_types=1);
namespace RebuiltOnepageWorker;
use PrivateD0Consumption\{Consumer,Target};
use PrivateD0Lab\{FinancialPort,SyntheticPaymentReader};
use PDO;
use Closure;
/** Trusted bootstrap must own these objects; never deserialize them from webhook/config payload. */
final readonly class Worker {
 public function __construct(private PDO $inbox,private Closure $gate,private Target $target,
  private FinancialPort $financial,private SyntheticPaymentReader $reader,private ServiceAuthorityPort $entry){}
 public function once():array {
  $status=(new Consumer($this->inbox,$this->gate,$this->target,$this->financial,$this->reader))->run();
  if(!in_array($status,['completed','not_claimable'],true))return ['financial'=>$status,'entry'=>'not_attempted'];
  // not_claimable is not evidence of ACK; SQL demands persisted matching observation.
  // A fresh read is mandatory; injected reader MUST NOT cache prior PSP responses.
  $entry=$this->entry->run($this->target->nativeAccount,$this->target->payment,$this->reader);
  return ['financial'=>$status,'entry'=>$entry];
 }
}
