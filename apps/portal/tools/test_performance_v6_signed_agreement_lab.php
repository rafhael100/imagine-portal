<?php
declare(strict_types=1);

require_once __DIR__.'/../app/Homologation/Commerce/PerformanceTriChannelV5CandidateCatalog.php';
require_once __DIR__.'/../app/Homologation/Commerce/PortalPerformanceV6AgreementLabIssuer.php';

use App\Homologation\Commerce\PerformanceTriChannelV5CandidateCatalog as Catalog;
use App\Homologation\Commerce\PortalPerformanceV6AgreementLabIssuer as Issuer;

function v6Assert(bool $ok,string $label):void {
    if(!$ok)throw new RuntimeException('Portal V6 agreement candidate: '.$label);
}
function v6Deny(callable $f,string $label):void {
    try{$f();}catch(InvalidArgumentException|RuntimeException){return;}
    throw new RuntimeException('Issuer accepted invalid V6 agreement: '.$label);
}
$cat=new Catalog();$secret=str_repeat('G',48);
$tenant='tenant_v6_portal_1234567';$period='2026-11';$now=1794660000;
$media=['meta_ads'=>70000,'google_ads'=>100000,'chatgpt_ads'=>120000];
$issue=static fn(string $agreement,array $plan=$media,string $month='2026-11',
    string $nonce='nonce_v6_issuer_1234567890')=>
    Issuer::issue($cat,$plan,$tenant,$agreement,$month,
        'k_v6_contract_test',$nonce,$now-1,$now+120,$secret);
$a=$issue('agreement_brand_01_1234567');
$b=$issue('agreement_brand_02_1234567');
v6Assert($a['agreement_id']==='agreement_brand_01_1234567','agreement included');
v6Assert($b['agreement_id']==='agreement_brand_02_1234567','second agreement included');
v6Assert(!hash_equals($a['signature'],$b['signature']),'different agreement alters signature even if rest identical');
v6Assert($a['contract_version']==='performance-trichannel-v6-agreement-candidate','version domain');
v6Assert($a['audience']==='imagine-performance-finance-v6-agreement-preview','audience domain');
v6Assert($a['quote']['estimated_management_fee_cents']===58000,'20 percent planned fee');
v6Assert($a['quote']['real_charge_enabled']===false,'no real payment');
v6Assert($a['quote']['catalog_version']===$cat->version(),'owner-approved catalog pin');
v6Assert($a['quote']['media_paid_directly_to_platforms']===true,'media paid direct');

$changed=$issue('agreement_brand_01_1234567',$media,'2026-12');
v6Assert(!hash_equals($changed['signature'],$a['signature']),'month HMAC bound');
$changed=$issue('agreement_brand_01_1234567',['chatgpt_ads'=>120001,'google_ads'=>100000,'meta_ads'=>70000]);
v6Assert(!hash_equals($changed['signature'],$a['signature']),'media HMAC bound');
$changed=$issue('agreement_brand_01_1234567',$media,'2026-11','nonce_v6_new_123456789012');
v6Assert(!hash_equals($changed['signature'],$a['signature']),'nonce HMAC bound');

$unsigned=$a;unset($unsigned['signature']);
ksort($unsigned,SORT_STRING);ksort($unsigned['quote'],SORT_STRING);
ksort($unsigned['quote']['media_by_channel_cents'],SORT_STRING);
$bytes="imagine:portal:performance:quote:v6\n".
    json_encode($unsigned,JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);
v6Assert(hash_equals(hash_hmac('sha256',$bytes,$secret),$a['signature']),'independent signed bytes parity');
v6Deny(fn()=>$issue('bad'),'agreement ID format');
v6Deny(fn()=>$issue('agreement_brand_01_1234567',['chatgpt_ads'=>119999]),'below owner approved floor');
v6Deny(fn()=>$issue('agreement_brand_01_1234567',$media,'2026-13'),'bad month');
v6Deny(fn()=>Issuer::issue($cat,$media,$tenant,'agreement_brand_01_1234567',$period,
    'k_v6_contract_test','nonce_v6_issuer_1234567890',$now,$now+301,$secret),'too long validity');
v6Deny(fn()=>Issuer::issue($cat,$media,$tenant,'agreement_brand_01_1234567',$period,
    'k_v6_contract_test','nonce_v6_issuer_1234567890',$now,$now+100,'short'),'weak key');
putenv('PORTAL_PERF_V6_AGREEMENT_LAB=0');
v6Deny(fn()=>$issue('agreement_brand_01_1234567'),'flag off');
putenv('PORTAL_PERF_V6_AGREEMENT_LAB=1');
putenv('APP_ENV=production');
v6Deny(fn()=>$issue('agreement_brand_01_1234567'),'production disabled');
echo "OK: Portal HMAC V6 signs immutable agreement identity, month, nonce, approved floors; no checkout/charge/Ads\n";
