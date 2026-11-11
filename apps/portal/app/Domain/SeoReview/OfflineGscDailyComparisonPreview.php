<?php
declare(strict_types=1);

namespace App\Domain\SeoReview;

/**
 * GSC-F138: consumption of a synthetic F54 SEO trend DTO.
 * This is a local, test-only visualization contract, never a Google grant.
 */
final readonly class OfflineGscDailyComparisonPreview
{
    private function __construct(
        public array $periods,
        public array $previous,
        public array $current,
        public array $change,
        public bool $coverageComplete,
        public array $reviewSignals,
    ) {}

    private static function shape(mixed $value, array $names): bool
    {
        if (!is_array($value)) return false;
        $keys=array_keys($value);
        sort($keys);sort($names);
        return $keys===$names;
    }

    private static function nonnegative(mixed $value, int $max=1000000000): bool
    {
        return is_int($value)&&$value>=0&&$value<=$max;
    }

    private static function number(mixed $value): bool
    {
        return (is_int($value)||is_float($value)) && is_finite((float)$value);
    }

    private static function frac(mixed $value): bool
    {
        return $value===null ||
            (self::number($value) && $value>=-1 && $value<=1000000);
    }

    private static function day(mixed $value): bool
    {
        if(!is_string($value)||
            preg_match('/\A[0-9]{4}-[0-9]{2}-[0-9]{2}\z/D',$value)!==1)
            return false;
        $a=explode('-',$value);
        return checkdate((int)$a[1],(int)$a[2],(int)$a[0]);
    }

    private static function metrics(mixed $value): bool
    {
        return self::shape($value,[
            'clicks','impressions','ctr','weightedAveragePosition'
        ]) &&
        self::nonnegative($value['clicks']) &&
        self::nonnegative($value['impressions']) &&
        $value['clicks']<=$value['impressions'] &&
        self::number($value['ctr']) && $value['ctr']>=0 && $value['ctr']<=1 &&
        abs($value['ctr']-
            ($value['impressions']>0 ? $value['clicks']/$value['impressions'] : 0))<=0.0001 &&
        ($value['weightedAveragePosition']===null ||
            (self::number($value['weightedAveragePosition']) &&
             $value['weightedAveragePosition']>=1)) &&
        ($value['impressions']!==0||$value['weightedAveragePosition']===null);
    }

    public static function fromDto(mixed $dto): ?self
    {
        if(PHP_SAPI!=='cli'||getenv('APP_ENV')!=='testing'||
            getenv('PORTAL_GSC_COMPARISON_LAB')!=='1')return null;
        if(!self::shape($dto,[
            'contractVersion','sourceType','tenantRef','propertyFingerprint',
            'periods','comparisonDays','coverageStatus','coverageComplete',
            'previous','current','change','reviewSignals','oauthVerified',
            'tenantGrantVerified','customerDisplayAuthorized',
            'productionReadAuthorized','networkPerformed','releaseStatus','caveats'
        ]) || $dto['contractVersion']!=='seo-gsc-daily-comparison-offline-v1' ||
            $dto['sourceType']!=='two_operator_supplied_date_exports_not_verified'||
            $dto['tenantRef']!=='imagine-demo'||
            $dto['propertyFingerprint']!==str_repeat('a',64)||
            $dto['releaseStatus']!=='HOLD_EXTERNAL_BACKEND_VERIFICATION'
        )return null;
        foreach([
            'oauthVerified','tenantGrantVerified','customerDisplayAuthorized',
            'productionReadAuthorized','networkPerformed'
        ] as $flag)if($dto[$flag]!==false)return null;
        if(!is_bool($dto['coverageComplete'])||
            !self::nonnegative($dto['comparisonDays'],92)||
            $dto['comparisonDays']<1 ||
            !self::shape($dto['periods'],['previous','current'])||
            !self::metrics($dto['previous'])||!self::metrics($dto['current']))
            return null;
        foreach(['previous','current'] as $part){
            $p=$dto['periods'][$part];
            if(!self::shape($p,['startDate','endDate','returnedDays','unreturnedDays'])||
                !self::day($p['startDate'])||!self::day($p['endDate'])||
                !self::nonnegative($p['returnedDays'],92)||
                !self::nonnegative($p['unreturnedDays'],92)||
                $p['returnedDays']+$p['unreturnedDays']!==$dto['comparisonDays'])
                return null;
        }
        if($dto['periods']['previous']['endDate']>=$dto['periods']['current']['startDate'])
            return null;
        $complete=$dto['coverageComplete'];
        if($dto['coverageStatus']!==($complete?
            'COMPLETE_SUPPLIED_DATE_COVERAGE':'PARTIAL_DATA_NO_TREND_SIGNALS'))
            return null;
        if($complete&&($dto['periods']['previous']['unreturnedDays']!==0||
            $dto['periods']['current']['unreturnedDays']!==0))return null;

        $change=$dto['change'];
        if(!self::shape($change,[
            'clicksFraction','impressionsFraction','ctrPercentagePoints',
            'averagePositionDifference'
        ])||!self::frac($change['clicksFraction'])||
            !self::frac($change['impressionsFraction'])||
            ($change['ctrPercentagePoints']!==null&&!self::number($change['ctrPercentagePoints']))||
            ($change['averagePositionDifference']!==null&&!self::number($change['averagePositionDifference'])))
            return null;
        $signals=$dto['reviewSignals'];
        if(!is_array($signals)||!array_is_list($signals)||count($signals)>4)return null;
        $allowed=[
            'review_impression_drop','review_click_drop',
            'review_ctr_drop','review_average_position_decline'
        ];
        if(count(array_unique($signals))!==count($signals))return null;
        foreach($signals as $signal)if(!in_array($signal,$allowed,true))return null;
        if(!$complete&&($signals!==[]||
            $change['ctrPercentagePoints']!==null||
            $change['averagePositionDifference']!==null))return null;
        if(!is_array($dto['caveats'])||!array_is_list($dto['caveats'])||
            count($dto['caveats'])!==3)return null;
        foreach($dto['caveats'] as $caveat)
            if(!is_string($caveat)||strlen($caveat)>240)return null;
        return new self($dto['periods'],$dto['previous'],$dto['current'],
            $change,$complete,$signals);
    }

    public function canQueryGoogle(): bool {return false;}
    public function canPublish(): bool {return false;}
    public function canStoreTokens(): bool {return false;}
    public function isCustomerDisplayAuthorized(): bool {return false;}
}
