<?php
declare(strict_types=1);
/**
 * F430: synthetic F424→F425→F427 signed review bridge.
 * Writes a PostgreSQL test fixture, NOT a migration or payment request.
 */
if (getenv('APP_ENV')!=='testing' || getenv('GITHUB_ACTIONS')!=='true') {
    throw new RuntimeException('F430 must run only as isolated CI fixture');
}
$output=$argv[1]??'';
if (!is_string($output)
    || preg_match('/\A\/tmp\/f430-[A-Za-z0-9._-]+\.sql\z/D',$output)!==1) {
    throw new RuntimeException('F430 requires a private disposable /tmp SQL path');
}
require __DIR__.'/../app/Homologation/Commerce/ApprovedOfferCatalog.php';
require __DIR__.'/../app/Homologation/Commerce/PerformanceSettlement.php';
require __DIR__.'/../app/Homologation/Commerce/PerformanceMonthlyCloseCandidate.php';
require __DIR__.'/../app/Homologation/Commerce/PerformanceMonthlyCloseReviewEnvelope.php';

use App\Homologation\Commerce\ApprovedOfferCatalog;
use App\Homologation\Commerce\PerformanceSettlement;
use App\Homologation\Commerce\PerformanceMonthlyCloseCandidate;
use App\Homologation\Commerce\PerformanceMonthlyCloseReviewEnvelope;

$tz=new DateTimeZone('America/Sao_Paulo');
$today=new DateTimeImmutable('now',$tz);
$prev=$today->modify('first day of last month')->setTime(0,0);
$period=$prev->format('Y-m');
$activated=$prev->modify('first day of previous month')->format('Y-m-d');
$todayString=$today->format('Y-m-d');
$due=$today->modify('+10 days')->format('Y-m-d');
$issued=gmdate('Y-m-d\TH:i:s\Z');

$quote=(new ApprovedOfferCatalog())->quote(
    'performance',['google_ads'=>100_000,'meta_ads'=>70_000]
);
$report=PerformanceSettlement::calculate(
    $quote,$period,['google_ads'=>400_000,'meta_ads'=>35_000],
    'SIM-F430-HMAC-BRIDGE-PG18'
);
$candidate=PerformanceMonthlyCloseCandidate::inspect(
    $quote,$report,'acknowledged',$activated,$period,$todayString,$due
);
$reportDigest=hash('sha256',json_encode($report,JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES));
$key='f430-disposable-only-hmac-signing-key-never-operational';
$tenant='org-f430-bridge';
$order='order-f430-bridge';
$version=2;
$wire=PerformanceMonthlyCloseReviewEnvelope::issue(
    $candidate,$tenant,$order,$version,$reportDigest,$issued,$key
);
if (!PerformanceMonthlyCloseReviewEnvelope::verify(
    $wire,$candidate,$tenant,$order,$version,$reportDigest,$issued,$key
)) {
    throw new RuntimeException('CRITICAL: F425 real synthetic HMAC failed before DB');
}
$forged=$wire;$forged['payload']['review_amount_cents']++;
if (PerformanceMonthlyCloseReviewEnvelope::verify(
    $forged,$candidate,$tenant,$order,$version,$reportDigest,$issued,$key
)) {
    throw new RuntimeException('CRITICAL: tampered synthetic HMAC passed');
}
$payload=$wire['payload'];
if ($payload['can_charge']!==false || $payload['can_issue_invoice']!==false
    || $payload['can_write_psp']!==false || $payload['can_grant_service']!==false
    || $payload['period']!==$period
    || $payload['review_amount_cents']!==$candidate['informational_management_fee_cents']) {
    throw new RuntimeException('CRITICAL: financial authorization escaped F425');
}
$envelopeHash=hash('sha256',json_encode($wire,JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES));
$periodStart=$period.'-01';

/** Only strict, hex/opaque synthetic tokens enter the disposable SQL fixture. */
function literal(string $value):string
{
    if (preg_match('/\A[A-Za-z0-9._:-]{3,128}\z/D',$value)!==1) {
        throw new RuntimeException('Unsafe synthetic SQL fixture');
    }
    return "'".$value."'";
}
$fields=[
    ':HASH:'=>literal($envelopeHash),
    ':MAC:'=>literal($wire['mac']),
    ':TENANT:'=>literal($tenant),
    ':ORDER:'=>literal($order),
    ':VERSION:'=>strval($version),
    ':REPORT_SHA:'=>literal($payload['acknowledged_report_sha256']),
    ':CANDIDATE_SHA:'=>literal($payload['candidate_sha256']),
    ':PERIOD:'=>literal($periodStart),
    ':AMOUNT:'=>strval($payload['review_amount_cents']),
    ':ISSUED:'=>literal($payload['issued_at']),
    ':EXPIRES:'=>literal($payload['expires_at']),
];

