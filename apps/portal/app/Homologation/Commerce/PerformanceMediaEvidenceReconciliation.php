<?php
declare(strict_types=1);

namespace App\Homologation\Commerce;

use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;

/**
 * F428: deterministic, synthetic-only reconciliation of media statement rows.
 *
 * This is NOT OAuth authentication, official Google/Meta evidence, account
 * consent, financial signoff, invoice authorization, or a PSP API adapter.
 * Source adapters must authenticate vendor data and map account ownership
 * before this normalization can be reused with non-synthetic input.
 */
final class PerformanceMediaEvidenceReconciliation
{
    private const CHANNELS=['google_ads','meta_ads'];
    private const SYNTHETIC='offline_synthetic_fixture';
    private const TZ='America/Sao_Paulo';

    /**
     * @param array<string,mixed> $offer Server-side frozen V5 offer
     * @param array<string,mixed> $report Client-acknowledged F422 report
     * @param array<string,string> $accounts Trusted account mapping, not HTTP
     * @param array<string,mixed> $statements Offline fixtures indexed by channel
     * @return array<string,mixed> Pure review report. All billing flags are FALSE.
     */
    public static function inspect(
        array $offer,
        array $report,
        string $trustedTenant,
        string $trustedOrder,
        array $accounts,
        array $statements,
        string $period,
        string $trustedNowUtc
    ): array {
        if (preg_match('/\A[A-Za-z0-9._:-]{3,128}\z/D',$trustedTenant)!==1
            || preg_match('/\A[A-Za-z0-9._:-]{3,128}\z/D',$trustedOrder)!==1
            || preg_match('/\A20[0-9]{2}-(0[1-9]|1[0-2])\z/D',$period)!==1
            || ($offer['product']??null)!=='performance'
            || ($offer['real_charge_enabled']??null)!==false
            || ($report['period']??null)!==$period
            || ($report['currency']??null)!=='BRL'
            || ($report['real_invoice_authorized']??null)!==false
        ) {
            throw new InvalidArgumentException('Not a scoped, non-billable Performance statement');
        }
        $budgets=$offer['media_by_channel_cents']??null;
        $reported=$report['channels']??null;
        if (!is_array($budgets) || $budgets===[]
            || count($budgets)>2 || !is_array($reported)
            || !self::sameChannels($budgets,$reported)
            || !self::sameChannels($budgets,$accounts)
            || !self::sameChannels($budgets,$statements)) {
            throw new InvalidArgumentException('Evidence must match exactly the contracted channels');
        }
        $tz=new DateTimeZone(self::TZ);
        $first=self::calendarDate($period.'-01',$tz);
        $end=$first->modify('first day of next month');
        $today=self::utc($trustedNowUtc);
        if ($end->setTimezone(new DateTimeZone('UTC')) >= $today) {
            throw new InvalidArgumentException('Cannot inspect an open calendar month');
        }

        $results=[];$mismatches=[];
        foreach ($budgets as $channel=>$planned) {
            if (!in_array($channel,self::CHANNELS,true)
                || !is_int($planned) || $planned<=0
                || !is_string($accounts[$channel])
                || preg_match('/\A[A-Za-z0-9._:-]{3,128}\z/D',$accounts[$channel])!==1
                || !is_array($reported[$channel])
                || !is_int($reported[$channel]['actual_cents']??null)
                || $reported[$channel]['actual_cents']<0
                || !is_array($statements[$channel])) {
                throw new InvalidArgumentException('Invalid contracted channel or account');
            }
            $s=$statements[$channel];
            $expectedKeys=[
                'account_ref','channel','complete','currency','generated_at_utc',
                'order_ref','origin','period','rows','tenant_ref','timezone'
            ];
            $keys=array_keys($s);sort($keys,SORT_STRING);sort($expectedKeys,SORT_STRING);
            if ($keys!==$expectedKeys || $s['origin']!==self::SYNTHETIC
                || $s['tenant_ref']!==$trustedTenant || $s['order_ref']!==$trustedOrder
                || $s['channel']!==$channel || $s['account_ref']!==$accounts[$channel]
                || $s['currency']!=='BRL' || $s['period']!==$period
                || $s['timezone']!==self::TZ || $s['complete']!==true
                || !is_array($s['rows']) || count($s['rows'])>3000
                || !is_string($s['generated_at_utc'])) {
                throw new InvalidArgumentException('Unsigned, wrong-owner or incomplete media fixture');
            }
            $created=self::utc($s['generated_at_utc']);
            if ($created < $end->setTimezone(new DateTimeZone('UTC'))
                || $created>$today) {
                throw new InvalidArgumentException('Statement generated outside closed-period review window');
            }
            $seen=[];$spent=0;
            foreach ($s['rows'] as $row) {
                if (!is_array($row) || count($row)!==3
                    || !isset($row['event_ref'],$row['day'],$row['spend_cents'])
                    || !is_string($row['event_ref'])
                    || preg_match('/\A[A-Za-z0-9._:-]{3,128}\z/D',$row['event_ref'])!==1
                    || !is_string($row['day'])
                    || !is_int($row['spend_cents'])
                    || $row['spend_cents']<0 || $row['spend_cents']>100_000_000) {
                    throw new InvalidArgumentException('Invalid or unnormalized daily spend row');
                }
                $day=self::calendarDate($row['day'],$tz);
                if ($day<$first || $day>=$end || isset($seen[$row['event_ref']])) {
                    throw new InvalidArgumentException('Out-of-period or duplicated media event');
                }
                $seen[$row['event_ref']]=true;
                if ($spent>100_000_000-$row['spend_cents']) {
                    throw new InvalidArgumentException('Media total overflow');
                }
                $spent+=$row['spend_cents'];
            }
            $reportedCents=$reported[$channel]['actual_cents'];
            $matched=$spent===$reportedCents && $spent>0;
            if (!$matched) {
                $mismatches[]=[
                    'channel'=>$channel,
                    'reason'=>$spent===0?'zero_spend_requires_human_review':'statement_report_disagree'
                ];
            }
            $results[$channel]=[
                'account_ref'=>$accounts[$channel],
                'rows'=>count($s['rows']),
                'statement_spend_cents'=>$spent,
                'report_spend_cents'=>$reportedCents,
                'matched'=>$matched,
                'synthetic_rows_sha256'=>hash(
                    'sha256',json_encode($s['rows'],JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES)
                ),
            ];
        }
        return [
            'schema'=>'imagine.portal.performance.synthetic-media-review.v1',
            'tenant_ref'=>$trustedTenant,
            'order_ref'=>$trustedOrder,
            'period'=>$period,
            'currency'=>'BRL',
            'channels'=>$results,
            'amounts_match_offline'=>$mismatches===[],
            'discrepancies'=>$mismatches,
            'evidence_origin'=>self::SYNTHETIC,
            'official_media_evidence_verified'=>false,
            'contractual_partial_month_policy_verified'=>false,
            'can_charge'=>false,
            'can_issue_invoice'=>false,
            'can_write_psp'=>false,
            'can_activate_ads'=>false,
            'reason'=>'offline_fixture_does_not_prove_platform_account_or_statement_authenticity',
        ];
    }

