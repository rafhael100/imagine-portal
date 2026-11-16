<?php

declare(strict_types=1);
namespace App\Domain\AutomationAdministration;
use Closure;
use RuntimeException;

/** Read adapter contract. Source must authenticate its own service identity; no browser credential is accepted here. */
final readonly class AutomationStateReader
{
 public function __construct(private Closure $source,private Closure $authorize) {}
 public function read(int $admin,string $company): array {
  if($admin<1 || preg_match('/\A[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}\z/D',$company)!==1
    || ($this->authorize)($admin,$company)!==true)throw new RuntimeException('AUTOMATION_READ_DENIED');
  $value=($this->source)($company);$now=time();
  if(!is_array($value) || strlen(json_encode($value,JSON_THROW_ON_ERROR))>8192)throw new RuntimeException('AUTOMATION_READ_INVALID');
  $keys=array_keys($value);sort($keys);
  if($keys!==['ai_provider_calls','company_id','counts','events','observed_at','operational_authorization','schema','settings_version','source_kind']
   || $value['schema']!=='CRM_AUTOMATION_STATE_V1' || $value['source_kind']!=='synthetic_private_database'
   || $value['company_id']!==$company || !is_int($value['observed_at']) || $value['observed_at']>$now+5 || $value['observed_at']<$now-60
   || !is_int($value['settings_version']) || $value['settings_version']<0 || $value['settings_version']>20
   || $value['ai_provider_calls']!==0 || $value['operational_authorization']!==false
   || !is_array($value['counts']) || !is_array($value['events']) || !array_is_list($value['events']) || count($value['events'])>20)throw new RuntimeException('AUTOMATION_READ_INVALID');
  $keys=array_keys($value['counts']);sort($keys);
  if($keys!==['cancelled','completed','failed','pending'])throw new RuntimeException('AUTOMATION_READ_INVALID');
  foreach($value['counts'] as $count)if(!is_int($count)||$count<0||$count>200)throw new RuntimeException('AUTOMATION_READ_INVALID');
  if(array_sum($value['counts'])>200)throw new RuntimeException('AUTOMATION_READ_INVALID');
  foreach($value['events'] as $event){
   if(!is_array($event))throw new RuntimeException('AUTOMATION_READ_INVALID');$keys=array_keys($event);sort($keys);
   if($keys!==['action','at'] || !is_string($event['action']) || !preg_match('/\Aautomation\.(queued|cancelled|task_created|retry|failed|settings\.v(?:[1-9]|1[0-9]|20))\z/D',$event['action'])
     || !is_int($event['at']) || $event['at']<0 || $event['at']>$value['observed_at'])throw new RuntimeException('AUTOMATION_READ_INVALID');
  }
  // A successful transport/read is not a durable grant. Revalidate also on repeated requests and after I/O.
  if(($this->authorize)($admin,$company)!==true)throw new RuntimeException('AUTOMATION_READ_REVOKED');
  return $value;
 }
}
