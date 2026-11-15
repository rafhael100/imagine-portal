<?php
declare(strict_types=1);

namespace App\Domain\SeoReview;

/**
 * GSC-F144 — deterministic request-shape factory for Search Analytics
 * query/page pagination. This is never an authorized HTTP client.
 *
 * The future adapter MUST independently verify Portal session/MFA, tenant
 * grant, exact Google property, current OAuth scope and token-vault binding
 * before issuing the request. This factory only checks syntax and bounds.
 */
final class GoogleSearchConsoleQueryPagePaginationPlan
{
    /**
     * Up to four 1,000-row pages, with contiguous offsets. Google supports
     * larger limits, but this module deliberately uses the smaller F59 cap.
     *
     * @return array{method:string,url:string,body:array<string,mixed>}|null
     */
    public function buildRequestShape(
        mixed $siteUrl,
        mixed $startDate,
        mixed $endDate,
        mixed $rowLimit=1000,
        mixed $startRow=0,
    ): ?array {
        if(!is_int($rowLimit)||$rowLimit<1||$rowLimit>1000||
            !is_int($startRow)||$startRow<0||
            $startRow%$rowLimit!==0||
            intdiv($startRow,$rowLimit)>3||
            $startRow+$rowLimit>4000)
            return null;

        $base=(new GoogleSearchConsoleSearchAnalyticsPlan())->buildRequestShape(
            $siteUrl,$startDate,$endDate,['query','page'],$rowLimit
        );
        if($base===null ||
            ($base['method']??null)!=='POST' ||
            !isset($base['body']) ||
            !is_array($base['body']))
            return null;

        $body=$base['body'];
        $body['aggregationType']='byPage';
        $body['startRow']=$startRow;
        return [
            'method'=>$base['method'],
            'url'=>$base['url'],
            'body'=>$body,
        ];
    }

    public static function authorizesOutboundRequest(): bool {return false;}
    public static function authorizesGoogleAccess(): bool {return false;}
    public static function authorizesPublication(): bool {return false;}
}