    private static function sameChannels(array $expected,array $other): bool
    {
        $a=array_keys($expected);$b=array_keys($other);
        sort($a,SORT_STRING);sort($b,SORT_STRING);
        return $a===$b;
    }

    private static function calendarDate(string $raw,DateTimeZone $tz): DateTimeImmutable
    {
        if (preg_match('/\A20[0-9]{2}-(0[1-9]|1[0-2])-[0-3][0-9]\z/D',$raw)!==1) {
            throw new InvalidArgumentException('Invalid media calendar date');
        }
        $v=DateTimeImmutable::createFromFormat('!Y-m-d',$raw,$tz);
        if (!$v || $v->format('Y-m-d')!==$raw) {
            throw new InvalidArgumentException('Nonexistent media calendar date');
        }
        return $v;
    }

    private static function utc(string $raw): DateTimeImmutable
    {
        if (preg_match('/\A20[0-9]{2}-(0[1-9]|1[0-2])-[0-3][0-9]T[0-2][0-9]:[0-5][0-9]:[0-5][0-9]Z\z/D',$raw)!==1) {
            throw new InvalidArgumentException('Invalid UTC evidence timestamp');
        }
        $v=DateTimeImmutable::createFromFormat('!Y-m-d\TH:i:s\Z',$raw,new DateTimeZone('UTC'));
        if (!$v || $v->format('Y-m-d\TH:i:s\Z')!==$raw) {
            throw new InvalidArgumentException('Nonexistent UTC evidence time');
        }
        return $v;
    }
}
