<?php
declare(strict_types=1);

namespace App\Domain\SeoReview;

use Closure;
use PDO;
use SensitiveParameter;
use Throwable;

/**
 * F109: isolated, CLI-only review of an explicit seo.gsc.view grant.
 * Connect permission is NOT metrics-read permission. The SQL procedure
 * locks the F108 six rows plus the membership and explicit grant until
 * commit. No Portal HTTP authorization or Google access is created.
 */
final readonly class OfflinePortalGscMetricsGrantReview
{
    public function __construct(private PDO $db) {}

    public function inspect(
        #[SensitiveParameter] mixed $firstPartySessionRef,
        mixed $expectedOrganizationUuid,
        mixed $propertyFromBackend,
        mixed $trustedNow,
        mixed $offlineDto,
        ?Closure $onLockedRows=null,
    ): ?array {
        if (PHP_SAPI!=='cli' ||
            getenv('APP_ENV')!=='testing' ||
            getenv('PORTAL_GSC_F109_VIEW_LAB')!=='1' ||
            getenv('PORTAL_GSC_F108_ATOMIC_LAB')!=='1' ||
            getenv('PORTAL_OPENSEO_DAILY_LAB')!=='1' ||
            !is_int($trustedNow) || $trustedNow<1600000000 ||
            $trustedNow>2200000000 ||
            !is_string($firstPartySessionRef) ||
            preg_match('/\A[a-f0-9]{64}\z/D',$firstPartySessionRef)!==1 ||
            !is_string($expectedOrganizationUuid) ||
            preg_match(
                '/\A[0-9a-f]{8}-[0-9a-f]{4}-[1-8][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}\z/D',
                $expectedOrganizationUuid
            )!==1 ||
            !is_array($offlineDto) ||
            !is_string($offlineDto['tenantRef']??null) ||
            preg_match('/\A[a-z0-9][a-z0-9_-]{2,63}\z/D',
                $offlineDto['tenantRef'])!==1)
            return null;

        $fingerprint=GoogleSearchConsolePropertyIdentifier::fingerprint(
            $propertyFromBackend
        );
        if ($fingerprint===null ||
            ($offlineDto['propertyFingerprint']??null)!==$fingerprint)
            return null;
        $preview=OfflineOpenSeoDailyPreview::fromDto($offlineDto);
        if ($preview===null) return null;
        $tenant=$offlineDto['tenantRef'];

        $result=(new GoogleSearchConsoleTenantScopedTransaction($this->db))
            ->run([
                'tenantRef'=>$tenant,'actorRef'=>'f109-review-only',
            ],function (PDO $connection) use (
                $firstPartySessionRef,$expectedOrganizationUuid,$tenant,
                $fingerprint,$trustedNow,$preview,$onLockedRows
            ):?array {
                $s=$connection->prepare(
                    'SELECT * FROM public.portal_gsc_f109_metric_read_candidate(
                     :digest,CAST(:org AS uuid),:tenant,:fp,CAST(:at AS bigint)
                    )'
                );
                if(!$s || !$s->execute([
                    'digest'=>hash('sha256',$firstPartySessionRef),
                    'org'=>$expectedOrganizationUuid,'tenant'=>$tenant,
                    'fp'=>$fingerprint,'at'=>$trustedNow,
                ]))return null;
                $row=$s->fetch(PDO::FETCH_ASSOC);
                if(!is_array($row) ||
                    $s->fetch(PDO::FETCH_ASSOC)!==false ||
                    ($row['portal_organization_id']??null)!==
                        $expectedOrganizationUuid ||
                    !in_array($row['recorded_metrics_view_grant']??null,
                        [true,'t',1,'1'],true))
                    return null;
                $issued=filter_var($row['portal_issued_at']??null,
                    FILTER_VALIDATE_INT);
                $expires=filter_var($row['portal_expires_at']??null,
                    FILTER_VALIDATE_INT);
                if ($issued===false || $expires===false ||
                    $issued>$trustedNow || $expires<=$trustedNow ||
                    $expires<=$issued || $expires-$issued>7200)
                    return null;
                if($onLockedRows!==null && $onLockedRows()!==true)
                    return null;

                return [
                    'contractVersion'=>'portal-gsc-f109-view-grant-review-lab-v1',
                    'status'=>'RECORDED_SEO_VIEW_GRANT_NOT_AUTHORIZATION',
                    'period'=>$preview->period,
                    'summary'=>$preview->summary,
                    'missingDatesUnknown'=>$preview->missingDatesUnknown,
                    'firstPartySessionAndGscRowsObservedAtomically'=>true,
                    'explicitSeoGscViewRowObserved'=>true,
                    'connectOnlyGrantsInsufficient'=>true,
                    'revocationRowLocksHeldDuringReview'=>true,
                    'sessionAuthenticatedForProduction'=>false,
                    'seoViewPermissionAuthorizedForProduction'=>false,
                    'googleOAuthVerifiedNow'=>false,
                    'sourceOriginVerified'=>false,
                    'customerDisplayAuthorized'=>false,
                    'productionReadAuthorized'=>false,
                    'publicationAuthorized'=>false,
                    'networkPerformed'=>false,
                    'releaseStatus'=>'HOLD_REAL_AUTH_RBAC_GOOGLE_AND_APPSEC',
                ];
            });
        return is_array($result)?$result:null;
    }

    public static function canAuthorizeCustomer():bool{return false;}
    public static function canQueryGoogle():bool{return false;}
    public static function canPublish():bool{return false;}
}
