<?php
declare(strict_types=1);

namespace App\Homologation\Commerce;

use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;

/**
 * F424: finite, deterministic, review-only monthly Performance draft.
 *
 * The month must have closed and the client must have acknowledged a report.
 * This is NOT an invoice, mandate, tax document, trusted media-statement
 * verification, payment instruction or entitlement to query the Asaas API.
 * Tenant/order authorization and row locks belong to a later DB integration.
 */
final class PerformanceMonthlyCloseCandidate
{
    private const TZ = 'America/Sao_Paulo';

    /**
     * @param array<string,mixed> $frozenOffer V5 quote from server, not browser
     * @param array<string,mixed> $acknowledgedReport Synthetic F422 report
     * @return array<string,mixed> Informational proposal, cannot be charged
     */
    public static function inspect(
        array $frozenOffer,
        array $acknowledgedReport,
        string $clientDecision,
        string $verifiedActivationDate,
        string $period,
        string $trustedToday,
        string $proposedDueDate
    ): array {
        if (($frozenOffer['product']??null)!=='performance'
            || ($frozenOffer['pricing_policy']??null)!==PerformancePostpaidPolicy::VERSION
            || ($frozenOffer['real_charge_enabled']??null)!==false
            || ($frozenOffer['real_postpaid_invoice_authorized']??null)!==false
            || ($frozenOffer['media_paid_directly_to_platforms']??null)!==true
            || ($frozenOffer['minimum_management_fee_per_channel_cents']??null)!==17_000
            || ($frozenOffer['activation_per_channel_cents']??null)!==49_000
            || ($frozenOffer['gateway']??null)!=='asaas'
            || ($acknowledgedReport['policy_version']??null)!==PerformancePostpaidPolicy::VERSION
            || ($acknowledgedReport['period']??null)!==$period
            || ($acknowledgedReport['currency']??null)!=='BRL'
            || ($acknowledgedReport['real_invoice_authorized']??null)!==false
            || ($acknowledgedReport['status']??null)!=='postpaid_requires_finance_review'
            || $clientDecision!=='acknowledged'
            || !is_string($acknowledgedReport['evidence_ref']??null)
            || preg_match('/\ASIM-[A-Z0-9][A-Z0-9-]{2,76}\z/D',$acknowledgedReport['evidence_ref'])!==1) {
            throw new InvalidArgumentException('Unreviewed, unversioned or non-synthetic financial terms');
        }
        $start = self::date($verifiedActivationDate);
        $today = self::date($trustedToday);
        $due = self::date($proposedDueDate);
        if (!preg_match('/\A20[0-9]{2}-(0[1-9]|1[0-2])\z/D', $period)) {
            throw new InvalidArgumentException('Invalid calendar competence');
        }
        $first = self::date($period.'-01');
        $end = $first->modify('last day of this month');
        if ($first->format('Y-m') < $start->format('Y-m')
            || $end >= $today
            || $due <= $end
            || $due < $today) {
            throw new InvalidArgumentException('Unclosed period, future activation or invalid due date');
        }
        // No automatic floor proration exists for an activation partway
        // through its first month. Do NOT treat that month as a full month.
        if ($first->format('Y-m')===$start->format('Y-m')
            && $start->format('d')!=='01') {
            throw new InvalidArgumentException('First partial month needs explicit contract policy');
        }
        $budgets=$frozenOffer['media_by_channel_cents']??null;
        $rows=$acknowledgedReport['channels']??null;
        if (!is_array($budgets) || $budgets===[] || !is_array($rows)
            || count($budgets)>2
            || array_diff(array_keys($budgets),['meta_ads','google_ads'])!==[]
            || array_diff(array_keys($rows),array_keys($budgets))!==[]
            || array_diff(array_keys($budgets),array_keys($rows))!==[]) {
            throw new InvalidArgumentException('Missing or extra contracted media channel');
        }
        $measured=[];
        foreach ($rows as $channel=>$row) {
            if (!is_array($row) || !is_int($row['actual_cents']??null)
                || $row['actual_cents']<=0
                || !is_int($row['planned_cents']??null)
                || !is_int($budgets[$channel]??null)
                || $row['planned_cents']!==$budgets[$channel]
                || !is_int($row['management_fee_cents']??null)) {
                // A zero-spend active month may involve suspension and
                // requires human validation before a floor is requested.
                throw new InvalidArgumentException('Measured media or active-channel evidence unresolved');
            }
            $measured[$channel]=$row['actual_cents'];
        }
        $calculated=PerformancePostpaidPolicy::fullMonth(array_keys($budgets),$measured);
        if (($frozenOffer['activation_total_cents']??null)!==$calculated['activation_total_cents']
            || ($frozenOffer['amount_now_cents']??null)!==$calculated['activation_total_cents']
            || ($acknowledgedReport['activation_paid_cents']??null)!==$calculated['activation_total_cents']
            || ($acknowledgedReport['actual_management_fee_cents']??null)!==$calculated['management_fee_cents']) {
            throw new InvalidArgumentException('Postpaid report amount or activation was altered');
        }
        foreach ($calculated['channels'] as $channel=>$expected) {
            if ($rows[$channel]['management_fee_cents']!==$expected['management_fee_cents']
                || ($rows[$channel]['minimum_applied']??null)!==$expected['minimum_applied']
                || ($rows[$channel]['media_cents']??null)!==$expected['media_cents']) {
                throw new InvalidArgumentException('Per-channel floor or spend altered');
            }
        }
        return [
            'schema'=>'imagine.portal.performance.monthly-close-review-only.v1',
            'policy_version'=>PerformancePostpaidPolicy::VERSION,
            'period'=>$period,
            'period_end'=>$end->format('Y-m-d'),
            'proposed_due_date'=>$proposedDueDate,
            'charge_key_candidate'=>'performance-'.$period,
            'channel_count'=>count($calculated['channels']),
            'currency'=>'BRL',
            'informational_management_fee_cents'=>$calculated['management_fee_cents'],
            'minimum_per_channel_cents'=>PerformancePostpaidPolicy::MONTHLY_MINIMUM_CENTS,
            'activation_excluded_from_monthly_fee'=>true,
            'media_paid_directly_to_platforms'=>true,
            'sandbox_evidence_only'=>true,
            'real_invoice_authorized'=>false,
            'sandbox_psp_write_authorized'=>false,
            'fiscal_document_authorized'=>false,
            'production_authorized'=>false,
            'reason'=>'review_candidate_requires_external_media_evidence_and_financial_go',
        ];
    }

    private static function date(string $input): DateTimeImmutable
    {
        if (preg_match('/\A20[0-9]{2}-(0[1-9]|1[0-2])-[0-3][0-9]\z/D',$input)!==1) {
            throw new InvalidArgumentException('Invalid ISO calendar date');
        }
        $date=DateTimeImmutable::createFromFormat('!Y-m-d',$input,new DateTimeZone(self::TZ));
        if (!$date || $date->format('Y-m-d')!==$input) {
            throw new InvalidArgumentException('Nonexistent calendar day');
        }
        return $date;
    }
}
