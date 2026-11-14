<?php

declare(strict_types=1);

namespace App\Infrastructure\Recovery;

/**
 * F394 review-only ACK body producer; no network, key access or queue.
 *
 * An ACK may be PREPARED only after a separately signed F387 intent was
 * checked and a restricted F391 PostgreSQL read explicitly observed a
 * revoked row. Even then the ACK is a claim, not independent serving proof.
 */
final readonly class Pg18VerifiedRevocationAckPreparationCandidate
{
    public function __construct(
        private SignedRevocationDigestStatusPgReconcileCandidate $reconcile,
        private string $organization,
        private string $instance,
    ) {}

    public function prepare(
        string $intentId,
        string $digest,
        string $freshNonce,
        array $independentIntentReceipt,
        int $now,
    ):array {
        if(!self::hex($intentId,32)||!self::hex($digest,64)
            ||!self::hex($freshNonce,64)
            ||!self::scope($this->organization)
            ||!self::scope($this->instance)){
            return self::no('F394_ACK_INPUT_DENIED');
        }
        $checked=$this->reconcile->inspectAndVerifyRow(
            $intentId,$digest,$freshNonce,$independentIntentReceipt,$now
        );
        if(($checked['status']??null)!=='LAB_PG_ROW_REVOKED_STILL_NO_SERVING'
            ||($checked['canAuthorizeHttp']??null)!==false
            ||($checked['canAuthorizeBusiness']??null)!==false
            ||($checked['canClaimPostgresRevoked']??null)!==false) {
            return self::no('F394_LOCAL_REVOCATION_NOT_VERIFIED');
        }
        $sequence=$independentIntentReceipt['intent_sequence']??null;
        if(!is_int($sequence)||$sequence<1||$sequence>1000000000
            ||($independentIntentReceipt['organization']??null)!==$this->organization
            ||($independentIntentReceipt['instance']??null)!==$this->instance
            ||($independentIntentReceipt['intent_id']??null)!==$intentId
            ||($independentIntentReceipt['session_sha256']??null)!==$digest){
            return self::no('F394_ACK_SCOPE_DENIED');
        }
        // This payload still needs a distinct authenticated F393 publisher.
        return self::no('LAB_ACK_BODY_PREPARED_STILL_NO_SERVING',[
            'organization'=>$this->organization,
            'instance'=>$this->instance,
            'intent_id'=>$intentId,
            'session_sha256'=>$digest,
            'intent_sequence'=>$sequence,
            'local_status'=>'revoked_claimed',
        ]);
    }

    private static function hex(mixed $v,int $len):bool{
        return is_string($v)&&strlen($v)===$len
            &&preg_match('/\A[a-f0-9]+\z/D',$v)===1;
    }
    private static function scope(mixed $v):bool{
        return is_string($v)
            &&preg_match('/\A[A-Za-z0-9_-]{1,64}\z/D',$v)===1;
    }
    private static function no(string $status,?array $body=null):array{
        return [
            'schema'=>'imagine.portal.f394.ack-publication-plan.v1',
            'status'=>$status,
            'ackBody'=>$body,
            'canAuthorizeHttp'=>false,
            'canAuthorizeBusiness'=>false,
            'canIssueSession'=>false,
            'canRecoverFromRestore'=>false,
            'canDeploy'=>false,
            'canClaimPostgresRevoked'=>false,
            'canClaimQueueFullyProcessed'=>false,
        ];
    }
}
