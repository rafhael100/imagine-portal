<?php
declare(strict_types=1);
/** F425 deterministic standalone finance/HMAC fixture. No DB, network or PSP. */
require __DIR__.'/../app/Homologation/Commerce/ApprovedOfferCatalog.php';
require __DIR__.'/../app/Homologation/Commerce/PerformanceSettlement.php';
require __DIR__.'/../app/Homologation/Commerce/PerformanceMonthlyCloseCandidate.php';
require __DIR__.'/../app/Homologation/Commerce/PerformanceMonthlyCloseReviewEnvelope.php';

use App\Homologation\Commerce\ApprovedOfferCatalog as Catalog;
use App\Homologation\Commerce\PerformanceSettlement as Settlement;
use App\Homologation\Commerce\PerformanceMonthlyCloseCandidate as Close;
use App\Homologation\Commerce\PerformanceMonthlyCloseReviewEnvelope as Wire;

const SIGNING_KEY='f425-pure-test-only-signing-key-no-production-secrets';
const TENANT='org-synthetic-f425';
const ORDER='order-synthetic-f425';
const ISSUED='2026-12-05T12:00:00Z';
const NOW='2026-12-05T12:07:00Z';

$catalog=new Catalog();
$quote=$catalog->quote('performance',['google_ads'=>100_000,'meta_ads'=>70_000]);
$report=Settlement::calculate(
    $quote,'2026-11',['google_ads'=>400_000,'meta_ads'=>35_000],
    'SIM-F425-MONTH-CLOSE'
);
$candidate=Close::inspect(
    $quote,$report,'acknowledged','2026-10-01','2026-11',
    '2026-12-05','2026-12-08'
);
$digest=hash('sha256',json_encode($report,JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES));
$wire=Wire::issue($candidate,TENANT,ORDER,2,$digest,ISSUED,SIGNING_KEY);
$checks=0;
function check(bool $ok,string $reason):void {
    global $checks;
    $checks++;
    if (!$ok)throw new RuntimeException('F425 invalid outcome: '.$reason);
}
function denyIssue(callable $call,string $reason):void {
    try {
        $call();
    }catch(InvalidArgumentException) {
        check(true,$reason);
        return;
    }
    throw new RuntimeException('F425 unexpectedly signed '.$reason);
}
function verify(
    array $target,
    array $source,
    string $tenant=TENANT,
    string $order=ORDER,
    int $version=2,
    string $digestOverride='',
    string $when=NOW,
    string $key=SIGNING_KEY
):bool {
    global $digest;
    return Wire::verify(
        $target,$source,$tenant,$order,$version,
        $digestOverride===''?$digest:$digestOverride,$when,$key
    );
}

check($wire['payload']['schema']==='imagine.portal.performance.month-close-envelope.v1','schema');
check($wire['payload']['tenant_ref']===TENANT,'tenant');
check($wire['payload']['order_ref']===ORDER,'order');
check($wire['payload']['report_version']===2,'report version');
check($wire['payload']['review_amount_cents']===97_000,'R$970 signed review');
check($wire['payload']['period']==='2026-11','period');
check($wire['payload']['expires_at']==='2026-12-05T12:15:00Z','15 minute expiry');
foreach(['can_issue_invoice','can_charge','can_write_psp','can_grant_service'] as $flag){
    check($wire['payload'][$flag]===false,'NO-GO '.$flag);
}
check(verify($wire,$candidate),'original valid review');
check(verify($wire,$candidate,when:'2026-12-05T12:15:00Z'),'expiry inclusive');
check(!verify($wire,$candidate,when:'2026-12-05T12:15:01Z'),'expired');
check(!verify($wire,$candidate,when:'2026-12-05T11:59:59Z'),'premature');
check(!verify($wire,$candidate,tenant:'org-other-f425'),'cross tenant');
check(!verify($wire,$candidate,order:'order-other-f425'),'cross order');
check(!verify($wire,$candidate,version:3),'wrong version');
check(!verify($wire,$candidate,digestOverride:str_repeat('0',64)),'wrong report fingerprint');
check(!verify($wire,$candidate,key:'f425-other-synthetic-key-long-enough-to-sign'),'wrong key');

