<?php
declare(strict_types=1);

namespace App\Domain\SeoReview;

/**
 * GSC-F151 — generate a syntactic next-page *review intention* only when
 * SEO F63 v2 proves the page-size and last-page arithmetic.
 *
 * No HTTP transport, authority, OAuth token, customer display or scheduling.
 * The opaque provider DTO is untrusted; all fields are revalidated first.
 */
final class GoogleSearchConsoleNextPageIntent
{
    /**
     * @return array{contractVersion:string,status:string,request:array,
     *               networkPerformed:bool,oauthVerified:bool,
     *               productionReadAuthorized:bool}|null
     */
    public function fromOfflineDto(
        mixed $dto,
        mixed $requestedSiteUrl,
    ): ?array {
        if(PHP_SAPI!=='cli'||getenv('APP_ENV')!=='testing'||
            getenv('PORTAL_GSC_OVERLAP_LAB')!=='1'||
            getenv('PORTAL_GSC_PAGINATION_LAB')!=='1'||
            getenv('PORTAL_GSC_REQUEST_LAB')!=='1'||
            !is_array($dto)||
            ($dto['contractVersion']??null)!=='seo-gsc-query-page-pagination-offline-v2')
            return null;

        $review=OfflineGscQueryPagePaginationPreview::fromDto($dto);
        if($review===null||$review->canQueryGoogle()||
            $review->canDisplayToCustomers()||
            GoogleSearchConsolePropertyIdentifier::fingerprint($requestedSiteUrl)===
                null||
            !hash_equals(
                $dto['propertyFingerprint'],
                GoogleSearchConsolePropertyIdentifier::fingerprint($requestedSiteUrl)
            ))return null;

        $paging=$review->pagination;
        if(!$paging['mayHaveMore']||$paging['maxPagesReached']||
            $paging['pagesReceived']>=4||
            $paging['nextStartRow']===null||
            $paging['lastPageRows']!==$paging['pageSize'])
            return null;

        $period=$dto['period'];
        $request=(new GoogleSearchConsoleQueryPagePaginationPlan())->buildRequestShape(
            $requestedSiteUrl,
            $period['startDate'],
            $period['endDate'],
            $paging['pageSize'],
            $paging['nextStartRow']
        );
        if($request===null||
            ($request['body']['startRow']??null)!==$paging['nextStartRow']||
            ($request['body']['rowLimit']??null)!==$paging['pageSize'])
            return null;

        return [
            'contractVersion'=>'portal-gsc-next-page-intent-review-v1',
            'status'=>'SYNTHETIC_NEXT_PAGE_INTENT_NOT_AUTHORIZED',
            'request'=>$request,
            'networkPerformed'=>false,
            'oauthVerified'=>false,
            'productionReadAuthorized'=>false,
        ];
    }

    public static function authorizesOutboundHttp(): bool {return false;}
    public static function authorizesTokenAccess(): bool {return false;}
}
