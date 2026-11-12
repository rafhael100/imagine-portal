<?php
declare(strict_types=1);

namespace App\Domain\SeoReview;

/**
 * GSC-F143 — test-only pagination envelope for a F59 aggregate. Reuses the
 * F141 identity/privacy/metric validator; separately validates offsets,
 * continuation and up-to-four-page bounds. No provider I/O or permissions.
 */
final readonly class OfflineGscQueryPagePaginationPreview
{
    private function __construct(
        public OfflineGscQueryPageOverlapPreview $aggregate,
        public array $pagination,
    ) {}

    private static function shape(mixed $value,array $fields): bool
    {
        if(!is_array($value))return false;
        $keys=array_keys($value);
        sort($keys);sort($fields);
        return $keys===$fields;
    }

    private static function countValue(mixed $n,int $max): bool
    {
        return is_int($n) && $n>=0 && $n<=$max;
    }

    public static function fromDto(mixed $dto): ?self
    {
        if(PHP_SAPI!=='cli'||getenv('APP_ENV')!=='testing'||
            getenv('PORTAL_GSC_OVERLAP_LAB')!=='1'||
            getenv('PORTAL_GSC_PAGINATION_LAB')!=='1')
            return null;

        if(!self::shape($dto,[
            'contractVersion','sourceType','tenantRef','propertyFingerprint',
            'period','summary','pagination','reviewStatus',
            'oauthVerified','tenantGrantVerified','customerDisplayAuthorized',
            'productionReadAuthorized','networkPerformed','releaseStatus','caveats'
        ]) ||
            !in_array($dto['contractVersion'],[
                'seo-gsc-query-page-pagination-offline-v1',
                'seo-gsc-query-page-pagination-offline-v2'
            ],true)||
            $dto['sourceType']!=='operator_supplied_paginated_query_page_not_verified')
            return null;

        $versionTwo=$dto['contractVersion']==='seo-gsc-query-page-pagination-offline-v2';
        $paging=$dto['pagination'];
        $fields=[
            'pagesReceived','rowsReceived','mayHaveMore','maxPagesReached','nextStartRow'
        ];
        if($versionTwo){
            $fields[]='pageSize';
            $fields[]='lastPageRows';
        }
        if(!self::shape($paging,$fields) ||
            !self::countValue($paging['pagesReceived'],4)||
            $paging['pagesReceived']<1||
            !self::countValue($paging['rowsReceived'],4000)||
            !is_bool($paging['mayHaveMore'])||
            !is_bool($paging['maxPagesReached'])||
            $paging['maxPagesReached']!==($paging['pagesReceived']===4)||
            $paging['rowsReceived']>$paging['pagesReceived']*1000||
            !is_array($dto['summary'])||
            ($dto['summary']['receivedRows']??null)!==$paging['rowsReceived']||
            ($dto['summary']['possibleTruncation']??null)!==$paging['mayHaveMore'])
            return null;

        if($versionTwo){
            // F59 v2 exposes enough counters to validate the arithmetic
            // exactly instead of inferring it from total rows alone.
            if(!self::countValue($paging['pageSize'],1000)||
                $paging['pageSize']<1||
                !self::countValue($paging['lastPageRows'],$paging['pageSize'])||
                $paging['rowsReceived']!==(
                    ($paging['pagesReceived']-1)*$paging['pageSize']+
                    $paging['lastPageRows']
                )||
                $paging['mayHaveMore']!==(
                    $paging['lastPageRows']===$paging['pageSize']
                ))
                return null;
        }

        if($paging['mayHaveMore']){
            // With a full final page and no skipped offsets, every page in
            // this four-page batch must have exactly the same page size.
            if(!self::countValue($paging['nextStartRow'],4000)||
                $paging['nextStartRow']!==$paging['rowsReceived']||
                $paging['rowsReceived']===0||
                $paging['rowsReceived']%$paging['pagesReceived']!==0)
                return null;
        }elseif($paging['nextStartRow']!==null){
            return null;
        }

        $normalized=$dto;
        unset($normalized['pagination']);
        $normalized['contractVersion']='seo-gsc-query-page-overlap-offline-v1';
        $normalized['sourceType']='operator_supplied_query_page_response_not_verified';
        $core=OfflineGscQueryPageOverlapPreview::fromDto($normalized);
        if($core===null)return null;
        return new self($core,$paging);
    }

    public function canQueryGoogle(): bool {return false;}
    public function canDisplayToCustomers(): bool {return false;}
    public function canStoreTokens(): bool {return false;}
    public function canPublish(): bool {return false;}
}
