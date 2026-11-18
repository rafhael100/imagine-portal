<?php
declare(strict_types=1);
namespace App\Infrastructure\Commerce;
use App\Domain\Commerce\D0\EntryStore;
use App\Domain\SitesIntegration\{AsaasOfficialPaymentReader,AsaasReadOnlyPaymentClient};
use Closure;use RuntimeException;use Throwable;
/** Explicit per-attempt server-side composition. No provider registration, credential discovery or activation. */
final readonly class ConfiguredSandboxD0Reader implements AsaasOfficialPaymentReader
{
 private const KEYS=['account','configuration','environment','generation'];
 private function __construct(private EntryStore $store,private string $session,private string $order,
  private array $configuration,private Closure $credential,private ?array $expected){}
 /** Configuration and credential lease MUST be supplied by the authorized server operator, never browser input.
  * Lease is exactly {account,configuration,environment,generation,token}. Matching it is NOT proof of custody.
  */
 public static function forAttempt(EntryStore $store,string $session,string $order,array $configuration,Closure $credential,bool $enabled=false):self {
  $expected=null;
  try{
   $keys=array_keys($configuration);sort($keys);
   if($enabled && $keys===self::KEYS && $configuration['environment']==='sandbox'
    && is_string($configuration['account']) && preg_match('/\A[A-Za-z0-9][A-Za-z0-9._:-]{0,95}\z/D',$configuration['account'])===1
    && is_string($configuration['configuration']) && preg_match('/\Acfg_[A-Za-z0-9_-]{8,96}\z/D',$configuration['configuration'])===1
    && is_string($configuration['generation']) && preg_match('/\A[1-9][0-9]{0,18}\z/D',$configuration['generation'])===1){
    $s=$store->inspect($session,$order);
    if(is_array($s) && ($s['state']??null)==='ready' && ($s['account']??null)===$configuration['account']
     && ($s['environment']??null)==='sandbox'
     && ($s['account_authorization']['configuration']??null)===$configuration['configuration']
     && ($s['account_authorization']['generation']??null)===$configuration['generation'])$expected=$s;
   }
  }catch(Throwable){}
  return new self($store,$session,$order,$configuration,$credential,$expected);
 }
 private function current():bool{return $this->expected!==null && $this->store->inspect($this->session,$this->order)===$this->expected;}
 public function fetchPayment(string $paymentId):?array {
  try{
   if($this->expected===null || ($this->expected['binding']['payment_id']??null)!==$paymentId || !$this->current())return null;
   $lease=($this->credential)($this->configuration);
   if(!is_array($lease))return null;$keys=array_keys($lease);sort($keys);
   if($keys!==[...self::KEYS,'token'])return null;
   foreach(self::KEYS as $key)if($lease[$key]!==$this->configuration[$key])return null;
   if(!is_string($lease['token']) || !$this->current())return null;
   // Fixed canonical Sandbox reader. Never expose token/client; no production switch, URL or transport injection.
   $reader=new AsaasReadOnlyPaymentClient('sandbox',$lease['token']);unset($lease);
   $response=$reader->fetchPayment($paymentId);unset($reader);
   return $this->current()?$response:null;
  }catch(Throwable){return null;}
 }
 public function __debugInfo():array{return ['reader'=>'Configured Sandbox D0; custody unverified'];}
 public function __serialize():array{throw new RuntimeException('D0_READER_UNAVAILABLE');}
}
