<?php
declare(strict_types=1);

namespace App\Domain\SeoReview;

use PDO;

/**
 * AA — internal tenant-scoped entrypoint for recorded GSC grants.
 *
 * Every database operation executes inside the same RLS-enforced, transaction-
 * local PostgreSQL tenant scope. This is NOT a Portal authenticator, Google
 * property proof, OAuth callback route or permission to display customer data.
 */
final class GoogleSearchConsoleTenantGrantGateway
{
    private readonly GoogleSearchConsoleTenantScopedTransaction $scope;

    public function __construct(private readonly PDO $pdo)
    {
        $this->scope=new GoogleSearchConsoleTenantScopedTransaction($pdo);
    }

    /**
     * Returns only the existing conservative review contract; even a fresh
     * stored grant does not confirm live Google authorization.
     */
    public function inspectRecordedGrant(
        mixed $trustedContext,
        mixed $exactGoogleSiteUrl,
        mixed $trustedNow
    ):?array {
        if(!self::validContext($trustedContext) ||
            GoogleSearchConsolePropertyIdentifier::fingerprint(
                $exactGoogleSiteUrl
            )===null) return null;

        $result=$this->scope->run(
            $trustedContext,
            function(PDO $db)use($trustedContext,$exactGoogleSiteUrl,$trustedNow){
                return (new GoogleSearchConsoleRecordedPropertyGrant($db))
                    ->inspect(
                        $trustedContext['tenantRef'],
                        $trustedContext['actorRef'],
                        $exactGoogleSiteUrl,
                        $trustedNow
                    );
            }
        );
        return is_array($result)?$result:null;
    }

    /**
     * Revocation and metadata audit share the SAME scoped transaction.
     * If audit INSERT fails, throwing within the closure rolls back the
     * revocation; no success can be returned without both committed.
     */
    public function revokeRecordedGrantWithAudit(
        mixed $trustedContext,
        mixed $exactGoogleSiteUrl,
        mixed $trustedNow
    ):bool {
        if(!self::validContext($trustedContext))return false;
        $fingerprint=GoogleSearchConsolePropertyIdentifier::fingerprint(
            $exactGoogleSiteUrl
        );
        if($fingerprint===null)return false;

        $result=$this->scope->run(
            $trustedContext,
            function(PDO $db)use($trustedContext,$exactGoogleSiteUrl,
                $fingerprint,$trustedNow):bool {
                $changed=(new GoogleSearchConsolePropertyGrantRevocation($db))
                    ->revokeExact(
                        $trustedContext['tenantRef'],
                        $trustedContext['actorRef'],
                        $exactGoogleSiteUrl,
                        $trustedNow
                    );
                if(!$changed)throw new \RuntimeException('GSC grant change refused');
                $logged=(new GoogleSearchConsoleSecurityEventAudit($db))
                    ->record(
                        'property_grant_revoked',
                        $trustedContext['tenantRef'],
                        $trustedContext['actorRef'],
                        $fingerprint,$trustedNow
                    );
                if(!$logged)throw new \RuntimeException('GSC security event refused');
                return true;
            }
        );
        return $result===true;
    }

    public static function authorizesGoogleHttp():bool{return false;}
    public static function authorizesCustomerDisplay():bool{return false;}
    public static function authorizesPortalMembership():bool{return false;}

    private static function validContext(mixed $ctx):bool
    {
        if(!is_array($ctx)||array_is_list($ctx)||
            count($ctx)!==2||
            !isset($ctx['tenantRef'],$ctx['actorRef']))return false;
        foreach(['tenantRef','actorRef'] as $key){
            $value=$ctx[$key];
            if(!is_string($value) ||
                preg_match('/\A[a-z0-9][a-z0-9_-]{2,63}\z/D',$value)!==1)
                return false;
        }
        return true;
    }
}
