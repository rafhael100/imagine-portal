<?php
declare(strict_types=1);
if(getenv('CI')!=='true'||getenv('ADMIN_PHASE3_HTTPS_CI')!=='1')exit(1);
require __DIR__.'/../../../vendor/autoload.php';
$app=require __DIR__.'/../../../bootstrap/app.php';$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
config()->set('performance_admin.enabled',true);config()->set('performance_admin.mode','isolated_ci');config()->set('performance_admin.service_review_enabled',true);
config()->set('performance_admin.engine_root',getenv('PERFORMANCE_ADMIN_LAB_ENGINE_ROOT'));
config()->set('performance_admin.service_review_url','https://performance-ci.internal:19443/v1/admin/read');config()->set('performance_admin.service_review_host','performance-ci.internal');config()->set('performance_admin.service_review_port',19443);config()->set('performance_admin.service_review_address','172.30.250.1');
$t=getenv('ADMIN_PHASE3_TLS');foreach(['ca'=>'ca.pem','certificate'=>'client.pem','private_key'=>'client.key'] as $key=>$file)config()->set('performance_admin.service_review_'.$key,$t.'/'.$file);
$s=json_decode(file_get_contents(getenv('ADMIN_PHASE3_STATE')),true,16,JSON_THROW_ON_ERROR);
$identity=fn()=>['issuer'=>'portal_service','key_id'=>'service_key_01','active'=>true,'secret'=>$s['secret'],'expires_at'=>time()+120,'companies'=>['company_alfa','company_beta'],'operations'=>['dashboard.read']];
$events=[];$client=new App\Domain\PerformanceAdministration\SignedServiceReadClient($identity,function($event,$id)use(&$events){$events[]=[$event,$id];});
$n=0;$ok=function($v)use(&$n){if(!$v)throw new RuntimeException('HTTPS assertion '.($n+1));$n++;};
$r=$client->read('company_alfa','dashboard.read',[],'https_request001');$ok($r['status']==='unavailable'&&$r['metrics']===null&&$r['billable']===false);
$ok($client->read('company_alfa','dashboard.read',[],'https_request001')===$r);
try{$client->read('company_beta','dashboard.read',[],'https_cross0001');throw new LogicException('Cross-company accepted');}catch(RuntimeException){$ok(true);}
try{$client->read('company_alfa','dashboard.read',['resource'=>'revoke_during_read'],'https_revoke001');throw new LogicException('Revocation accepted');}catch(RuntimeException){$ok(true);}
try{$client->read('company_alfa','dashboard.read',[],'https_request001');throw new LogicException('Revoked retry accepted');}catch(RuntimeException){$ok(true);}
$ok(count($events)===2);$ok(count(file($s['audit']))===3);
echo "OK: $n separate-application HTTPS/mTLS/read/retry/cross-company/revocation assertions; synthetic authorities only\n";
