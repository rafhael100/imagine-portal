<?php
declare(strict_types=1);

namespace App\Homologation\Commerce;

use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;

/**
 * F425: review-only, short-lived HMAC binding of an F424 monthly candidate to
 * its tenant, frozen order and acknowledged report version. No payment
 * instruction, replay registry, real statement verification or tax document.
 */
final class PerformanceMonthlyCloseReviewEnvelope
{
    private const SCHEMA='imagine.portal.performance.month-close-envelope.v1';
    private const DOMAIN="IMAGINE-PORTAL-PERFORMANCE-MONTH-CLOSE-REVIEW-OFFLINE-V1\n";
    private const TTL=900;

    /** @param array<string,mixed> $candidate Output of F424, not a browser payload. */
    public static function issue(
        array $candidate,
        string $tenant,
        string $order,
        int $reportVersion,
        string $acknowledgedReportSha256,
        string $issuedAtUtc,
        #[\SensitiveParameter] string $secret
    ): array {
        self::validateContext($tenant,$order,$reportVersion,$acknowledgedReportSha256,$secret);
        self::validateCandidate($candidate);
        $issued=self::timestamp($issuedAtUtc);
        $payload=[
            'schema'=>self::SCHEMA,
            'tenant_ref'=>$tenant,
            'order_ref'=>$order,
            'report_version'=>$reportVersion,
            'acknowledged_report_sha256'=>$acknowledgedReportSha256,
            'candidate_sha256'=>hash('sha256',self::json($candidate)),
            'period'=>$candidate['period'],
            'policy_version'=>$candidate['policy_version'],
            'review_amount_cents'=>$candidate['informational_management_fee_cents'],
            'issued_at'=>$issuedAtUtc,
            'expires_at'=>$issued->modify('+15 minutes')->format('Y-m-d\TH:i:s\Z'),
            'can_issue_invoice'=>false,
            'can_charge'=>false,
            'can_write_psp'=>false,
            'can_grant_service'=>false,
            'environment'=>'offline_review',
        ];
        return ['payload'=>$payload,'mac'=>self::mac($payload,$secret)];
    }

    /**
     * Re-derives the exact envelope in constant-time HMAC comparison; caller
     * must obtain the candidate/report through authorized DB IAM, not HTTP.
     */
    public static function verify(
        array $wire,
        array $originalCandidate,
        string $trustedTenant,
        string $trustedOrder,
        int $trustedReportVersion,
        string $trustedReportSha256,
        string $trustedNowUtc,
        #[\SensitiveParameter] string $secret
    ): bool {
        try {
            $payload=$wire['payload']??null;
            $mac=$wire['mac']??null;
            if (!is_array($payload) || !is_string($mac)
                || preg_match('/\A[a-f0-9]{64}\z/D',$mac)!==1
                || !is_string($payload['issued_at']??null)
                || !is_string($payload['expires_at']??null)
                || count($wire)!==2) return false;
            $now=self::timestamp($trustedNowUtc);
            $issued=self::timestamp($payload['issued_at']);
            $expires=self::timestamp($payload['expires_at']);
            if ($expires->getTimestamp()-$issued->getTimestamp()!==self::TTL
                || $now<$issued || $now>$expires) return false;
            $expected=self::issue(
                $originalCandidate,$trustedTenant,$trustedOrder,
                $trustedReportVersion,$trustedReportSha256,
                $payload['issued_at'],$secret
            );
            return hash_equals($expected['mac'],$mac)
                && self::json($expected['payload'])===self::json($payload);
        } catch (\Throwable) {
            return false;
        }
    }

    private static function validateContext(
        string $tenant,string $order,int $version,string $digest,string $secret
    ): void {
        if (preg_match('/\A[A-Za-z0-9._:-]{3,128}\z/D',$tenant)!==1
            || preg_match('/\A[A-Za-z0-9._:-]{3,128}\z/D',$order)!==1
            || $version<1 || $version>20
            || preg_match('/\A[a-f0-9]{64}\z/D',$digest)!==1
            || strlen($secret)<32 || strlen($secret)>128) {
            throw new InvalidArgumentException('Invalid scoped review or signing key');
        }
    }

