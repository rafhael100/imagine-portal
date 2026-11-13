<?php

declare(strict_types=1);

namespace App\Infrastructure\Recovery;

use Throwable;

/**
 * F391: after F390 checked an independent signed intent and executed the
 * restricted PostgreSQL revoke function, read the row's actual SQL status
 * using the separately HMAC-bound tenant-scoped SECURITY DEFINER candidate.
 *
 * This adds no serving authorization. It never exposes SQL role or tenant
 * data through HTTP, and is NOT registered in Laravel.
 */
final readonly class SignedRevocationDigestStatusPgReconcileCandidate
{
    public function __construct(
        private SignedRevocationDigestPgReconcileCandidate $write,
        private PortalHashedSessionStatusCandidate $reader,
        private string $organization,
    ) {}

    public function inspectAndVerifyRow(
        string $trustedIntentId,
        string $sessionSha256,
        string $freshNonce,
        array $independentlySignedReceipt,
        int $now,
    ):array {
        $candidate=$this->write->inspectDigestOnly(
            $trustedIntentId,$sessionSha256,$freshNonce,
            $independentlySignedReceipt,$now
        );
        if(($candidate['status']??null)!=='LAB_REVOKE_FUNCTION_ACCEPTED_NO_STATUS_PROOF'
            ||($candidate['canAuthorizeHttp']??null)!==false
            ||($candidate['canClaimPostgresRevoked']??null)!==false){
            return self::denied('INDEPENDENT_OR_POSTGRES_WRITE_NOT_CONFIRMED');
        }

        try{
            $status=$this->reader->inspectSessionDigestStatus(
                $sessionSha256,$this->organization
            );
            return self::denied(match($status){
                'revoked'=>'LAB_PG_ROW_REVOKED_STILL_NO_SERVING',
                'not_revoked'=>'POSTGRES_REVOCATION_REMAINS_PENDING',
                'missing'=>'POSTGRES_DIGEST_MISSING_NOT_REVOKED',
                'denied'=>'POSTGRES_STATUS_ACCESS_DENIED',
                default=>'POSTGRES_STATUS_INCONSISTENT',
            });
        }catch(Throwable){
            return self::denied('POSTGRES_STATUS_READ_UNAVAILABLE');
        }
    }

    private static function denied(string $status):array{
        return [
            'schema'=>'imagine.portal.f391.digest-status-assessment.v1',
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
