<?php
declare(strict_types=1);

require_once __DIR__.'/../app/Homologation/Commerce/PortalPerformanceAdsReadGrantLabIssuer.php';

use App\Homologation\Commerce\PortalPerformanceAdsReadGrantLabIssuer as Issuer;

function assertGrant(bool $ok,string $msg):void {
    if(!$ok)throw new RuntimeException('Portal Ads grant lab: '.$msg);
}
function denyGrant(callable $f,string $label):void {
    try{$f();}catch(InvalidArgumentException|RuntimeException){return;}
    throw new RuntimeException('Invalid grant was issued: '.$label);
}

$tenant='tenant_ads_grant_123456';
$agreement='agreement_ads_grant_123456';
$account='adacct_1234567';
$ref='ref_ads_grant_12345678901';
$consent='consent_ads_grant_123456';
$keyId='k_perf_ads_read_01';
$nonce='nonce_s2s_ads_read_1234567890';
$now=1794660000;
$secret=str_repeat('Z',48);
$issue=static fn(
    ?string $t=null,?string $a=null,?string $acct=null,
    ?string $credential=null,?string $period=null,?string $n=null
):array=>Issuer::issue(
    $t??$tenant,$a??$agreement,$period??'2026-11',
    $acct??$account,$credential??$ref,$consent,$keyId,$n??$nonce,
    $now-1,$now+90,$secret
);
$g=$issue();
assertGrant($g['tenant_id']===$tenant && $g['agreement_id']===$agreement,
    'tenant/agreement bound');
assertGrant($g['permission']==='ads.insights.read' &&
            $g['provider']==='chatgpt_ads','only ChatGPT Ads reporting');
assertGrant($g['can_mutate_ads']===false && $g['can_charge']===false,
    'no write or billing');
assertGrant($g['audience']==='imagine-performance-ai.ads-read-lab',
    'read-only audience pin');
assertGrant($g['expires_at']-$g['issued_at']===91,
    'short expiry');
$copy=$g;$signature=$copy['signature'];unset($copy['signature']);
ksort($copy,SORT_STRING);
$expected=hash_hmac('sha256',
    "imagine:portal:performance:ads-read-grant:lab:v1\n".
    json_encode($copy,JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE),
    $secret);
assertGrant(hash_equals($expected,$signature),'canonical HMAC bytes parity');
assertGrant(!hash_equals($signature,$issue(null,null,'adacct_999999')['signature']),
    'different account has different signature');
assertGrant(!hash_equals($signature,$issue(null,'agreement_other_123456')['signature']),
    'different contract has different signature');
assertGrant(!hash_equals($signature,$issue('tenant_other_123456')['signature']),
    'different tenant has different signature');
assertGrant(!hash_equals($signature,$issue(null,null,null,
    'ref_other_123456789012')['signature']),
    'different credential reference has different signature');
assertGrant(!hash_equals($signature,$issue(null,null,null,null,'2026-12')['signature']),
    'different period changes signature');
assertGrant(!hash_equals($signature,$issue(null,null,null,null,null,
    'nonce_s2s_ads_read_other_123456')['signature']),
    'nonce changes signature');
denyGrant(fn()=>$issue('other'),'forbidden tenant format');
denyGrant(fn()=>$issue(null,null,'adacct_'),'missing account');
denyGrant(fn()=>$issue(null,null,null,'Bearer raw-secret'),'reject secret instead of ref');
denyGrant(fn()=>$issue(null,null,null,null,'2026-13'),'bad month');
denyGrant(fn()=>Issuer::issue($tenant,$agreement,'2026-11',$account,$ref,
    $consent,$keyId,$nonce,$now,$now+121,$secret),'TTL > 120');
denyGrant(fn()=>Issuer::issue($tenant,$agreement,'2026-11',$account,$ref,
    $consent,$keyId,$nonce,$now,$now+30,'weak'),'short secret');
putenv('PORTAL_PERF_ADS_S2S_READ_GRANT_LAB=0');
denyGrant(fn()=>$issue(),'lab flag off');
putenv('PORTAL_PERF_ADS_S2S_READ_GRANT_LAB=1');
putenv('APP_ENV=production');
denyGrant(fn()=>$issue(),'production disabled');
echo "OK: Portal HMAC Ads read grant tenant+agreement+account+credential+period+nonce+TTL, no writes/charges\n";