foreach([
    ['review_amount_cents'=>1],
    ['tenant_ref'=>'org-other-f425'],
    ['order_ref'=>'order-other-f425'],
    ['report_version'=>3],
    ['period'=>'2026-10'],
    ['candidate_sha256'=>str_repeat('a',64)],
    ['acknowledged_report_sha256'=>str_repeat('0',64)],
    ['can_issue_invoice'=>true],
    ['can_charge'=>true],
    ['can_write_psp'=>true],
    ['can_grant_service'=>true],
    ['expires_at'=>'2026-12-05T13:00:00Z'],
    ['issued_at'=>'2026-12-05T11:00:00Z'],
    ['environment'=>'production'],
    ['unreviewed_claim'=>'charge'],
] as $change) {
    $bad=$wire;$bad['payload']=array_replace($bad['payload'],$change);
    check(!verify($bad,$candidate),'tampered payload '.key($change));
}
$bad=$wire;$bad['mac']=str_repeat('f',64);
check(!verify($bad,$candidate),'forged MAC');
$bad=$wire;$bad['helper_nonce']='inject';
check(!verify($bad,$candidate),'extra envelope top-level field');

$changed=$candidate;$changed['informational_management_fee_cents']=17_000;
check(!verify($wire,$changed),'changed amount in source');
$changed=$candidate;$changed['proposed_due_date']='2026-12-09';
check(!verify($wire,$changed),'changed due date in source');
$changed=$candidate;$changed['period']='2026-10';
check(!verify($wire,$changed),'changed period in source');
$changed=$candidate;$changed['can_issue_invoice']=true;
check(!verify($wire,$changed),'unsafe source cannot be re-signed');

$changed=$candidate;$changed['real_invoice_authorized']=true;
denyIssue(static fn()=>Wire::issue($changed,TENANT,ORDER,2,$digest,ISSUED,SIGNING_KEY),'invoice flag');
$changed=$candidate;$changed['sandbox_psp_write_authorized']=true;
denyIssue(static fn()=>Wire::issue($changed,TENANT,ORDER,2,$digest,ISSUED,SIGNING_KEY),'PSP flag');
$changed=$candidate;$changed['unverified_customer_consent']=true;
denyIssue(static fn()=>Wire::issue($changed,TENANT,ORDER,2,$digest,ISSUED,SIGNING_KEY),'extra claim');
$changed=$candidate;$changed['proposed_due_date']='2026-11-01';
denyIssue(static fn()=>Wire::issue($changed,TENANT,ORDER,2,$digest,ISSUED,SIGNING_KEY),'due before close');
$changed=$candidate;$changed['period_end']='2026-11-29';
denyIssue(static fn()=>Wire::issue($changed,TENANT,ORDER,2,$digest,ISSUED,SIGNING_KEY),'wrong month end');
$changed=$candidate;$changed['informational_management_fee_cents']=17_000;
denyIssue(static fn()=>Wire::issue($changed,TENANT,ORDER,2,$digest,ISSUED,SIGNING_KEY),
    'two channels cannot be below R$340 management total');
denyIssue(static fn()=>Wire::issue(
    $candidate,TENANT,ORDER,2,$digest,'2026-11-30T23:59:59Z',SIGNING_KEY
),'not signed before month is closed');

denyIssue(static fn()=>Wire::issue($candidate,TENANT,ORDER,0,$digest,ISSUED,SIGNING_KEY),'zero report version');
denyIssue(static fn()=>Wire::issue($candidate,TENANT,ORDER,2,$digest,'2026-11-31T12:00:00Z',SIGNING_KEY),'nonexistent date');
denyIssue(static fn()=>Wire::issue($candidate,TENANT,ORDER,2,$digest,ISSUED,'short'),'weak key');

// Crucial limitation: a bare HMAC does NOT prevent replay. Demonstrate it,
// rather than silently treating a valid signature as a consumption record.
check(verify($wire,$candidate)&&verify($wire,$candidate),
    'replay requires future durable state and idempotency gate');
echo "PASS F425: {$checks} HMAC monthly-review scope, tamper, expiry and NO-GO checks; NO invoice, PSP or claim consumption.\n";
