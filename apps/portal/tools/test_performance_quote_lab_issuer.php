<?php
declare(strict_types=1);

require_once __DIR__ . '/../app/Homologation/Commerce/ApprovedOfferCatalog.php';
require_once __DIR__ . '/../app/Homologation/Commerce/PortalPerformanceQuoteLabIssuer.php';

use App\Homologation\Commerce\ApprovedOfferCatalog;
use App\Homologation\Commerce\PortalPerformanceQuoteLabIssuer as Issuer;
function sameLab(mixed $expected, mixed $actual, string $label): void {
    if ($expected !== $actual) throw new RuntimeException($label . ' mismatch');
}
function rejectedLab(callable $call, string $label): void {
    try { $call(); } catch (InvalidArgumentException | RuntimeException) { return; }
    throw new RuntimeException('Expected rejection: ' . $label);
}
$catalog = new ApprovedOfferCatalog();
$secret = str_repeat('Q', 48); // CI-only fixture
$tenant = 'tenant_test_12345678';
$key = 'k_lab001';
$nonce = 'nonce_portal_1234567890abcdef';
$issued = 1794660000;
$expire = $issued + 120;
$issue = static fn(array $budget, string $n = 'nonce_portal_1234567890abcdef') =>
    Issuer::issue($catalog, $budget, $tenant, $key, $n, $issued, $expire, $secret);
$one = $issue(['google_ads' => 70000]);
sameLab(14000, $one['quote']['estimated_management_fee_cents'], '20 percent minimum');
sameLab(false, $one['quote']['real_charge_enabled'], 'no real financial activity');
sameLab(true, $one['quote']['media_paid_directly_to_platforms'], 'media separate');
sameLab($catalog->version(), $one['quote']['catalog_version'], 'authoritative catalog');
sameLab('imagine-performance-finance-preview', $one['audience'], 'audience');
sameLab(64, strlen($one['signature']), 'HMAC SHA256 hex');
sameLab($one['signature'], $issue(['google_ads' => 70000])['signature'], 'deterministic same message');
$two = $issue(['meta_ads' => 70003, 'google_ads' => 70000]);
sameLab(28001, $two['quote']['estimated_management_fee_cents'], 'two channels rounded once');
$v2 = Issuer::issue($catalog, ['google_ads' => 70000], $tenant, $key, $nonce, $issued, $expire, $secret, '2026-11');
sameLab('2026-11', $v2['billing_period'], 'v2 signed month');
sameLab(14000, $v2['quote']['estimated_management_fee_cents'], 'v2 preserves approved 20 percent');
$v2Different = Issuer::issue($catalog, ['google_ads' => 70000], $tenant, $key, $nonce, $issued, $expire, $secret, '2026-12');
if (hash_equals($v2['signature'], $v2Different['signature'])) {
    throw new RuntimeException('Billing month not bound into HMAC signature.');
}
if (hash_equals($one['signature'], $v2['signature'])) {
    throw new RuntimeException('Version separation must change HMAC.');
}
rejectedLab(fn() => Issuer::issue($catalog, ['google_ads' => 70000], $tenant, $key, $nonce, $issued, $expire, $secret, '2026-13'), 'invalid billing month');
rejectedLab(fn() => Issuer::issue($catalog, ['google_ads' => 70000], $tenant, $key, $nonce, $issued, $expire, $secret, ''), 'missing billing month');

rejectedLab(fn() => $issue(['google_ads' => 69999]), 'below minimum');
rejectedLab(fn() => $issue(['google_ads' => 100000001]), 'above Portal quote cap');
rejectedLab(fn() => Issuer::issue($catalog, ['google_ads' => 70000], $tenant, $key, $nonce, $issued, $issued + 301, $secret), 'excess lifetime');
rejectedLab(fn() => Issuer::issue($catalog, ['google_ads' => 70000], $tenant, $key, $nonce, $issued, $expire, 'weak'), 'weak key');
rejectedLab(fn() => Issuer::issue($catalog, ['google_ads' => 70000], 'bad', $key, $nonce, $issued, $expire, $secret), 'bad tenant');
putenv('PORTAL_PERF_SIGN_LAB=0');
rejectedLab(fn() => $issue(['google_ads' => 70000]), 'explicit opt-in required');
putenv('PORTAL_PERF_SIGN_LAB=1');
putenv('APP_ENV=production');
rejectedLab(fn() => $issue(['google_ads' => 70000]), 'production cannot issue lab quotes');
echo "OK: Portal signed Performance quote issuer testing-only checks\n";
