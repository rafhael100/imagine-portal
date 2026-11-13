<?php
declare(strict_types=1);

namespace App\Infrastructure\Recovery;

use App\Domain\Identity\Browser\PortalBrowserAuthentication;
use SensitiveParameter;
use Throwable;

/**
 * F388: dormant, negative-only, signed external intent -> local SQL retry.
 *
 * This is a laboratory recovery coordinator, NOT a serving/login controller.
 * No Laravel binding, route, queue, cron, secret provider, or cloud client.
 * It must never be invoked using intent IDs or credentials from a browser.
 */
final readonly class SignedRevocationIntentPgReconcileCandidate
{
    public function __construct(
        private PortalBrowserAuthentication $sessions,
        private SignedRevocationIntentReadbackCandidateVerifier $verifier,
        private string $serverOrganization,
        private string $serverInstance,
        private string $pinnedExternalEd25519PublicHex,
    ) {}

    /**
     * The caller MUST obtain $receipt using a fresh, authenticated remote
     * lookup, then bind the nonce and intent ID to a trusted server-owned
     * retry queue. No such operational queue exists in F388.
     */
    public function inspectRetry(
        #[SensitiveParameter] string $opaqueSessionReference,
        string $trustedIntentId,
        string $expectedFreshNonce,
        array $independentSignedReceipt,
        int $now,
    ):array {
        if(preg_match('/\A[a-f0-9]{64}\z/D',$opaqueSessionReference)!==1
            ||preg_match('/\A[a-f0-9]{32}\z/D',$trustedIntentId)!==1
            ||preg_match('/\A[a-f0-9]{64}\z/D',$expectedFreshNonce)!==1){
            return self::result('RECONCILE_INPUT_DENIED');
        }
        $digest=hash('sha256',$opaqueSessionReference);
        try{
            $assessment=$this->verifier->verify(
                $independentSignedReceipt,
                $this->serverOrganization,
                $this->serverInstance,
                $trustedIntentId,
                $digest,
                $expectedFreshNonce,
                $this->pinnedExternalEd25519PublicHex,
                $now,
            );
            if(($assessment['reasonCode']??null)
                    !=='INTENT_CONFIRMED_PENDING_RECONCILIATION_NO_SERVING'
                ||($assessment['canAuthorizeHttp']??null)!==false
                ||($assessment['canAuthorizeBusiness']??null)!==false
                ||($assessment['canClaimPostgresRevoked']??null)!==false){
                return self::result('INDEPENDENT_INTENT_NOT_VERIFIED');
            }
            // Only the trusted Portal-side SQL role is permitted to revoke.
            // A signed receipt NEVER implies SQL success. The interface's
            // revoke() contract promises success only after durable DB write.
            if(!$this->sessions->revoke($opaqueSessionReference)){
                return self::result('POSTGRES_REVOKE_NOT_CONFIRMED');
            }
            // Re-read current authoritative SQL state. A false active check
            // is only a diagnostic adjunct; revoke() is the required proof.
            if($this->sessions->isActiveBusinessSession($opaqueSessionReference)){
                return self::result('POSTGRES_REVOKE_NOT_CONFIRMED');
            }
            return self::result('LAB_POSTGRES_REVOKE_OBSERVED_STILL_NO_SERVING');
        }catch(Throwable){
            return self::result('POSTGRES_REVOKE_UNAVAILABLE');
        }
    }

    private static function result(string $status):array
    {
        return [
            'schema'=>'imagine.portal.f388.pg18-reconciliation-assessment.v1',
            'status'=>$status,
            'canAuthorizeHttp'=>false,
            'canAuthorizeBusiness'=>false,
            'canIssueSession'=>false,
            'canRecoverFromRestore'=>false,
            'canDeploy'=>false,
            'canClaimPostgresRevoked'=>false,
        ];
    }
}
