<?php

declare(strict_types=1);

namespace App\Infrastructure\Recovery;

/**
 * F395: signed retry lease is an observation, not session grant, not
 * completion ACK and not a permission to authorize business HTTP.
 */
final class SignedRevocationRetryLeaseCandidateVerifier
{
    public const SCHEMA='imagine.portal.f395.signed-retry-lease.v1';
    private const DOMAIN='imagine.portal.f395.signed-retry-lease-domain.v1';
    private const KEYS=[
        'schema','organization','instance','intent_id','session_sha256',
        'lease_sha256','intent_sequence','attempt_count',
        'acquired_at','lease_until','observed_sequence',
        'issued_at','expires_at','nonce','status','signature',
    ];

    public function verify(
        array $r,
        string $organization,
        string $instance,
        string $intent,
        string $sessionDigest,
        string $expectedLeaseHash,
        string $nonce,
        string $pinnedPublicHex,
        int $now,
    ):array {
        if (!extension_loaded('sodium')
            ||array_keys($r)!==self::KEYS
            ||($r['schema']??null)!==self::SCHEMA
            ||!self::scope($organization)||!self::scope($instance)
            ||!self::hex($intent,32)||!self::hex($sessionDigest,64)
            ||!self::hex($expectedLeaseHash,64)
            ||!self::hex($nonce,64)||!self::hex($pinnedPublicHex,64)
            ||!self::hex($r['intent_id']??null,32)
            ||!self::hex($r['session_sha256']??null,64)
            ||!self::hex($r['lease_sha256']??null,64)
            ||!self::hex($r['nonce']??null,64)
            ||!self::hex($r['signature']??null,128)
            ||!in_array($r['status']??null,[
                'retry_lease_observed_not_completion',
                'retry_lease_expired_not_completion',
            ],true)) {
            return self::no('LEASE_CONTRACT_DENIED');
        }
        foreach(['intent_sequence','attempt_count','acquired_at',
            'lease_until','observed_sequence']as$key) {
            if(!is_int($r[$key]??null)
                ||$r[$key]<1
                ||$r[$key]>($key==='acquired_at'||$key==='lease_until'
                    ?9999999999:1000000000)) {
                return self::no('LEASE_CONTRACT_DENIED');
            }
        }
        if($r['attempt_count']>5||$r['lease_until']!==$r['acquired_at']+30
            ||$r['observed_sequence']<$r['intent_sequence']) {
            return self::no('LEASE_CONTRACT_DENIED');
        }
        if(!hash_equals($intent,$r['intent_id'])
            ||!hash_equals($sessionDigest,$r['session_sha256'])
            ||!hash_equals($expectedLeaseHash,$r['lease_sha256'])
            ||!hash_equals($nonce,$r['nonce'])) {
            return self::no('LEASE_BINDING_DENIED');
        }
        $issued=$r['issued_at']??null;
        $expires=$r['expires_at']??null;
        if(!is_int($issued)||!is_int($expires)
            ||$issued<1||$issued<$now-30||$issued>$now+5
            ||$expires<=$now||$expires<=$issued||$expires>$issued+30) {
            return self::no('LEASE_FRESHNESS_DENIED');
        }
        if(($r['status']==='retry_lease_observed_not_completion'
                &&$issued>=$r['lease_until'])
            ||($r['status']==='retry_lease_expired_not_completion'
                &&$issued<$r['lease_until'])) {
            return self::no('LEASE_CONTRACT_DENIED');
        }
        $pk=hex2bin($pinnedPublicHex);
        $sig=hex2bin($r['signature']);
        if(!is_string($pk)||!is_string($sig)
            ||!sodium_crypto_sign_verify_detached($sig,
                self::canonical($r),$pk)) {
            return self::no('LEASE_SIGNATURE_DENIED');
        }
        if($r['organization']!==$organization
            ||$r['instance']!==$instance) {
            return self::no('LEASE_SCOPE_DENIED');
        }
        return self::no('LEASE_RECORDED_NOT_ACK_OR_SERVING');
    }

    public static function canonical(array $r):string {
        return implode("\n",[
            self::DOMAIN,self::SCHEMA,
            (string)$r['organization'],(string)$r['instance'],
            (string)$r['intent_id'],(string)$r['session_sha256'],
            (string)$r['lease_sha256'],(string)$r['intent_sequence'],
            (string)$r['attempt_count'],(string)$r['acquired_at'],
            (string)$r['lease_until'],(string)$r['observed_sequence'],
            (string)$r['issued_at'],(string)$r['expires_at'],
            (string)$r['nonce'],(string)$r['status'],
        ]);
    }

    private static function scope(mixed $x):bool {
        return is_string($x)
            &&preg_match('/\A[A-Za-z0-9_-]{1,64}\z/D',$x)===1;
    }
    private static function hex(mixed $x,int $size):bool {
        return is_string($x)&&strlen($x)===$size
            &&preg_match('/\A[a-f0-9]+\z/D',$x)===1;
    }
    private static function no(string $reason):array {
        return [
            'schema'=>'imagine.portal.f395.retry-lease-assessment.v1',
            'reasonCode'=>$reason,
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
