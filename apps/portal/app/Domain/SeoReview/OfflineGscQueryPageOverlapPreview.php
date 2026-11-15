<?php
declare(strict_types=1);

namespace App\Domain\SeoReview;

/**
 * GSC-F141 — test-only, aggregate-only consumer for SEO-F57 overlap evidence.
 * No raw search terms, URLs, tokens, HTTP, OAuth or publish capability.
 */
final readonly class OfflineGscQueryPageOverlapPreview
{
    private function __construct(
        public array $summary,
        public string $reviewStatus,
    ) {}

    private static function shape(mixed $value,array $fields): bool
    {
        if(!is_array($value))return false;
        $keys=array_keys($value);
        sort($keys);sort($fields);
        return $keys===$fields;
    }

    private static function counter(mixed $n,int $max=4000): bool
    {
        return is_int($n)&&$n>=0&&$n<=$max;
    }

    private static function date(mixed $value): bool
    {
        if(!is_string($value)||
            preg_match('/\A[0-9]{4}-[0-9]{2}-[0-9]{2}\z/D',$value)!==1)
            return false;
        $parts=explode('-',$value);
        return checkdate((int)$parts[1],(int)$parts[2],(int)$parts[0]);
    }

    public static function fromDto(mixed $dto): ?self
    {
        if(PHP_SAPI!=='cli'||getenv('APP_ENV')!=='testing'||
            getenv('PORTAL_GSC_OVERLAP_LAB')!=='1')return null;
        if(!self::shape($dto,[
            'contractVersion','sourceType','tenantRef','propertyFingerprint',
            'period','summary','reviewStatus','oauthVerified','tenantGrantVerified',
            'customerDisplayAuthorized','productionReadAuthorized','networkPerformed',
            'releaseStatus','caveats'
        ]) || $dto['contractVersion']!=='seo-gsc-query-page-overlap-offline-v1'||
            $dto['sourceType']!=='operator_supplied_query_page_response_not_verified'||
            $dto['tenantRef']!=='imagine-demo'||
            $dto['propertyFingerprint']!==hash('sha256','sc-domain:example.com')||
            $dto['releaseStatus']!=='HOLD_EXTERNAL_BACKEND_VERIFICATION')
            return null;
        foreach([
            'oauthVerified','tenantGrantVerified','customerDisplayAuthorized',
            'productionReadAuthorized','networkPerformed'
        ] as $flag)if($dto[$flag]!==false)return null;
        $period=$dto['period'];
        if(!self::shape($period,['startDate','endDate'])||
            !self::date($period['startDate'])||!self::date($period['endDate'])||
            $period['startDate']>$period['endDate']||
            (strtotime($period['endDate'])-strtotime($period['startDate']))/86400>=92)
            return null;
        $s=$dto['summary'];
        if(!self::shape($s,[
            'receivedRows','redactedRows','analyzedRows','distinctQueries',
            'queriesOnMultiplePages','totalPagesAcrossOverlappingQueries',
            'impressionsOnOverlappingQueries','possibleTruncation'
        ]))return null;
        foreach([
            'receivedRows','redactedRows','analyzedRows','distinctQueries',
            'queriesOnMultiplePages','totalPagesAcrossOverlappingQueries'
        ] as $key)if(!self::counter($s[$key]))return null;
        if(!self::counter($s['impressionsOnOverlappingQueries'],1000000000)||
            !is_bool($s['possibleTruncation'])||
            $s['redactedRows']+$s['analyzedRows']!==$s['receivedRows']||
            $s['distinctQueries']>$s['analyzedRows']||
            $s['queriesOnMultiplePages']>$s['distinctQueries']||
            $s['totalPagesAcrossOverlappingQueries']>$s['analyzedRows']||
            $s['totalPagesAcrossOverlappingQueries']<
                2*$s['queriesOnMultiplePages'])
            return null;
        $status=$s['possibleTruncation']?'PARTIAL_COVERAGE_REVIEW_ONLY':
            ($s['queriesOnMultiplePages']>0?
                'POSSIBLE_QUERY_PAGE_OVERLAP_REVIEW':'NO_OVERLAP_IN_SUPPLIED_ROWS');
        if($dto['reviewStatus']!==$status)return null;
        if(!is_array($dto['caveats'])||!array_is_list($dto['caveats'])||
            count($dto['caveats'])!==3)return null;
        foreach($dto['caveats'] as $text)
            if(!is_string($text)||strlen($text)>220)return null;
        return new self($s,$status);
    }

    public function canPublish(): bool {return false;}
    public function canReadGoogle(): bool {return false;}
    public function canStoreTokens(): bool {return false;}
    public function hasVerifiedTenantGrant(): bool {return false;}
}
