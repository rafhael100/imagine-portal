<?php

declare(strict_types=1);

namespace App\Infrastructure\Recovery;

use Throwable;

/**
 * F396: strictly read-only recovery triage. The only path from a signed
 * cursor to a PostgreSQL diagnostic requires an independently signed
 * 30-second lease. An ACK cannot waive the SQL status check.
 *
 * No queues, routes, container bindings, side effects, network actions or
 * serving authorizations. This policy is only a CI candidate.
 */
final readonly class SignedRevocationRecoveryTriageCandidate
{
    public function __construct(
        private SignedRevocationCursorCandidateVerifier $cursorVerifier,
        private SignedRevocationRetryLeaseCandidateVerifier $leaseVerifier,
        private SignedRevocationAckClaimCandidateVerifier $ackVerifier,
        private PortalHashedSessionStatusCandidate $restrictedStatus,
        private string $serverOrganization,
        private string $serverInstance,
        private string $trustedEd25519PublicKeyHex,
    ) {}

    public function inspect(
        array $cursorReceipt,
        int $requestedAfter,
        string $cursorNonce,
        ?array $leaseReceipt,
        ?string $expectedLeaseDigest,
        ?string $leaseNonce,
        ?array $ackReceipt,
        ?string $ackNonce,
        int $now,
    ):array{
        if($requestedAfter<0||$requestedAfter>1000000000
            ||preg_match('/\A[a-f0-9]{64}\z/D',$cursorNonce)!==1){
            return self::blocked('CURSOR_INPUT_DENIED');
        }
        $cursor=$this->cursorVerifier->verify(
            $cursorReceipt,$this->serverOrganization,$this->serverInstance,
            $requestedAfter,$cursorNonce,$this->trustedEd25519PublicKeyHex,$now
        );
        $reason=$cursor['reasonCode']??null;
        if($reason==='CURSOR_NO_INTENT_NOT_AUTHORITY'){
            return self::blocked('NO_NEXT_INTENT_NOT_QUEUE_COMPLETE');
        }
        if($reason!=='CURSOR_INTENT_PENDING_STILL_NO_SERVING'){
            return self::blocked('INDEPENDENT_CURSOR_NOT_VERIFIED');
        }
        $id=$cursorReceipt['intent_id'];
        $digest=$cursorReceipt['session_sha256'];
        $sequence=$cursorReceipt['intent_sequence'];

        // Never query SQL with an unleased caller's untrusted session digest.
        if(!is_array($leaseReceipt)||!is_string($expectedLeaseDigest)
            ||!is_string($leaseNonce)){
            return self::blocked('SIGNED_LEASE_REQUIRED');
        }
        $lease=$this->leaseVerifier->verify(
            $leaseReceipt,$this->serverOrganization,$this->serverInstance,
            $id,$digest,$expectedLeaseDigest,$leaseNonce,
            $this->trustedEd25519PublicKeyHex,$now
        );
        if(($lease['reasonCode']??null)!=='LEASE_RECORDED_NOT_ACK_OR_SERVING'
            ||($leaseReceipt['intent_sequence']??null)!==$sequence){
            return self::blocked('LEASE_NOT_VERIFIED');
        }

        // An ACK claim can be present even when SQL is still active. It is
        // never proof of PostgreSQL completion and never skips this read.
        $hasAck=false;
        if($ackReceipt!==null){
            if(!is_string($ackNonce)){
                return self::blocked('ACK_REQUIRES_FRESH_NONCE');
            }
            $ack=$this->ackVerifier->verify(
                $ackReceipt,$this->serverOrganization,$this->serverInstance,
                $id,$digest,$ackNonce,$this->trustedEd25519PublicKeyHex,$now
            );
            if(($ack['reasonCode']??null)
                !=='ACK_CLAIM_RECORDED_NOT_POSTGRES_PROOF_NO_SERVING'
                ||($ackReceipt['intent_sequence']??null)!==$sequence){
                return self::blocked('ACK_CLAIM_NOT_VERIFIED');
            }
            $hasAck=true;
        }

        try{
            $state=$this->restrictedStatus->inspectSessionDigestStatus(
                $digest,$this->serverOrganization
            );
        }catch(Throwable){
            return self::blocked('POSTGRES_STATUS_UNAVAILABLE');
        }

        if($state==='revoked'){
            // Even with ACK and a restricted SQL read, no off-host atomic
            // end-to-end authorization barrier is established.
            return self::blocked($hasAck
                ?'SQL_REVOKED_WITH_ACK_CLAIM_STILL_NO_SERVING'
                :'SQL_REVOKED_ACK_NOT_PRESENT_STILL_NO_SERVING');
        }
        if($state==='missing')return self::blocked('POSTGRES_SESSION_MISSING_ESCALATE');
        if($state!=='not_revoked'){
            return self::blocked('POSTGRES_STATUS_UNSAFE');
        }
        if($hasAck){
            return self::blocked('ACK_CLAIM_CONTRADICTS_ACTIVE_POSTGRES');
        }
        if($leaseReceipt['attempt_count']>=5){
            return self::blocked('RETRY_BUDGET_EXHAUSTED_ESCALATE');
        }
        if($leaseReceipt['status']==='retry_lease_expired_not_completion'){
            return self::blocked('LEASE_EXPIRED_REQUIRE_NEW_SIGNED_CLAIM');
        }
        return self::blocked('ACTIVE_LEASE_CANDIDATE_STILL_NO_SERVING');
    }

    private static function blocked(string $reason):array {
        return [
            'schema'=>'imagine.portal.f396.recovery-triage.v1',
            'reasonCode'=>$reason,
            'canAuthorizeHttp'=>false,
            'canAuthorizeBusiness'=>false,
            'canIssueSession'=>false,
            'canRecoverFromRestore'=>false,
            'canDeploy'=>false,
            'canClaimPostgresRevoked'=>false,
            'canClaimQueueFullyProcessed'=>false,
            'canSkipReconciliation'=>false,
        ];
    }
}
