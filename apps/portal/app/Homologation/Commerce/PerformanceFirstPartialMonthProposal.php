<?php
declare(strict_types=1);

namespace App\Homologation\Commerce;

use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;

/**
 * F429: contract PROPOSAL for the first partial month, never an invoice.
 *
 * Formula, NOT a ratified agreement:
 *   per channel max(20% of statement-aligned actual BRL media,
 *                   R$170 * calendar days active / calendar days in month).
 * Half-up cent rounding is applied separately to each component/channel.
 * Suspensions, zero spend, uncertain activation dates and vendor statements
 * without official verification remain human review/no billing.
 */
final class PerformanceFirstPartialMonthProposal
{
    private const MINIMUM_CENTS=17_000;

    /**
     * @param array<string,mixed> $offer Server-owned frozen quote
     * @param array<string,mixed> $media F428 offline evidence review
     * @param array<string,string> $activationDates Trust-bound calendar dates
     * @param array<string,string> $states 'clear' only; any uncertainty denied
     * @return array<string,mixed> Pure informational review; no billing grant
     */
    public static function inspect(
        array $offer,
        array $media,
        array $activationDates,
        array $states,
        string $period,
        string $trustedToday
    ): array {
        if (($offer['product']??null)!=='performance'
            || ($offer['real_charge_enabled']??null)!==false
            || ($offer['real_postpaid_invoice_authorized']??null)!==false
            || ($offer['media_paid_directly_to_platforms']??null)!==true
            || ($offer['minimum_management_fee_per_channel_cents']??null)!==self::MINIMUM_CENTS
            || ($media['schema']??null)!=='imagine.portal.performance.synthetic-media-review.v1'
            || ($media['period']??null)!==$period
            || ($media['currency']??null)!=='BRL'
            || ($media['amounts_match_offline']??null)!==true
            || ($media['discrepancies']??null)!==[]
            || ($media['evidence_origin']??null)!=='offline_synthetic_fixture'
            || ($media['official_media_evidence_verified']??null)!==false
            || ($media['can_charge']??null)!==false
            || ($media['can_issue_invoice']??null)!==false
            || ($media['can_write_psp']??null)!==false) {
            throw new InvalidArgumentException('Unreviewed media or unsafe commercial offer');
        }
        if (preg_match('/\A20[0-9]{2}-(0[1-9]|1[0-2])\z/D',$period)!==1) {
            throw new InvalidArgumentException('Invalid competence');
        }
        $tz=new DateTimeZone('America/Sao_Paulo');
        $start=self::date($period.'-01',$tz);
        $end=$start->modify('last day of this month');
        if (self::date($trustedToday,$tz)<=$end) {
            throw new InvalidArgumentException('Unclosed media month');
        }
        $budgets=$offer['media_by_channel_cents']??null;
        $rows=$media['channels']??null;
        if (!is_array($budgets) || $budgets===[] || count($budgets)>2
            || !is_array($rows) || !self::sameChannels($budgets,$rows)
            || !self::sameChannels($budgets,$activationDates)
            || !self::sameChannels($budgets,$states)) {
            throw new InvalidArgumentException('Missing or additional contracted channels');
        }
        $days=(int)$end->format('d');
        $result=[];$total=0;$hasPartial=false;
        foreach ($budgets as $channel=>$planned) {
            if (!in_array($channel,['google_ads','meta_ads'],true)
                || !is_int($planned) || $planned<=0
                || !is_string($activationDates[$channel])
                || $states[$channel]!=='clear'
                || !is_array($rows[$channel])
                || ($rows[$channel]['matched']??null)!==true
                || !is_int($rows[$channel]['statement_spend_cents']??null)
                || !is_int($rows[$channel]['report_spend_cents']??null)
                || $rows[$channel]['statement_spend_cents']<=0
                || $rows[$channel]['statement_spend_cents']>100_000_000
                || $rows[$channel]['statement_spend_cents']!==$rows[$channel]['report_spend_cents']) {
                throw new InvalidArgumentException('Missing activation, suspended channel or unconfirmed spend');
            }
            $active=self::date($activationDates[$channel],$tz);
            if ($active<$start || $active>$end) {
                throw new InvalidArgumentException('Not a first-month activation for this channel');
            }
            $activeDays=$days-(int)$active->format('d')+1;
            $hasPartial=$hasPartial || $activeDays<$days;
            $floor=self::roundRatio(self::MINIMUM_CENTS*$activeDays,$days);
            $mediaCents=$rows[$channel]['statement_spend_cents'];
            $percent=self::roundRatio($mediaCents*20,100);
            $fee=max($floor,$percent);
            $total+=$fee;
            if ($total>100_000_000) {
                throw new InvalidArgumentException('Unreasonable proposed management total');
            }
            $result[$channel]=[
                'activation_date'=>$activationDates[$channel],
                'days_in_month'=>$days,
                'active_calendar_days'=>$activeDays,
                'aligned_media_cents'=>$mediaCents,
                'proportional_minimum_cents'=>$floor,
                'twenty_percent_media_cents'=>$percent,
                'proposed_management_cents'=>$fee,
                'minimum_applied'=>$floor>=$percent,
            ];
        }
        if (!$hasPartial) {
            throw new InvalidArgumentException('This calculation is reserved for first partial months');
        }
        return [
            'schema'=>'imagine.portal.performance.partial-month-contract-proposal.v1',
            'period'=>$period,
            'currency'=>'BRL',
            'channels'=>$result,
            'informational_management_total_cents'=>$total,
            'rounding'=>'half_up_integer_cents_per_channel',
            'status'=>'contract_proposal_only',
            'contract_policy_ratified'=>false,
            'official_media_evidence_verified'=>false,
            'suspension_policy_ratified'=>false,
            'real_invoice_authorized'=>false,
            'can_charge'=>false,
            'can_write_psp'=>false,
            'can_activate_ads'=>false,
            'reason'=>'partial_month_floors_and_vendor_evidence_require_contract_and_financial_go',
        ];
    }

    private static function roundRatio(int $numerator,int $denominator): int
    {
        if ($denominator<=0 || $numerator<0) {
            throw new InvalidArgumentException('Invalid ratio');
        }
        return intdiv($numerator*2+$denominator,2*$denominator);
    }

    private static function sameChannels(array $a,array $b): bool
    {
        $ka=array_keys($a);$kb=array_keys($b);
        sort($ka,SORT_STRING);sort($kb,SORT_STRING);
        return $ka===$kb;
    }

    private static function date(string $raw,DateTimeZone $tz): DateTimeImmutable
    {
        if (preg_match('/\A20[0-9]{2}-(0[1-9]|1[0-2])-[0-3][0-9]\z/D',$raw)!==1) {
            throw new InvalidArgumentException('Invalid ISO activation or trusted date');
        }
        $v=DateTimeImmutable::createFromFormat('!Y-m-d',$raw,$tz);
        if (!$v || $v->format('Y-m-d')!==$raw) {
            throw new InvalidArgumentException('Nonexistent calendar date');
        }
        return $v;
    }
}
