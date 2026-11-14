<?php
declare(strict_types=1);

require_once __DIR__ . '/../app/Homologation/Commerce/ApprovedOfferCatalog.php';
require_once __DIR__ . '/../app/Homologation/Commerce/PerformanceTriChannelV5CandidateCatalog.php';
require_once __DIR__ . '/../app/Homologation/Commerce/PortalPerformanceTriChannelV5LabIssuer.php';

use App\Homologation\Commerce\ApprovedOfferCatalog;
use App\Homologation\Commerce\PerformanceTriChannelV5CandidateCatalog as Candidate;
use App\Homologation\Commerce\PortalPerformanceTriChannelV5LabIssuer as Issuer;

function mustV5(mixed $a, mixed $b, string $label): void {
    if ($a !== $b) throw new RuntimeException($label . ': ' . var_export($b,true));
}
function rejectV5(callable $fn, string $label): void {
    try { $fn(); } catch (InvalidArgumentException | RuntimeException) { return; }
    throw new RuntimeException('Candidate must reject: '.$label);
}
$cat=new Candidate();
$active=new ApprovedOfferCatalog();
mustV5(['chatgpt_ads'=>120000,'google_ads'=>100000,'meta_ads'=>70000],$cat->minima(),'approved channel minima');
mustV5(['chatgpt_ads'=>180000,'google_ads'=>150000,'meta_ads'=>100000],$cat->recommendations(),'recommended media');
$proposal=file_get_contents(__DIR__.'/../app/Homologation/Commerce/performance-channel-minima-candidate-v5.json');
mustV5(hash('sha256',$proposal),$cat->version(),'catalog candidate SHA256');
mustV5(true,$cat->version()!==$active->version(),'candidate differs from active v4');
$old=$active->quote('performance',['google_ads'=>70000]);
mustV5(14000,$old['estimated_management_fee_cents'],'v4 stays on original approved media minimum');
rejectV5(fn()=> $active->quote('performance',['chatgpt_ads'=>120000]),'v4 must NOT accept ChatGPT');

$media=['meta_ads'=>70000,'chatgpt_ads'=>120000,'google_ads'=>100000];
$quote=$cat->quote($media);
mustV5(['chatgpt_ads'=>120000,'google_ads'=>100000,'meta_ads'=>70000],$quote['media_by_channel_cents'],'sorted signed channels');
mustV5(290000,array_sum($quote['media_by_channel_cents']),'media budgets not fees');
mustV5(58000,$quote['estimated_management_fee_cents'],'20 percent planned');
mustV5(58000,$quote['amount_now_cents'],'illustrative advance');
mustV5(false,$quote['real_charge_enabled'],'never active Asaas');
mustV5(true,$quote['media_paid_directly_to_platforms'],'media directly to platforms');
mustV5('BRL',$quote['currency'],'BRL');
mustV5('asaas',$quote['gateway'],'PSP is merely a future Portal authority');
mustV5(2000,$quote['management_fee_basis_points'],'20 percent bps');

$allZero=$cat->reconcile($quote,['chatgpt_ads'=>0,'google_ads'=>0,'meta_ads'=>0]);
mustV5(0,$allZero['illustrative_management_fee_cents'],'no realized media means zero fee earned');
mustV5(-58000,$allZero['illustrative_adjustment_cents'],'unused advance is a review item, not automatic refund');
mustV5(false,$allZero['asaas_write_enabled'],'refund/charges disabled');
$partial=$cat->reconcile($quote,['chatgpt_ads'=>35000,'google_ads'=>50000,'meta_ads'=>80000]);
mustV5(33000,$partial['illustrative_management_fee_cents'],'20 percent of 1650 actual');
mustV5(-25000,$partial['illustrative_adjustment_cents'],'negative adjustment requires finance review');
rejectV5(fn()=> $cat->quote(['google_ads'=>99999]),'Google below 1000');
rejectV5(fn()=> $cat->quote(['meta_ads'=>69999]),'Meta below 700');
rejectV5(fn()=> $cat->quote(['chatgpt_ads'=>119999]),'ChatGPT below 1200');
rejectV5(fn()=> $cat->quote(['chatgpt_ads'=>120000.0]),'floating point media');
rejectV5(fn()=> $cat->quote(['chatgpt_ads'=>100000001]),'over technical ceiling');
rejectV5(fn()=> $cat->quote(['unknown_ads'=>120000]),'unknown provider');
rejectV5(fn()=> $cat->quote([]),'empty contract');
rejectV5(fn()=> $cat->reconcile($quote,['google_ads'=>50000,'meta_ads'=>80000]),'missing ChatGPT spend is NOT zero');
$forged=$quote; $forged['estimated_management_fee_cents']++;
rejectV5(fn()=> $cat->reconcile($forged,['chatgpt_ads'=>35000,'google_ads'=>50000,'meta_ads'=>80000]),'tampered quote');

$secret=str_repeat('S',48);
$tenant='tenant_v5candidate_1234567';
$now=1794660000;
$nonce='nonce_v5candidate_1234567890abcdef';
$issue=static fn(array $m,string $period='2026-11',string $n='nonce_v5candidate_1234567890abcdef')=>
    Issuer::issue($cat,$m,$tenant,$period,'k_v5candidate_01',$n,$now-3,$now+100,$secret);
$signed=$issue($media);
mustV5('performance-trichannel-v5-candidate',$signed['contract_version'],'explicit candidate contract label');
mustV5('2026-11',$signed['billing_period'],'month bound');
mustV5(64,strlen($signed['signature']),'sha256 hmac signature length');
mustV5($signed['signature'],$issue(array_reverse($media,true))['signature'],'canonical signed ordering');
mustV5(false,hash_equals($signed['signature'],$issue($media,'2026-12')['signature']),'month changes signature');
mustV5(false,hash_equals($signed['signature'],$issue($media,'2026-11','nonce_new_candidate_1234567890')['signature']),'nonce changes signature');
mustV5(false,hash_equals($signed['signature'],$issue(['chatgpt_ads'=>125000,'google_ads'=>100000,'meta_ads'=>70000])['signature']),'commercial media changes signature');

$unsigned=$signed;unset($unsigned['signature']);
ksort($unsigned,SORT_STRING);ksort($unsigned['quote'],SORT_STRING);
ksort($unsigned['quote']['media_by_channel_cents'],SORT_STRING);
$bytes="imagine:portal:performance:quote:v5\n".json_encode($unsigned,JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);
mustV5(hash_hmac('sha256',$bytes,$secret),$signed['signature'],'independently recomputed v5 signature bytes');
rejectV5(fn()=> $issue($media,'2026-13'),'invalid competence');
rejectV5(fn()=> Issuer::issue($cat,$media,$tenant,'2026-11','k_v5candidate_01',$nonce,$now,$now+301,$secret),'token duration');
rejectV5(fn()=> Issuer::issue($cat,$media,$tenant,'2026-11','k_v5candidate_01',$nonce,$now,$now+100,'weak'),'weak signing secret');

putenv('PORTAL_PERF_TRICHANNEL_V5_LAB=0');
rejectV5(fn()=> $cat->quote($media),'catalog fails closed');
rejectV5(fn()=> $issue($media),'issuer fails closed');
putenv('PORTAL_PERF_TRICHANNEL_V5_LAB=1');
putenv('APP_ENV=production');
rejectV5(fn()=> $cat->quote($media),'production catalog forbidden');
rejectV5(fn()=> $issue($media),'production issuer forbidden');
echo "OK: approved three-channel V5 candidate isolated from active Portal V4; HMAC month, nonce, catalog, channels, 20 percent, no payment/Ads write\n";
