<?php
namespace Tests\Unit;
use Tests\TestCase;
use Illuminate\Support\Facades\Http;
use App\Domain\PerformanceAdministration\SignedServiceReadClient;
final class SignedServiceReadClientTest extends TestCase
{
 public function test_retries_sign_fresh_nonce_with_same_idempotency_and_never_forward_browser_credentials():void
 {
  if(getenv('PERFORMANCE_ADMIN_PG_CI')!=='1')$this->markTestSkipped('Requires exact cross-repository candidate checkout');
  config()->set('performance_admin.enabled',true);config()->set('performance_admin.mode','isolated_ci');
  config()->set('performance_admin.service_review_enabled',true);config()->set('performance_admin.service_review_url','https://performance.example.test/v1/admin/read');config()->set('performance_admin.service_review_host','performance.example.test');config()->set('performance_admin.service_review_address','172.20.0.4');
  // Fake HTTP test checks wiring only; separate HTTPS rehearsal supplies real ephemeral PEMs.
  foreach(['service_review_ca','service_review_certificate','service_review_private_key'] as $key)config()->set('performance_admin.'.$key,__FILE__);
  $identity=['issuer'=>'portal_service','key_id'=>'service_key_01','secret'=>str_repeat('K',48),'active'=>true,'expires_at'=>time()+120,'companies'=>['company_alfa'],'operations'=>['dashboard.read']];
  Http::preventStrayRequests();$calls=[];
  Http::fake(function($request)use(&$calls){$calls[]=$request;
   if(count($calls)===1)return Http::response([],503);
   $e=$request->data()['envelope'];return Http::response(['version'=>$e['version'],'company_id'=>$e['company_id'],'operation'=>$e['operation'],'correlation_id'=>$e['correlation_id'],'status'=>'unavailable','metrics'=>null,'external_operations_enabled'=>false,'billable'=>false]);
  });
  $events=[];$client=new SignedServiceReadClient(fn()=>$identity,function($event,$id)use(&$events){$events[]=[$event,$id];});
  $r=$client->read('company_alfa','dashboard.read',[],'request_unique_001');
  self::assertNull($r['metrics']);self::assertCount(2,$calls);
  $first=$calls[0]->data()['envelope'];$second=$calls[1]->data()['envelope'];
  self::assertNotSame($first['nonce'],$second['nonce']);self::assertSame($first['idempotency_key'],$second['idempotency_key']);
  foreach($calls as $request){self::assertFalse($request->hasHeader('Authorization'));self::assertFalse($request->hasHeader('Cookie'));self::assertSame(['envelope','body'],array_keys($request->data()));}
  self::assertSame('service_retry',$events[0][0]);self::assertSame('service_read_candidate',$events[1][0]);
 }
 public function test_disabled_candidate_and_insecure_url_make_no_request():void
 {
  Http::preventStrayRequests();Http::fake();$c=new SignedServiceReadClient(fn()=>[],fn()=>null);
  try{$c->read('company_alfa','dashboard.read',[],'request_unique_001');self::fail();}catch(\RuntimeException $e){self::assertSame('Service review disabled',$e->getMessage());}
  config()->set('performance_admin.enabled',true);config()->set('performance_admin.mode','isolated_ci');config()->set('performance_admin.service_review_enabled',true);config()->set('performance_admin.service_review_url','http://performance.example.test/v1/admin/read');
  try{$c->read('company_alfa','dashboard.read',[],'request_unique_001');self::fail();}catch(\RuntimeException $e){self::assertSame('Trusted HTTPS read endpoint required',$e->getMessage());}
  Http::assertNothingSent();
 }
 public function test_public_or_unpinned_destination_is_rejected_before_transport():void
 {
  config()->set('performance_admin.enabled',true);config()->set('performance_admin.mode','isolated_ci');config()->set('performance_admin.service_review_enabled',true);
  config()->set('performance_admin.service_review_url','https://performance.example.test/v1/admin/read');
  config()->set('performance_admin.service_review_host','performance.example.test');config()->set('performance_admin.service_review_address','8.8.8.8');
  Http::preventStrayRequests();Http::fake();$c=new SignedServiceReadClient(fn()=>[],fn()=>null);
  try{$c->read('company_alfa','dashboard.read',[],'request_unique_001');self::fail();}catch(\RuntimeException $e){self::assertSame('Private pinned destination required',$e->getMessage());}
  Http::assertNothingSent();
 }

}