    private static function validateCandidate(array $c): void
    {
        $keys=[
            'schema','policy_version','period','period_end',
            'proposed_due_date','charge_key_candidate','channel_count',
            'currency','informational_management_fee_cents',
            'minimum_per_channel_cents','activation_excluded_from_monthly_fee',
            'media_paid_directly_to_platforms','sandbox_evidence_only',
            'real_invoice_authorized','sandbox_psp_write_authorized',
            'fiscal_document_authorized','production_authorized','reason',
        ];
        $actual=array_keys($c);
        sort($actual,SORT_STRING);sort($keys,SORT_STRING);
        if ($actual!==$keys
            || $c['schema']!=='imagine.portal.performance.monthly-close-review-only.v1'
            || $c['policy_version']!==PerformancePostpaidPolicy::VERSION
            || preg_match('/\A20[0-9]{2}-(0[1-9]|1[0-2])\z/D',$c['period'])!==1
            || $c['period_end']!==self::endOfMonth($c['period'])
            || !is_string($c['proposed_due_date'])
            || self::calendarDay($c['proposed_due_date'])<=self::calendarDay($c['period_end'])
            || $c['charge_key_candidate']!=='performance-'.$c['period']
            || !in_array($c['channel_count'],[1,2],true)
            || $c['currency']!=='BRL'
            || !is_int($c['informational_management_fee_cents'])
            || $c['informational_management_fee_cents']<17_000
            || $c['informational_management_fee_cents']>100_000_000
            || $c['minimum_per_channel_cents']!==17_000
            || $c['activation_excluded_from_monthly_fee']!==true
            || $c['media_paid_directly_to_platforms']!==true
            || $c['sandbox_evidence_only']!==true
            || $c['real_invoice_authorized']!==false
            || $c['sandbox_psp_write_authorized']!==false
            || $c['fiscal_document_authorized']!==false
            || $c['production_authorized']!==false
            || $c['reason']!=='review_candidate_requires_external_media_evidence_and_financial_go'
        ) {
            throw new InvalidArgumentException('Not a closed F424 informational candidate');
        }
    }

    private static function endOfMonth(string $period): string
    {
        return self::calendarDay($period.'-01')->modify('last day of this month')->format('Y-m-d');
    }

    private static function calendarDay(string $raw): DateTimeImmutable
    {
        if (preg_match('/\A20[0-9]{2}-(0[1-9]|1[0-2])-[0-3][0-9]\z/D',$raw)!==1) {
            throw new InvalidArgumentException('Invalid calendar date');
        }
        $date=DateTimeImmutable::createFromFormat('!Y-m-d',$raw,new DateTimeZone('UTC'));
        if (!$date || $date->format('Y-m-d')!==$raw) {
            throw new InvalidArgumentException('Nonexistent calendar day');
        }
        return $date;
    }

    private static function timestamp(string $raw): DateTimeImmutable
    {
        if (preg_match('/\A20[0-9]{2}-(0[1-9]|1[0-2])-[0-3][0-9]T[0-2][0-9]:[0-5][0-9]:[0-5][0-9]Z\z/D',$raw)!==1) {
            throw new InvalidArgumentException('Strict UTC timestamp required');
        }
        $stamp=DateTimeImmutable::createFromFormat('!Y-m-d\TH:i:s\Z',$raw,new DateTimeZone('UTC'));
        if (!$stamp || $stamp->format('Y-m-d\TH:i:s\Z')!==$raw) {
            throw new InvalidArgumentException('Nonexistent UTC timestamp');
        }
        return $stamp;
    }

    private static function json(array $value): string
    {
        return json_encode(self::canonical($value),JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES);
    }

    private static function canonical(array $data): array
    {
        if (array_is_list($data)) {
            return array_map(
                static fn(mixed $v): mixed => is_array($v)?self::canonical($v):$v,
                $data
            );
        }
        ksort($data,SORT_STRING);
        foreach ($data as &$value) {
            if (is_array($value)) $value=self::canonical($value);
        }
        unset($value);
        return $data;
    }

    private static function mac(array $payload,string $secret): string
    {
        return hash_hmac('sha256',self::DOMAIN.self::json($payload),$secret);
    }
}
