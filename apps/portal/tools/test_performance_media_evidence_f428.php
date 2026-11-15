<?php
declare(strict_types=1);
/** F428 offline evidence only; never sends requests to Meta, Google or PSP. */
require __DIR__.'/../app/Homologation/Commerce/ApprovedOfferCatalog.php';
require __DIR__.'/../app/Homologation/Commerce/PerformanceSettlement.php';
require __DIR__.'/../app/Homologation/Commerce/PerformanceMediaEvidenceReconciliation.php';

use App\Homologation\Commerce\ApprovedOfferCatalog;
use App\Homologation\Commerce\PerformanceSettlement;
use App\Homologation\Commerce\PerformanceMediaEvidenceReconciliation as Media;

const TENANT='org-offline-f428';
const ORDER='order-offline-f428';
const PERIOD='2026-11';
const NOW='2026-12-05T12:00:00Z';

$offer=(new ApprovedOfferCatalog())->quote(
    'performance',['google_ads'=>100_000,'meta_ads'=>70_000]
);
$report=PerformanceSettlement::calculate(
    $offer,PERIOD,['google_ads'=>400_000,'meta_ads'=>35_000],
    'SIM-F428-MEDIA-ACCOUNT-REVIEW'
);
$accounts=['google_ads'=>'google-customer-f428','meta_ads'=>'meta-account-f428'];

function fixture(string $channel,string $account,array $rows):array {
    return [
        'account_ref'=>$account,
        'channel'=>$channel,
        'complete'=>true,
        'currency'=>'BRL',
        'generated_at_utc'=>'2026-12-02T04:00:00Z',
        'order_ref'=>ORDER,
        'origin'=>'offline_synthetic_fixture',
        'period'=>PERIOD,
        'rows'=>$rows,
        'tenant_ref'=>TENANT,
        'timezone'=>'America/Sao_Paulo',
    ];
}
$statements=[
    'google_ads'=>fixture('google_ads',$accounts['google_ads'],[
        ['event_ref'=>'google-day-01','day'=>'2026-11-01','spend_cents'=>390_000],
        ['event_ref'=>'google-day-02','day'=>'2026-11-30','spend_cents'=>10_000],
    ]),
    'meta_ads'=>fixture('meta_ads',$accounts['meta_ads'],[
        ['event_ref'=>'meta-day-01','day'=>'2026-11-15','spend_cents'=>35_000],
    ]),
];
$checks=0;
function check(bool $ok,string $label):void {
    global $checks;
    $checks++;
    if (!$ok)throw new RuntimeException('F428 failed: '.$label);
}
function inspect(array $s,array $accountsOverride=[],array $reportOverride=[],
    string $tenant=TENANT,string $order=ORDER,string $period=PERIOD,
    string $now=NOW):array {
    global $offer,$report,$accounts;
    return Media::inspect($offer,$reportOverride?:$report,$tenant,$order,
        $accountsOverride?:$accounts,$s,$period,$now);
}
function deny(callable $fn,string $label):void {
    try {$fn();}
    catch(InvalidArgumentException) {check(true,'rejected '.$label);return;}
    throw new RuntimeException('F428 unexpectedly accepted '.$label);
}
$result=inspect($statements);
check($result['schema']==='imagine.portal.performance.synthetic-media-review.v1','schema');
check($result['amounts_match_offline']===true,'exact amounts');
check($result['channels']['google_ads']['statement_spend_cents']===400_000,'google R$4000');
check($result['channels']['meta_ads']['statement_spend_cents']===35_000,'meta R$350');
check($result['channels']['google_ads']['rows']===2,'multiple daily rows');
check($result['discrepancies']===[],'no offline discrepancies');
check($result['official_media_evidence_verified']===false,'not official proof');
foreach(['can_charge','can_issue_invoice','can_write_psp','can_activate_ads',
         'contractual_partial_month_policy_verified'] as $key) {
    check($result[$key]===false,'NO-GO '.$key);
}
$wrong=$report;$wrong['channels']['google_ads']['actual_cents']++;
$diff=inspect($statements,reportOverride:$wrong);
check($diff['amounts_match_offline']===false
    && $diff['discrepancies'][0]['reason']==='statement_report_disagree','report mismatch');
$empty=$statements;$empty['google_ads']['rows']=[];
$diff=inspect($empty);
check($diff['amounts_match_offline']===false
    && $diff['discrepancies'][0]['reason']==='zero_spend_requires_human_review','zero media blocks');
check($result['channels']['google_ads']['synthetic_rows_sha256']!==
    $diff['channels']['google_ads']['synthetic_rows_sha256'],'evidence digest changes');

deny(static fn()=>inspect($statements,tenant:'org-cross-tenant'),'cross-tenant');
deny(static fn()=>inspect($statements,order:'order-cross-tenant'),'cross-order');
deny(static fn()=>inspect($statements,period:'2026-10'),'cross-period');
deny(static fn()=>inspect($statements,now:'2026-11-15T00:00:00Z'),'open month');
$bad=$accounts;$bad['google_ads']='google-other-account';
deny(static fn()=>inspect($statements,accountsOverride:$bad),'cross-account registry');
$bad=$statements;$bad['meta_ads']['account_ref']='meta-other-account';
deny(static fn()=>inspect($bad),'cross-account statement');
$bad=$statements;$bad['meta_ads']['tenant_ref']='org-untrusted';
deny(static fn()=>inspect($bad),'forged tenant');
$bad=$statements;$bad['meta_ads']['origin']='google_ads_api';
deny(static fn()=>inspect($bad),'claims of official vendor authorization');
$bad=$statements;$bad['google_ads']['complete']=false;
deny(static fn()=>inspect($bad),'incomplete statement');
$bad=$statements;$bad['google_ads']['currency']='USD';
deny(static fn()=>inspect($bad),'wrong currency');
$bad=$statements;$bad['google_ads']['timezone']='UTC';
deny(static fn()=>inspect($bad),'wrong timezone');
$bad=$statements;$bad['google_ads']['generated_at_utc']='2026-11-30T22:00:00Z';
deny(static fn()=>inspect($bad),'not closed at generation timestamp');
$bad=$statements;$bad['google_ads']['generated_at_utc']='2027-01-01T01:00:00Z';
deny(static fn()=>inspect($bad),'statement after trusted now');
$bad=$statements;$bad['google_ads']['rows'][]=$bad['google_ads']['rows'][0];
deny(static fn()=>inspect($bad),'duplicate transaction identifier');
$bad=$statements;$bad['google_ads']['rows'][0]['day']='2026-10-31';
deny(static fn()=>inspect($bad),'previous-month data');
$bad=$statements;$bad['google_ads']['rows'][0]['day']='2026-11-31';
deny(static fn()=>inspect($bad),'invalid calendar day');
$bad=$statements;$bad['google_ads']['rows'][0]['spend_cents']=-1;
deny(static fn()=>inspect($bad),'negative money');
$bad=$statements;$bad['google_ads']['rows'][0]['spend_cents']='390000';
deny(static fn()=>inspect($bad),'string money');
$bad=$statements;$bad['google_ads']['rows'][0]['phantom']='inject';
deny(static fn()=>inspect($bad),'unexpected provider row metadata');
$bad=$statements;$bad['extra_ads']=fixture('google_ads','foo',[]);
deny(static fn()=>inspect($bad),'uncontracted channel');
$bad=$statements;unset($bad['meta_ads']);
deny(static fn()=>inspect($bad),'missing contracted channel');

echo "PASS F428: {$checks} scoped synthetic media-evidence checks; discrepancies block review and no official/PSP billing authority.\n";
