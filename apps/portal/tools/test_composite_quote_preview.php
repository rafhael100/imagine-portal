<?php
declare(strict_types=1);
require __DIR__.'/../app/Homologation/Commerce/CompositeQuotePreview.php';

use App\Homologation\Commerce\CompositeQuotePreview;
use App\Homologation\Commerce\ApprovedOfferCatalog;

$c=new CompositeQuotePreview();
$source=new ApprovedOfferCatalog();
$checks=0;$success=0;$denied=0;
function check(bool $condition,string $label): void {
    global $checks;
    $checks++;
    if (!$condition) throw new RuntimeException('Composite preview invariant: '.$label);
}
function mustDeny(callable $call): void {
    global $denied;
    try { $call(); } catch (InvalidArgumentException) {$denied++;return;}
    throw new RuntimeException('Unapproved composite quote unexpectedly accepted');
}
$names=['google_ads','meta_ads','chatgpt_ads'];
foreach (['none','onepage','landing-pages'] as $site)
    foreach (['minimum','recommended'] as $tier)
        for($mask=0;$mask<8;$mask++){
            $channels=[];
            foreach ($names as $i=>$name)
                if (($mask&(1<<$i))!==0) $channels[]=$name;
            $selection=['site'=>$site,'channels'=>$channels,'tier'=>$tier];
            if (in_array('chatgpt_ads',$channels,true)||
                ($site==='none'&&$channels===[])){
                mustDeny(fn()=>$c->quote($selection));
                continue;
            }
            $q=$c->quote($selection);
            $success++;
            check($q['contract_version']==='portal-composite-quote-v5-lab-v1','version');
            check($q['catalog_version']===$source->version(),'catalog digest');
            check($q['site']===$site&&$q['media_tier']===$tier&&$q['channels']===$channels,'selection');
            $s=$site==='none'?null:$source->quote($site);
            $media=[];
            $offering=$source->offers()['performance'];
            foreach ($channels as $channel) {
                $media[$channel]=$offering[$tier.'_media_investment_by_channel_cents'][$channel];
            }
            $performance=$media===[]?null:$source->quote('performance',$media);
            $setup=$s['setup_total_cents']??0;
            $activation=$performance['activation_total_cents']??0;
            check($q['site_setup_cents']===$setup,'site amount');
            check($q['advertising_activation_cents']===$activation,'activation');
            check($q['setup_activation_total_cents']===$setup+$activation,'combined total');
            check($q['site_monthly_cents']===($s['monthly']['amount_cents']??0),'monthly site');
            check($q['site_monthly_first_due_offset_days']===($s['monthly']['first_due_offset_days']??null),'D90');
            check($q['site_monthly_anchor']===($s['monthly']['anchor']??null),'contract anchor');
            check($q['management_estimate_postpaid_cents']===
                ($performance['estimated_management_fee_cents']??0),'postpaid estimate');
            check($q['media_paid_directly_to_platforms_cents']===array_sum($media),'media direct');
            check(count($q['illustrative_three_parts_cents'])===3,'illustrative parts');
            check(array_sum($q['illustrative_three_parts_cents'])===$setup+$activation,'parts sum');
            check($q['illustrative_three_parts_cents'][0]===$q['illustrative_three_parts_cents'][1],'two equal');
            check($q['illustrative_three_parts_cents'][2]-$q['illustrative_three_parts_cents'][0]<=2,'cent adjustment');
            foreach ([
                'single_product_checkout_created','atomic_multi_product_checkout_implemented',
                'atomic_multi_channel_checkout_implemented','payment_schedule_created',
                'customer_or_tenant_data_captured','contract_accepted','real_charge_enabled',
                'real_postpaid_invoice_authorized','public_checkout_authorized','operator_approval_verified'
            ] as $flag) check($q[$flag]===false,'forbidden authority '.$flag);
            check($q['release_status']==='HOLD_PORTAL_AUTHN_CART_PSP_SETTLEMENT_AND_HUMAN_GO','HOLD');
            $asText=json_encode($q,JSON_THROW_ON_ERROR);
            check(!str_contains($asText,'https://')&&!str_contains($asText,'customer_id'),'no urls or customer');
        }
check($success===22,'22 valid anonymous preview cases');
check($denied===26,'26 rejected selection cases');
$special=$c->quote(['site'=>'onepage',
 'channels'=>['google_ads','meta_ads'],'tier'=>'recommended']);
check($special['setup_activation_total_cents']===197000,'combined R1970');
check($special['illustrative_three_parts_cents']===[65666,65666,65668],'exact 3 cent parts');
check($special['site_monthly_cents']===7990,'site monthly D90');
check($special['management_estimate_postpaid_cents']===50000,'postpaid fee separate');
check($special['media_paid_directly_to_platforms_cents']===250000,'media direct separate');

$base=['site'=>'onepage','channels'=>['google_ads'],'tier'=>'minimum'];
foreach ([
 array_replace($base,['customerId'=>'sensitive']),
 array_replace($base,['tenantId'=>'sensitive']),
 array_replace($base,['priceCents'=>1]),
 array_replace($base,['checkoutAuthorized'=>true]),
 array_replace($base,['channels'=>['chatgpt_ads']]),
 array_replace($base,['channels'=>['google_ads','google_ads']]),
 array_replace($base,['channels'=>['facebook_ads']]),
 array_replace($base,['channels'=>['google_ads','meta_ads','chatgpt_ads']]),
 array_replace($base,['channels'=>[0=>'google_ads',2=>'meta_ads']]),
 array_replace($base,['channels'=>['google_ads'=>1]]),
 array_replace($base,['site'=>'unapproved']),
 array_replace($base,['tier'=>'vip']),
 array_replace($base,['tier'=>false]),
 array_replace($base,['site'=>false]),
 array_replace($base,['channels'=>false]),
 array_replace($base,['channels'=>[null]]),
 ['site'=>'onepage','channels'=>['google_ads']],
 ['site'=>'onepage','tier'=>'minimum']
] as $unsafe)mustDeny(fn()=>$c->quote($unsafe));

echo json_encode([
 'contractVersion'=>'portal-composite-quote-v5-lab-v1',
 'scenariosReviewed'=>48,'validAnonymousQuotes'=>$success,
 'deniedScenarios'=>26,'additionalAdversarialDenials'=>18,
 'pricingChecks'=>$checks,
 'siteAndAdsCombinedIllustrated'=>true,
 'atomicCheckoutImplemented'=>false,
 'realChargeAuthorized'=>false,
 'noCustomerData'=>true,
 'releaseStatus'=>'HOLD_PORTAL_AUTHN_CART_PSP_SETTLEMENT_AND_HUMAN_GO'
],JSON_THROW_ON_ERROR)."\n";
