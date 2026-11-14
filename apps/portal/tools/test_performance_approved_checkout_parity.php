<?php
declare(strict_types=1);
/**
 * F419 cross-branch parity, ZERO side effects.
 *
 * Compare F419 offline quote/review against pinned, independently reviewed
 * sandbox checkout contract in PR #757. The checkout fixture is read from
 * a separate exact-SHA git checkout in CI, never a live backend or VPS.
 */
if (PHP_SAPI !== 'cli' || $argc !== 2
    || !is_file($argv[1])
    || basename($argv[1]) !== 'PerformancePostpaidPolicy.php') {
    fwrite(STDERR, "Use a reviewed, pinned PR757 policy source path.\n");
    exit(2);
}
require __DIR__.'/../app/Domain/Performance/ActivationPostpaidPreview.php';
require $argv[1];

use App\Domain\Performance\ActivationPostpaidPreview as Preview;
use App\Homologation\Commerce\PerformancePostpaidPolicy as Checkout;

function same(mixed $value, mixed $expected, string $why): void {
    if ($value !== $expected) {
        throw new RuntimeException('F419 pricing divergence: '.$why);
    }
}
same(Preview::POLICY_VERSION, Checkout::VERSION, 'policy version');
same(Preview::ACTIVATION_PER_CHANNEL_CENTS, Checkout::ACTIVATION_CENTS, 'setup fee');
same(Preview::MANAGEMENT_MINIMUM_PER_CHANNEL_CENTS, Checkout::MONTHLY_MINIMUM_CENTS, 'management floor');
same(Preview::MANAGEMENT_BASIS_POINTS, 2_000, 'basis points');
$eligibility=['meta_ads'=>true,'google_ads'=>true,'chatgpt_ads'=>true];
$cases=[
    ['meta_ads'=>0],
    ['meta_ads'=>35_000],
    ['meta_ads'=>70_000],
    ['meta_ads'=>85_000],
    ['meta_ads'=>85_003],
    ['google_ads'=>400_000],
    ['meta_ads'=>35_000,'google_ads'=>400_000],
    ['meta_ads'=>70_000,'google_ads'=>150_000],
    ['meta_ads'=>0,'google_ads'=>400_000],
    ['meta_ads'=>35_000,'google_ads'=>400_000,'chatgpt_ads'=>0],
];
foreach ($cases as $media) {
    $channels=array_keys($media);
    $quote=Preview::activationQuote($channels, $eligibility);
    $a=Preview::monthlyReview($channels,'2026-10-01','2026-11',$media,'SIM-F419-PARITY');
    $b=Checkout::fullMonth($channels,$media);
    same($quote['activation_due_now_cents'],$b['activation_total_cents'],'activation per channel');
    same($a['informational_full_month_management_fee_cents'],$b['management_fee_cents'],'sum per-channel max(20%,170)');
    same($a['postpaid_policy_version'],$b['policy_version'],'monthly policy version');
    same($a['minimum_management_fee_cents'],Checkout::MONTHLY_MINIMUM_CENTS,'monthly minimum');
    same($a['first_month_partial_contract_review_required'],false,'full calendar month');
    same($a['real_invoice_authorized'],false,'no real invoices');
    same($b['automatic_charge_enabled'],false,'no checkout writes');
    same($quote['checkout_enabled'],false,'no simulated checkout activation');
    $byChannel=[];
    foreach ($a['channels'] as $item) $byChannel[$item['channel']]=$item;
    foreach ($channels as $channel) {
        same(
            $byChannel[$channel]['informational_full_month_management_fee_cents'],
            $b['channels'][$channel]['management_fee_cents'],
            'channel-specific fee '.$channel
        );
        same(
            $byChannel[$channel]['minimum_applied'],
            $b['channels'][$channel]['minimum_applied'],
            'channel minimum flag '.$channel
        );
    }
}
echo 'PASS F419: '.count($cases).' synthetic full-month scenarios agree with pinned PR757 checkout; no PSP or customer data.'."\n";
