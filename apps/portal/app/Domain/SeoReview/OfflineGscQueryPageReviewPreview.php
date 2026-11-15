<?php
declare(strict_types=1);

namespace App\Domain\SeoReview;

use DateTimeImmutable;
use DateTimeZone;

/**
 * GSC-F155 — strict, test-only PHP consumer for SEO-F74 aggregated handoff.
 * Source properties/tenant are syntactic fixture data, NEVER real grants.
 */
final readonly class OfflineGscQueryPageReviewPreview
{
    private function __construct(
        public array $summary,
        public string $coverageStatus,
        public array $suggestedReviews,
    ) {}

    private static function shape(mixed $value,array $expected): bool
    {
        if(!is_array($value)||array_is_list($value))return false;
        $keys=array_keys($value);
        sort($keys);sort($expected);
        return $keys===$expected;
    }

    private static function count(mixed $value,int $max=4000): bool
    {
        return is_int($value)&&$value>=0&&$value<=$max;
    }

    private static function day(mixed $value): ?int
    {
        if(!is_string($value)||preg_match('/\A[0-9]{4}-[0-9]{2}-[0-9]{2}\z/D',$value)!==1)
            return null;
        $dt=DateTimeImmutable::createFromFormat(
            '!Y-m-d',$value,new DateTimeZone('UTC'));
        if($dt===false||$dt->format('Y-m-d')!==$value)return null;
        return intdiv($dt->getTimestamp(),86400);
    }

    public static function fromDto(mixed $dto): ?self
    {
        if(PHP_SAPI!=='cli'||getenv('APP_ENV')!=='testing'||
            getenv('PORTAL_GSC_QUERY_PAGE_REVIEW_LAB')!=='1')return null;

        if(!self::shape($dto,[
            'contractVersion','sourceType','scope','period',
            'coverageStatus','reviewStatus','summary','pagination',
            'suggestedReviews','oauthVerified','propertyAuthorizationVerified',
            'backendTenantGrantVerified','customerDisplayAuthorized',
            'publicationAuthorized','networkPerformed','releaseStatus',
            'limitations'
        ])||
            $dto['contractVersion']!=='seo-portal-query-page-review-offline-v1'||
            $dto['sourceType']!=='operator_supplied_query_page_batch_unverified'||
            $dto['reviewStatus']!=='REQUIRES_AUTHENTICATED_HUMAN_REVIEW'||
            $dto['releaseStatus']!=='HOLD_EXTERNAL_AUTHORIZATION')
            return null;

        foreach([
            'oauthVerified','propertyAuthorizationVerified',
            'backendTenantGrantVerified','customerDisplayAuthorized',
            'publicationAuthorized','networkPerformed'
        ] as $flag)
            if($dto[$flag]!==false)return null;

        if(!self::shape($dto['scope'],[
            'tenantRef','propertyFingerprint','sourceRevision'
        ])||$dto['scope']['tenantRef']!=='imagine-demo'||
            $dto['scope']['propertyFingerprint']!==hash('sha256','sc-domain:example.com')||
            !is_string($dto['scope']['sourceRevision'])||
            preg_match('/\A[a-f0-9]{40}\z/D',$dto['scope']['sourceRevision'])!==1)
            return null;

        if(!self::shape($dto['period'],['startDate','endDate']))return null;
        $start=self::day($dto['period']['startDate']);
        $end=self::day($dto['period']['endDate']);
        if($start===null||$end===null||$end<$start||$end-$start>91)return null;

        $s=$dto['summary'];
        if(!self::shape($s,[
            'receivedRows','redactedRows','analyzedRows','distinctQueries',
            'queriesOnMultiplePages','pagesAcrossOverlappingQueries'
        ]))return null;
        foreach($s as $value)if(!self::count($value))return null;
        if($s['receivedRows']!==$s['redactedRows']+$s['analyzedRows']||
            $s['distinctQueries']>$s['analyzedRows']||
            $s['queriesOnMultiplePages']>$s['distinctQueries']||
            $s['pagesAcrossOverlappingQueries']>$s['analyzedRows']||
            $s['pagesAcrossOverlappingQueries']<
              $s['queriesOnMultiplePages']*2||
            ($s['queriesOnMultiplePages']===0&&
              $s['pagesAcrossOverlappingQueries']!==0))
            return null;

        $p=$dto['pagination'];
        if(!self::shape($p,['pagesReceived','pageSize','lastPageRows','mayHaveMore'])||
            !self::count($p['pagesReceived'],4)||$p['pagesReceived']<1||
            !self::count($p['pageSize'],1000)||$p['pageSize']<1||
            !self::count($p['lastPageRows'],1000)||
            $p['lastPageRows']>$p['pageSize']||
            !is_bool($p['mayHaveMore'])||
            $s['receivedRows']!==($p['pagesReceived']-1)*$p['pageSize']+
                $p['lastPageRows']||
            $p['mayHaveMore']!==($p['lastPageRows']===$p['pageSize']))
            return null;

        $redacted=$s['redactedRows']>0;
        $partial=$p['mayHaveMore'];
        $expectedStatus=$redacted?'REDACTED_ROWS_NO_SIGNALS':
            ($partial?'PARTIAL_BATCH_NO_SIGNALS':'SUPPLIED_BATCH_ONLY_UNVERIFIED');
        if($dto['coverageStatus']!==$expectedStatus)return null;
        $expectedReview=(!$redacted&&!$partial&&
            $s['queriesOnMultiplePages']>0)?['review_query_page_overlap']:[];
        if($dto['suggestedReviews']!==$expectedReview)return null;

        if(!is_array($dto['limitations'])||
            !array_is_list($dto['limitations'])||
            count($dto['limitations'])!==3)return null;
        foreach($dto['limitations'] as $note)
            if(!is_string($note)||strlen($note)>256)return null;

        return new self($s,$expectedStatus,$expectedReview);
    }

    public function canQueryGoogle(): bool {return false;}
    public function canPublish(): bool {return false;}
    public function hasVerifiedTenantGrant(): bool {return false;}
}