$template=<<<'SQL'
\set ON_ERROR_STOP on
-- F430 values originate in PHP F424/F425 and all are SYNTHETIC.
DO $guard$
BEGIN
 IF current_database()<>'portal_purchase_rehearsal' THEN
  RAISE EXCEPTION 'CRITICAL: F430 cannot run outside disposable lab';
 END IF;
END $guard$;

SET SESSION AUTHORIZATION perf_monthly_f427_issuer;
INSERT INTO perf_monthly_f427.monthly_reviews(
 envelope_hash,envelope_mac,tenant_ref,order_ref,report_version,
 report_sha256,candidate_sha256,period_start,amount_cents,issued_at,expires_at)
VALUES(
 :HASH:,:MAC:,:TENANT:,:ORDER:,:VERSION:,
 :REPORT_SHA:,:CANDIDATE_SHA:,:PERIOD:,:AMOUNT:,:ISSUED:,:EXPIRES:);
RESET SESSION AUTHORIZATION;

SET SESSION AUTHORIZATION perf_monthly_f427_worker;
DO $exercise$
BEGIN
 IF perf_monthly_f427.consume_review(
  :HASH:,repeat('0',64),:TENANT:,:ORDER:,:PERIOD:,
  :VERSION:,:REPORT_SHA:,:CANDIDATE_SHA:,:AMOUNT:)
 THEN RAISE EXCEPTION 'CRITICAL: wrong HMAC consumed F425 review'; END IF;

 IF perf_monthly_f427.consume_review(
  :HASH:,:MAC:,'org-other-bridge',:ORDER:,:PERIOD:,
  :VERSION:,:REPORT_SHA:,:CANDIDATE_SHA:,:AMOUNT:)
 THEN RAISE EXCEPTION 'CRITICAL: wrong tenant consumed F425 review'; END IF;

 IF perf_monthly_f427.consume_review(
  :HASH:,:MAC:,:TENANT:,:ORDER:,:PERIOD:,
  :VERSION:,:REPORT_SHA:,repeat('f',64),:AMOUNT:)
 THEN RAISE EXCEPTION 'CRITICAL: altered candidate consumed review'; END IF;

 IF perf_monthly_f427.consume_review(
  :HASH:,:MAC:,:TENANT:,:ORDER:,:PERIOD:,
  :VERSION:,:REPORT_SHA:,:CANDIDATE_SHA:,:AMOUNT:+1)
 THEN RAISE EXCEPTION 'CRITICAL: amount tamper consumed review'; END IF;

 IF NOT perf_monthly_f427.consume_review(
  :HASH:,:MAC:,:TENANT:,:ORDER:,:PERIOD:,
  :VERSION:,:REPORT_SHA:,:CANDIDATE_SHA:,:AMOUNT:)
 THEN RAISE EXCEPTION 'CRITICAL: valid F425 envelope not consumed in F427'; END IF;

 IF perf_monthly_f427.consume_review(
  :HASH:,:MAC:,:TENANT:,:ORDER:,:PERIOD:,
  :VERSION:,:REPORT_SHA:,:CANDIDATE_SHA:,:AMOUNT:)
 THEN RAISE EXCEPTION 'CRITICAL: F425/F427 signed envelope replay accepted'; END IF;
END $exercise$;
RESET SESSION AUTHORIZATION;

DO $audit$
DECLARE n bigint;
BEGIN
 SELECT count(*) INTO n FROM perf_monthly_f427.monthly_reviews
 WHERE state='consumed' AND envelope_hash=:HASH:
 AND envelope_mac=:MAC: AND consumed_at IS NOT NULL;
 IF n<>1 THEN RAISE EXCEPTION 'CRITICAL: exactly one persisted F425 HMAC consumption required'; END IF;
 SELECT count(*) INTO n FROM perf_monthly_f427.monthly_reviews WHERE state='consumed';
 IF n<>1 THEN RAISE EXCEPTION 'CRITICAL: extra monthly review was consumed'; END IF;
END $audit$;
\echo 'PASS F430: actual F425 synthetic HMAC, F424 report and F427 PostgreSQL18 single-use binding; no billing.'
SQL;
$sql=strtr($template,$fields);
if (str_contains($sql,':HASH:') || str_contains($sql,':MAC:')) {
    throw new RuntimeException('Unbound SQL test fixture');
}
if (file_put_contents($output,$sql,LOCK_EX)===false || !chmod($output,0600)) {
    throw new RuntimeException('Cannot securely write disposable SQL fixture');
}
echo "PASS F430 PHP: validated signed review and emitted disposable PostgreSQL18 fixture; no payments.\n";
