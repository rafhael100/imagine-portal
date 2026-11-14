<?php

declare(strict_types=1);

namespace App\Infrastructure\Recovery;

/**
 * F393: independent witness signed ACK *claim*, NOT a verified PostgreSQL
 * completion certificate and NEVER a basis for HTTP/business serving.
 */
final class SignedRevocationAckClaimCandidateVerifier
{
    public const SCHEMA='imagine.portal.f393.signed-ack-claim.v1';
    private const DOMAIN='imagine.portal.f393.ed25519-ack-domain.v1';
    private const KEYS=[
        'schema','organization','instance','intent_id','session_sha256',
        'intent_sequence','observed_sequence','observed_session_floor',
        'claimed_at','issued_at','expires_at','nonce','status','signature',
    ];

    public function verify(
        array $receipt,
        string $organization,
        string $instance,
        string $expectedIntentId,
        string $expectedDigest,
        string $freshNonce,
        string $trustedPublicHex,
        int $now,
    ):array {
        if(!extension_loaded('sodium')
            ||array_keys($receipt)!==self::KEYS
            ||($receipt['schema']??null)!==self::SCHEMA
            ||($receipt['status']??null)!=='ack_recorded_local_claim_unverified'
            ||!self::scope($organization)||!self::scope($instance)
            ||!self::hex($expectedIntentId,32)
            ||!self::hex($expectedDigest,64)
            ||!self::hex($freshNonce,64)
            ||!self::hex($trustedPublicHex,64)
            ||!self::hex($receipt['intent_id']??null,32)
            ||!self::hex($receipt['session_sha256']??null,64)
            ||!self::hex($receipt['nonce']??null,64)
            ||!self::hex($receipt['signature']??null,128)) {
            return self::no('ACK_CONTRACT_DENIED');
        }
        foreach(['intent_sequence','observed_sequence',
            'observed_session_floor','claimed_at']as$key){
            if(!is_int($receipt[$key]??null)
                ||$receipt[$key]<1
                ||$receipt[$key]>($key==='claimed_at'?9999999999:1000000000)){
                return self::no('ACK_CONTRACT_DENIED');
            }
        }
        if(!hash_equals($expectedIntentId,$receipt['intent_id'])
            ||!hash_equals($expectedDigest,$receipt['session_sha256'])
            ||!hash_equals($freshNonce,$receipt['nonce'])){
            return self::no('ACK_REQUEST_BINDING_DENIED');
        }
        $issued=$receipt['issued_at']??null;
        $expires=$receipt['expires_at']??null;
        if(!is_int($issued)||!is_int($expires)
            ||$issued<1||$issued<$now-30||$issued>$now+5
            ||$expires<=$now||$expires<=$issued||$expires>$issued+30){
            return self::no('ACK_FRESHNESS_DENIED');
        }
        if($receipt['observed_sequence']<$receipt['intent_sequence']){
            return self::no('ACK_WITNESS_SEQUENCE_DENIED');
        }
        $sig=hex2bin($receipt['signature']);
        $public=hex2bin($trustedPublicHex);
        if(!is_string($sig)||!is_string($public)
            ||!sodium_crypto_sign_verify_detached(
                $sig,self::canonical($receipt),$public)){
            return self::no('ACK_SIGNATURE_DENIED');
        }
        if(($receipt['organization']??null)!==$organization
            ||($receipt['instance']??null)!==$instance){
            return self::no('ACK_SCOPE_DENIED');
        }
        return self::no('ACK_CLAIM_RECORDED_NOT_POSTGRES_PROOF_NO_SERVING');
    }

    public static function canonical(array $r):string{
        return implode("\n",[
            self::DOMAIN,self::SCHEMA,
            (string)$r['organization'],(string)$r['instance'],
            (string)$r['intent_id'],(string)$r['session_sha256'],
            (string)$r['intent_sequence'],(string)$r['observed_sequence'],
            (string)$r['observed_session_floor'],(string)$r['claimed_at'],
            (string)$r['issued_at'],(string)$r['expires_at'],
            (string)$r['nonce'],(string)$r['status'],
        ]);
    }

    private static function hex(mixed $value,int $n):bool{
        return is_string($value)&&strlen($value)===$n
            &&preg_match('/\A[a-f0-9]+\z/D',$value)===1;
    }
    private static function scope(mixed $v):bool{
        return is_string($v)&&preg_match('/\A[A-Za-z0-9_-]{1,64}\z/D',$v)===1;
    }
    private static function no(string $status):array{
        return [
            'schema'=>'imagine.portal.f393.ack-assessment.v1',
            'reasonCode'=>$status,
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
