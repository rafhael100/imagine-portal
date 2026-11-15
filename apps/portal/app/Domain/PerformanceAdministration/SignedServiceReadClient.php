<?php
declare(strict_types=1);
namespace App\Domain\PerformanceAdministration;
use Closure;
use Illuminate\Support\Facades\Http;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Str;
use Imagine\Performance\Administration\AdministrativeServiceEnvelope as Envelope;
use RuntimeException;
/** Unwired read-only candidate. Credentials come from server resolver, never Request/session. */
final class SignedServiceReadClient
{
 public function __construct(private Closure $identity,private Closure $audit){}
 private function resolve(string $issuer,string $key):?array
 {
  $identity=($this->identity)();
  return is_array($identity)&&($identity['issuer']??null)===$issuer&&($identity['key_id']??null)===$key?$identity:null;
 }
 public function read(string $company,string $operation,array $query,string $idempotencyKey):array
 {
  if(!PrivateDemonstrationPolicy::allowed()||config('performance_admin.service_review_enabled')!==true)throw new RuntimeException('Service review disabled');
  $url=config('performance_admin.service_review_url');
  if(!is_string($url)||parse_url($url,PHP_URL_SCHEME)!=='https'||parse_url($url,PHP_URL_USER)!==null||parse_url($url,PHP_URL_PASS)!==null
      ||parse_url($url,PHP_URL_QUERY)!==null||parse_url($url,PHP_URL_FRAGMENT)!==null||parse_url($url,PHP_URL_PATH)!=='/v1/admin/read')throw new RuntimeException('Trusted HTTPS read endpoint required');
  $path=rtrim((string)config('performance_admin.engine_root'),'/').'/src/Domain/Administration/AdministrativeServiceEnvelope.php';
  if(!is_file($path)||!hash_equals((string)config('performance_admin.service_envelope_hash'),hash_file('sha256',$path)))throw new RuntimeException('Service contract integrity denied');
  require_once $path;
  $body=json_encode((object)$query,JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES);
  for($attempt=0;$attempt<2;$attempt++){
   $identity=($this->identity)();
   if(!is_array($identity)||!is_string($identity['issuer']??null)||!is_string($identity['key_id']??null)||!is_string($identity['secret']??null))throw new RuntimeException('Service identity unavailable');
   $e=Envelope::issue($identity['issuer'],$identity['key_id'],$company,$operation,str_replace('-','',Str::uuid()->toString()),$idempotencyKey,$body,time(),$identity['secret']);
   Envelope::verify($e,$body,time(),fn($issuer,$key)=>$this->resolve($issuer,$key));
   try{$response=Http::withOptions(['verify'=>true,'allow_redirects'=>false])->acceptJson()->connectTimeout(2)->timeout(5)
     ->withHeaders(['X-Imagine-Correlation'=>$e['correlation_id']])->post($url,['envelope'=>$e,'body'=>$body]);}
   catch(ConnectionException){($this->audit)('service_timeout',$e['correlation_id']);if($attempt===0)continue;throw new RuntimeException('Service unavailable');}
   if($response->serverError()&&$attempt===0){($this->audit)('service_retry',$e['correlation_id']);continue;}
   if(!$response->successful())throw new RuntimeException('Service response denied');
   Envelope::verify($e,$body,time(),fn($issuer,$key)=>$this->resolve($issuer,$key));
   $r=$response->json();$fields=['version','company_id','operation','correlation_id','status','metrics','external_operations_enabled','billable'];
   $keys=is_array($r)?array_keys($r):[];sort($keys);sort($fields);
   if($keys!==$fields||$r['version']!==Envelope::VERSION||$r['company_id']!==$company||$r['operation']!==$operation
       ||$r['correlation_id']!==$idempotencyKey||!in_array($r['status'],['synthetic','unavailable'],true)
       ||$r['external_operations_enabled']!==false||$r['billable']!==false
       ||($r['status']==='unavailable'&&$r['metrics']!==null))throw new RuntimeException('Service response integrity denied');
   ($this->audit)('service_read_candidate',$e['correlation_id']);return $r;
  }
  throw new RuntimeException('Service unavailable');
 }
}
