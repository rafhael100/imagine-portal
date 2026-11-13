<?php

declare(strict_types=1);

namespace App\Infrastructure\Recovery;

use Throwable;

/**
 * F390: dormant digest-only reconciliation. Original bearer is never needed.
 *
 * A F387 independent Ed25519 receipt must bind the exact digest, intent,
 * organization/instance, ephemeral nonce, validity and signing key before
 * the restricted SQL function can be called. Never bound in Laravel HTTP.
 */
final readonly class SignedRevocationDigestPgReconcileCandidate
{
    public function __construct(
        private PortalHashedSessionRevocationCandidate $sql,
        private SignedRevocationIntentReadbackCandidateVerifier $witness,
        private string $organization,
        private string $instance,
        private string $pinnedPublicKeyHex,
    ) {}

    public function inspectDigestOnly(
        string $trustedIntentId,
        string $sessionSha256,
        string $freshNonce,
        array $signedIndependentReceipt,
        int $now,
    ):array {
        if (!self::hex($trustedIntentId,32)
            || !self::hex($sessionSha256,64)
            || !self::hex($freshNonce,64)) {
            return self::denied('RECONCILE_DIGEST_INPUT_DENIED');
        }
        try {
            $verified=$this->witness->verify(
                $signedIndependentReceipt,
                $this->organization,
                $this->instance,
                $trustedIntentId,
                $sessionSha256,
                $freshNonce,
                $this->pinnedPublicKeyHex,
                $now
            );
            if (($verified['reasonCode']??null)
                    !=='INTENT_CONFIRMED_PENDING_RECONCILIATION_NO_SERVING'
                ||($verified['canAuthorizeHttp']??null)!==false
                ||($verified['canAuthorizeBusiness']??null)!==false
                ||($verified['canClaimPostgresRevoked']??null)!==false) {
                return self::denied('INDEPENDENT_DIGEST_INTENT_NOT_VERIFIED');
            }
            if (!$this->sql->revokeSessionDigest($sessionSha256)) {
                return self::denied('POSTGRES_REVOKE_DIGEST_NOT_CONFIRMED');
            }
            // The existing SQL function returns TRUE even for idempotent
            // no-op/missing rows. Deliberately no status=revoked claim.
            return self::denied('LAB_REVOKE_FUNCTION_ACCEPTED_NO_STATUS_PROOF');
        } catch (Throwable) {
            return self::denied('POSTGRES_REVOKE_DIGEST_UNAVAILABLE');
        }
    }

    private static function hex(mixed $candidate,int $length):bool {
        return is_string($candidate)
            && strlen($candidate)===$length
            && preg_match('/\A[a-f0-9]+\z/D',$candidate)===1;
    }

    private static function denied(string $status):array {
        return [
            'schema'=>'imagine.portal.f390.digest-only-reconciliation.v1',
            'status'=>$status,
            'canAuthorizeHttp'=>false,
            'canAuthorizeBusiness'=>false,
            'canIssueSession'=>false,
            'canRecoverFromRestore'=>false,
            'canDeploy'=>false,
            'canClaimPostgresRevoked'=>false,
            'canAssertSessionExisted'=>false,
        ];
    }
}
