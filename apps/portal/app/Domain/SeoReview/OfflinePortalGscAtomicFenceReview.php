<?php
declare(strict_types=1);

namespace App\Domain\SeoReview;

use Closure;
use PDO;
use SensitiveParameter;
use Throwable;

/**
 * F108: disposable CLI review which holds row locks for session, identity,
 * organization, membership, GSC scope-link and recorded property grant in
 * ONE PostgreSQL transaction. No actual authenticated HTTP caller, Google
 * OAuth or customer-display permission is established by this component.
 *
 * The SQL definer and the restricted runtime are CI-only. A successful
 * observation becomes stale the moment this transaction commits. DO NOT
 * use its result as a reusable token or capability.
 */
final readonly class OfflinePortalGscAtomicFenceReview
{
    public function __construct(private PDO $db) {}

    /**
     * @param ?Closure $onLockedRows Lab-only callback to verify a competing
     *    revoker cannot mutate a locked row until the transaction completes.
     *    Callback runs with the locks held; never expose to HTTP.
     */
    public function inspect(
        #[SensitiveParameter] mixed $firstPartySessionRef,
        mixed $expectedOrganizationUuid,
        mixed $exactPropertyFromBackend,
        mixed $trustedNow,
        mixed $offlineDto,
        ?Closure $onLockedRows = null,
    ): ?array {
        if (PHP_SAPI !== 'cli' ||
            getenv('APP_ENV') !== 'testing' ||
            getenv('PORTAL_GSC_F108_ATOMIC_LAB') !== '1' ||
            getenv('PORTAL_OPENSEO_DAILY_LAB') !== '1' ||
            !is_int($trustedNow) || $trustedNow < 1600000000 ||
            $trustedNow > 2200000000 ||
            !is_string($firstPartySessionRef) ||
            preg_match('/\A[a-f0-9]{64}\z/D', $firstPartySessionRef) !== 1 ||
            !self::uuid($expectedOrganizationUuid) ||
            !is_array($offlineDto) ||
            !self::ref($offlineDto['tenantRef']??null))
            return null;

        $fingerprint=GoogleSearchConsolePropertyIdentifier::fingerprint(
            $exactPropertyFromBackend
        );
        if ($fingerprint===null ||
            ($offlineDto['propertyFingerprint']??null)!==$fingerprint)
            return null;
        $preview=OfflineOpenSeoDailyPreview::fromDto($offlineDto);
        if ($preview===null) return null;

        // The tenant in F104 is merely a candidate scope. All identities,
        // versions and GSC actor associations MUST be verified against
        // restricted PostgreSQL rows (not any browser/DTO claim).
        $tenant=$offlineDto['tenantRef'];
        $scope=new GoogleSearchConsoleTenantScopedTransaction($this->db);
        $result=$scope->run([
            'tenantRef'=>$tenant,'actorRef'=>'f108-review-only'
        ],function (PDO $connection) use (
            $firstPartySessionRef,$expectedOrganizationUuid,$tenant,
            $fingerprint,$trustedNow,$preview,$onLockedRows
        ): ?array {
            $sql='SELECT * FROM public.portal_gsc_f108_locked_observation(
                :digest,CAST(:organization AS uuid),:tenant,:property,
                CAST(:observed_at AS bigint)
            )';
            $stmt=$connection->prepare($sql);
            if (!$stmt || !$stmt->execute([
                'digest'=>hash('sha256',$firstPartySessionRef),
                'organization'=>$expectedOrganizationUuid,
                'tenant'=>$tenant,
                'property'=>$fingerprint,
                'observed_at'=>$trustedNow,
            ])) return null;
            $row=$stmt->fetch(PDO::FETCH_ASSOC);
            if (!is_array($row) || $stmt->fetch(PDO::FETCH_ASSOC)!==false ||
                ($row['portal_organization_id']??null)!==
                    $expectedOrganizationUuid ||
                !self::uuid($row['portal_identity_id']??null) ||
                !self::pgTrue($row['recorded_grant_fresh']??null))
                return null;

            foreach (['organization_revision','membership_revision']
                as $field) {
                $revision=filter_var($row[$field]??null,
                    FILTER_VALIDATE_INT,['options'=>['min_range'=>1]]);
                if ($revision===false || $revision>2147483647)
                    return null;
            }
            $issued=filter_var($row['portal_issued_at']??null,
                FILTER_VALIDATE_INT);
            $expires=filter_var($row['portal_expires_at']??null,
                FILTER_VALIDATE_INT);
            // Unlike F107, issuedAt is read from the real session row
            // while locked. Never synthesize issuance from observation.
            if ($issued===false || $expires===false ||
                $issued>$trustedNow || $expires<=$trustedNow ||
                $expires-$issued>7200 || $expires<=$issued)
                return null;

            // Called ONLY from the synthetic concurrency test. The locks
            // remain held until the wrapping transaction commits, so a
            // revocation UPDATE must block (or time out) until then.
            if ($onLockedRows !== null && $onLockedRows()!==true)
                return null;

            return [
                'contractVersion'=>'portal-gsc-f108-atomic-review-lab-v1',
                'status'=>'LOCKED_OBSERVATION_NOT_CUSTOMER_AUTHORIZATION',
                'period'=>$preview->period,
                'summary'=>$preview->summary,
                'missingDatesUnknown'=>$preview->missingDatesUnknown,
                'sessionIssuedAtReadFromDatabase'=>true,
                'firstPartyRowAndGscGrantReadInOneTransaction'=>true,
                'rowLocksHeldDuringReview'=>true,
                'recordedGrantFresh'=>true,
                'permissionForSeoVerified'=>false,
                'sessionAuthenticatedForProduction'=>false,
                'googleOAuthVerifiedNow'=>false,
                'sourceOriginVerified'=>false,
                'customerDisplayAuthorized'=>false,
                'productionReadAuthorized'=>false,
                'publicationAuthorized'=>false,
                'networkPerformed'=>false,
                'releaseStatus'=>'HOLD_TRUSTED_HTTP_OAUTH_AND_APPSEC_REVIEW',
            ];
        });
        return is_array($result)?$result:null;
    }

    private static function pgTrue(mixed $v):bool
    {
        return in_array($v,[true,1,'1','t'],true);
    }

    private static function uuid(mixed $v):bool
    {
        return is_string($v) &&
            preg_match('/\A[0-9a-f]{8}-[0-9a-f]{4}-[1-8][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}\z/D',$v)===1;
    }

    private static function ref(mixed $v):bool
    {
        return is_string($v) &&
            preg_match('/\A[a-z0-9][a-z0-9_-]{2,63}\z/D',$v)===1;
    }

    public static function canAuthorizeCustomer():bool{return false;}
    public static function canQueryGoogle():bool{return false;}
    public static function canPublish():bool{return false;}
}
