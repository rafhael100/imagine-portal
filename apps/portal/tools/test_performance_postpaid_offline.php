<?php
declare(strict_types=1);
/** F408 standalone smoke; deliberately no PSP, DB, Laravel or network. */
require __DIR__.'/../app/Domain/Performance/ActivationPostpaidPreview.php';

use App\Domain\Performance\ActivationPostpaidPreview as Candidate;

function ensure(bool $condition, string $why): void {
    if (!$condition) throw new \RuntimeException($why);
}
function denies(callable $operation): void {
    try { $operation(); } catch (\InvalidArgumentException) { return; }
    throw new \RuntimeException('Unsafe financial candidate accepted');
}
$eligibility=['meta_ads'=>true,'google_ads'=>true,'chatgpt_ads'=>false];
$quote=Candidate::activationQuote(['meta_ads','google_ads'],$eligibility);
ensure($quote['activation_due_now_cents']===98000,'Two channels must activate for R$980');
ensure($quote['management_basis_points']===2000,'Monthly management must be 20 percent');
ensure($quote['minimum_management_fee_cents']===null,'Missing approved management minimum must remain unresolved');
ensure($quote['checkout_enabled']===false && $quote['asaas_write_enabled']===false,'Real checkout disabled');
denies(static fn()=>Candidate::activationQuote(['chatgpt_ads'],$eligibility));
denies(static fn()=>Candidate::activationQuote(['meta_ads','meta_ads'],$eligibility));
$review=Candidate::monthlyReview(['meta_ads','google_ads'],'2026-11-14','2026-11',
    ['google_ads'=>0,'meta_ads'=>15000],'SIM-MEDIA-001');
ensure($review['start_date']==='2026-11-14','First month must begin on activation date');
ensure($review['end_date']==='2026-11-30','Close civil month');
ensure($review['informational_management_20pct_cents']===3000,'Only verified-actual candidate may be computed');
ensure($review['real_invoice_authorized']===false,'No invoice without evidence and minimum decision');
denies(static fn()=>Candidate::monthlyReview(['meta_ads'],'2026-11-14','2026-10',['meta_ads'=>500],'SIM-BAD-001'));
echo "PASS F408: R$490 activation/channel + 20pct postpaid candidate; NO-GO billing.\n";
