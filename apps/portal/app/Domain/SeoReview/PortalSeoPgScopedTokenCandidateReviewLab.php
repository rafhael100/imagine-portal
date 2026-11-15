<?php
declare(strict_types=1);

namespace App\Domain\SeoReview;

use PDO;
use SensitiveParameter;
use SensitiveParameterValue;

/**
 * SEO-F115 — deny-by-default, test-only candidate metadata review.
 *
 * A token candidate is not useful to a future service just because it
 * decrypts. This review requires the *same tenant, actor and exact GSC
 * property* to have both a fresh recorded grant and an unrevoked encrypted
 * candidate in the SAME PostgreSQL RLS transaction.
 *
 * Does not return the token secret, grant identity, property or tenant;
 * does not prove Google OAuth, authenticate Portal users or make HTTP calls.
 */
final readonly class PortalSeoPgScopedTokenCandidateReviewLab
{
    private readonly GoogleSearchConsoleTenantScopedTransaction $scope;
    private SensitiveParameterValue $key;

    public function __construct(
        PDO $pdo,
        #[SensitiveParameter] string $syntheticEncryptionKey
    ){
        if(strlen($syntheticEncryptionKey)!==32)
            throw new \InvalidArgumentException('F115: invalid local key');
        $this->scope=new GoogleSearchConsoleTenantScopedTransaction($pdo);
        $this->key=new SensitiveParameterValue($syntheticEncryptionKey);
    }

    public function review(mixed $trustedContext,mixed $exactSiteUrl,mixed $now):?array
    {
        if(PHP_SAPI!=='cli' ||
            getenv('APP_ENV')!=='testing' ||
            getenv('CI')!=='true' ||
            getenv('PORTAL_SEO_F115_PG_CI')!=='1' ||
            getenv('PORTAL_GSC_TOKEN_VAULT_LAB')!=='1' ||
            !self::validContext($trustedContext) ||
            !is_int($now)||$now<1600000000||$now>2200000000)
            return null;

        $fingerprint=GoogleSearchConsolePropertyIdentifier::fingerprint(
            $exactSiteUrl
        );
        if($fingerprint===null)return null;

        $result=$this->scope->run($trustedContext,
            function(PDO $db)use(
                $trustedContext,$exactSiteUrl,$fingerprint,$now
            ):?array {
                $tenant=$trustedContext['tenantRef'];
                $actor=$trustedContext['actorRef'];

                // GSC grant registration is NEVER independent proof
                // of live Google property authorization.
                $record=(new GoogleSearchConsoleRecordedPropertyGrant($db))
                    ->inspect($tenant,$actor,$exactSiteUrl,$now);
                if(!is_array($record) ||
                    ($record['recordedGrantFresh']??null)!==true ||
                    ($record['tenantRef']??null)!==$tenant ||
                    ($record['actorRef']??null)!==$actor ||
                    ($record['propertyFingerprint']??null)!==$fingerprint ||
                    ($record['recordedScope']??null)!==
                        GoogleSearchConsoleOAuthPreflight::READONLY_SCOPE ||
                    ($record['googleApiCheckedNow']??null)!==false ||
                    ($record['customerDisplayAuthorized']??null)!==false)
                    return null;

                $secret=(new GoogleSearchConsoleEncryptedTokenVault(
                    $db,$this->key->getValue()
                ))->inspectSyntheticCandidate($tenant,$actor,$fingerprint,$now);
                if($secret===null)return null;

                // Read safe metadata only, and deliberately DO NOT call
                // takeForTrustedBackend(). No candidate token escapes.
                $candidate=$secret->safeReview();
                if(($candidate['contractVersion']??null)!==
                    'portal-gsc-encrypted-token-candidate-review-v1' ||
                    ($candidate['scope']??null)!==
                        GoogleSearchConsoleOAuthPreflight::READONLY_SCOPE ||
                    ($candidate['providerOriginVerified']??null)!==false ||
                    ($candidate['tenantGrantVerified']??null)!==false ||
                    ($candidate['tokenUseAuthorized']??null)!==false ||
                    ($candidate['customerDisplayAuthorized']??null)!==false ||
                    ($candidate['releaseStatus']??null)!==
                        'HOLD_AUTHENTICATED_BACKEND')
                    return null;

                return [
                    'contractVersion'=>
                        'portal-seo-f115-token-grant-coherence-review-lab-v1',
                    'recordedGrantFresh'=>true,
                    'encryptedCandidatePresentForLabReview'=>true,
                    'sameScopedPostgresTransaction'=>true,
                    'candidateTokenReturned'=>false,
                    'liveGooglePropertyVerified'=>false,
                    'realPortalIdentityVerified'=>false,
                    'providerOriginVerified'=>false,
                    'tokenUseAuthorized'=>false,
                    'customerDisplayAuthorized'=>false,
                    'productionReadAuthorized'=>false,
                    'networkPerformed'=>false,
                    'releaseStatus'=>'HOLD_TRUSTED_AUTH_AND_GOOGLE_PROOF'
                ];
            }
        );
        return is_array($result)?$result:null;
    }

    private static function validContext(mixed $ctx):bool
    {
        if(!is_array($ctx)||array_is_list($ctx)||count($ctx)!==2 ||
            !isset($ctx['tenantRef'],$ctx['actorRef']))
            return false;
        foreach(['tenantRef','actorRef'] as $name){
            if(!is_string($ctx[$name]) ||
                preg_match('/\A[a-z0-9][a-z0-9_-]{2,63}\z/D',$ctx[$name])!==1)
                return false;
        }
        return true;
    }
    public static function authorizesGoogleHttp():bool{return false;}
    public static function authorizesTokenUse():bool{return false;}
    public static function authorizesCustomerDisplay():bool{return false;}
}
