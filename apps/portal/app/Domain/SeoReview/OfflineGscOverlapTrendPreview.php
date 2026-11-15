<?php
declare(strict_types=1);

namespace App\Domain\SeoReview;

use DateTimeImmutable;
use DateTimeZone;

/**
 * GSC-F152 — strict test-only, aggregate-only consumer for SEO F65.
 * Not an authenticated Google or Portal service and never permits display.
 */
final readonly class OfflineGscOverlapTrendPreview
{
    private function __construct(
        public array $previous,
        public array $current,
        public ?float $shareChange,
        public array $reviewSignals,
        public bool $coverageComplete,
    ) {}

    private static function shape(mixed $value,array $names): bool
    {
        if(!is_array($value)||array_is_list($value))return false;
        $keys=array_keys($value);
        sort($keys);sort($names);
        return $keys===$names;
    }

    private static function nonnegative(mixed $value,int $max=4000): bool
    {
        return is_int($value)&&$value>=0&&$value<=$max;
    }

    private static function share(mixed $value,int $overlap,int $queries): bool
    {
        if($queries===0)return $value===null && $overlap===0;
        return (is_float($value)||is_int($value)) && is_finite((float)$value) &&
            $value>=0 && $value<=1 &&
            abs((float)$value-round($overlap/$queries,4))<0.00001;
    }

    private static function metric(mixed $value): bool
    {
        if(!self::shape($value,[
            'receivedRows','redactedRows','distinctQueries',
            'queriesOnMultiplePages','overlapShare'
        ]))return false;
        foreach(['receivedRows','redactedRows','distinctQueries',
            'queriesOnMultiplePages'] as $field)
            if(!self::nonnegative($value[$field]))return false;
        if($value['redactedRows']>$value['receivedRows']||
            $value['distinctQueries']>$value['receivedRows']-$value['redactedRows']||
            $value['queriesOnMultiplePages']>$value['distinctQueries'])
            return false;
        return self::share(
            $value['overlapShare'],
            $value['queriesOnMultiplePages'],
            $value['distinctQueries']
        );
    }

    private static function day(mixed $value): ?int
    {
        if(!is_string($value)||
            preg_match('/\A[0-9]{4}-[0-9]{2}-[0-9]{2}\z/D',$value)!==1)
            return null;
        $dt=DateTimeImmutable::createFromFormat(
            '!Y-m-d',$value,new DateTimeZone('UTC'));
        if($dt===false||$dt->format('Y-m-d')!==$value)return null;
        return intdiv($dt->getTimestamp(),86400);
    }

    public static function fromDto(mixed $dto): ?self
    {
        if(PHP_SAPI!=='cli'||getenv('APP_ENV')!=='testing'||
            getenv('PORTAL_GSC_TRENDS_LAB')!=='1')return null;
        if(!self::shape($dto,[
            'contractVersion','sourceType','tenantRef','propertyFingerprint',
            'periods','comparisonDays','coverageStatus','coverageComplete',
            'previous','current','overlapShareChange','reviewSignals',
            'oauthVerified','tenantGrantVerified','customerDisplayAuthorized',
            'productionReadAuthorized','networkPerformed','releaseStatus','caveats'
        ])||
            $dto['contractVersion']!=='seo-gsc-query-page-overlap-trend-offline-v1'||
            $dto['sourceType']!=='two_operator_supplied_query_page_exports_not_verified'||
            $dto['tenantRef']!=='imagine-demo'||
            $dto['propertyFingerprint']!==hash('sha256','sc-domain:example.com')||
            $dto['releaseStatus']!=='HOLD_EXTERNAL_BACKEND_VERIFICATION')
            return null;

        foreach(['oauthVerified','tenantGrantVerified','customerDisplayAuthorized',
            'productionReadAuthorized','networkPerformed'] as $flag)
            if($dto[$flag]!==false)return null;

        if(!self::shape($dto['periods'],['previous','current'])||
            !self::nonnegative($dto['comparisonDays'],92)||
            $dto['comparisonDays']<1||
            !self::metric($dto['previous'])||
            !self::metric($dto['current'])||
            !is_bool($dto['coverageComplete']))
            return null;

        $dates=[];
        foreach(['previous','current'] as $period){
            $p=$dto['periods'][$period];
            if(!self::shape($p,['startDate','endDate']))return null;
            $from=self::day($p['startDate']);
            $until=self::day($p['endDate']);
            if($from===null||$until===null||$from>$until||
                $until-$from+1!==$dto['comparisonDays'])return null;
            $dates[$period]=['from'=>$from,'until'=>$until];
        }
        if($dates['previous']['until']>=$dates['current']['from'])return null;

        $complete=$dto['coverageComplete'];
        $redacted=$dto['previous']['redactedRows']>0||
            $dto['current']['redactedRows']>0;
        // SEO-F67: redacted evidence cannot claim complete coverage or
        // emit overlap trend signals even if a former batch was full.
        if($complete&&$redacted)return null;
        $expectedCoverage=$redacted?'REDACTED_GSC_ROWS_NO_SIGNALS':
            ($complete?'COMPLETE_SUPPLIED_BATCHES':'PARTIAL_GSC_BATCHES_NO_SIGNALS');
        if($dto['coverageStatus']!==$expectedCoverage)return null;
        $before=$dto['previous']['overlapShare'];
        $after=$dto['current']['overlapShare'];
        $expected=$before===null||$after===null?null:
            round((float)$after-(float)$before,4);
        $delta=$dto['overlapShareChange'];
        if(!$complete){
            if($delta!==null||$dto['reviewSignals']!==[])return null;
        }elseif($expected===null){
            if($delta!==null)return null;
        }elseif((!is_float($delta)&&!is_int($delta))||
            !is_finite((float)$delta)||abs($delta-$expected)>0.00001)
            return null;

        $signals=$dto['reviewSignals'];
        if(!is_array($signals)||!array_is_list($signals)||
            count($signals)>1 ||
            ($signals!==[]&&$signals!==['review_overlap_share_increase']))
            return null;
        $shouldSignal=$complete&&$expected!==null&&
            $dto['previous']['distinctQueries']>=10&&
            $dto['current']['distinctQueries']>=10&&
            $dto['current']['queriesOnMultiplePages']>=4&&$expected>=0.15;
        if(($signals!==[])!==$shouldSignal)return null;

        if(!is_array($dto['caveats'])||!array_is_list($dto['caveats'])||
            !in_array(count($dto['caveats']),[3,4],true)||
            ($redacted&&count($dto['caveats'])!==4))return null;
        foreach($dto['caveats'] as $caveat)
            if(!is_string($caveat)||strlen($caveat)>240)return null;

        return new self($dto['previous'],$dto['current'],$delta,$signals,$complete);
    }

    public function canQueryGoogle(): bool {return false;}
    public function canPublish(): bool {return false;}
    public function hasVerifiedTenantGrant(): bool {return false;}
}
