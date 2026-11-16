<?php

declare(strict_types=1);
namespace App\Domain\AutomationAdministration;
use Closure;
use RuntimeException;

/** Fixed-destination private client. No cookies, bearer session, redirect or shared product database. */
final readonly class AutomationS2SClient
{
 public function __construct(private string $ca,private string $certificate,private string $privateKey,private string $signingKeyFile) {}
 public function reader(Closure $authorize): AutomationStateReader {
  return new AutomationStateReader(fn(string $company)=>$this->read($company),$authorize);
 }
 public function read(string $company,?string $requestId=null,?string $nonce=null):array {
  if(!preg_match('/\A[a-f0-9]{8}-[a-f0-9]{4}-4[a-f0-9]{3}-[89ab][a-f0-9]{3}-[a-f0-9]{12}\z/D',$company))throw new RuntimeException('AUTOMATION_SCOPE_DENIED');
  $key=file_get_contents($this->signingKeyFile);
  if(!is_string($key)||strlen($key)<32||strlen($key)>256)throw new RuntimeException('AUTOMATION_KEY_UNAVAILABLE');
  $random=random_bytes(16);$random[6]=chr((ord($random[6])&15)|64);$random[8]=chr((ord($random[8])&63)|128);
  $uuid=vsprintf('%s%s-%s-%s-%s-%s%s%s',str_split(bin2hex($random),4));
  $v=['schema'=>'CENTRAL_AUTO_MTLS_REQUEST_V2','issuer'=>'portal-automation-admin','audience'=>'crm-automation-state',
   'operation'=>'automation.state.read','company_id'=>$company,'request_id'=>$requestId??$uuid,'nonce'=>$nonce??bin2hex(random_bytes(32)),'issued_at'=>time()];
  $v['mac']=hash_hmac('sha256',implode('|',$v),$key);
  $ctx=stream_context_create(['http'=>['method'=>'POST','header'=>"Content-Type: application/json\r\nAccept: application/json\r\nConnection: close\r\n",'content'=>json_encode($v,JSON_THROW_ON_ERROR),
   'timeout'=>3,'follow_location'=>0,'max_redirects'=>0,'ignore_errors'=>true],
   'ssl'=>['verify_peer'=>true,'verify_peer_name'=>true,'allow_self_signed'=>false,'peer_name'=>'crm-s2s',
   'cafile'=>$this->ca,'local_cert'=>$this->certificate,'local_pk'=>$this->privateKey,
   'crypto_method'=>STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT|STREAM_CRYPTO_METHOD_TLSv1_3_CLIENT]]);
  // Fixed private hostname and path: no browser input or provider-supplied URL.
  $raw=@file_get_contents('https://crm-s2s:8443/private/automations/state',false,$ctx,0,16385);
  $status=$http_response_header[0]??'';
  if(!is_string($raw)||strlen($raw)>16384||!preg_match('/\AHTTP\/\S+ 200(?: |$)/',$status))throw new RuntimeException('AUTOMATION_TRANSPORT_DENIED');
  $r=json_decode($raw,true,8,JSON_THROW_ON_ERROR);if(!is_array($r))throw new RuntimeException('AUTOMATION_REPLY_INVALID');
  $keys=array_keys($r);sort($keys);
  if($keys!==['body_b64','company_id','mac','nonce','operation','request_id','schema'])throw new RuntimeException('AUTOMATION_REPLY_INVALID');
  foreach($r as $value)if(!is_string($value))throw new RuntimeException('AUTOMATION_REPLY_INVALID');
  if($r['schema']!=='CENTRAL_AUTO_MTLS_RESPONSE_V2'||$r['company_id']!==$company||$r['request_id']!==$v['request_id']||$r['nonce']!==$v['nonce']||$r['operation']!==$v['operation'])throw new RuntimeException('AUTOMATION_REPLY_MISMATCH');
  $expected=hash_hmac('sha256',implode('|',array_map(fn($k)=>$r[$k],['schema','request_id','nonce','company_id','operation','body_b64'])),$key);
  if(!hash_equals($expected,$r['mac']))throw new RuntimeException('AUTOMATION_REPLY_SIGNATURE');
  $body=base64_decode($r['body_b64'],true);if($body===false||strlen($body)>8192)throw new RuntimeException('AUTOMATION_REPLY_LIMIT');
  return json_decode($body,true,16,JSON_THROW_ON_ERROR);
 }
}
