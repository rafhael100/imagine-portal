<?php

declare(strict_types=1);

namespace App\Infrastructure\Recovery;

/**
 * F392: offline verifier of one signed append-only intent page.
 * No Laravel route/service binding, no bearer, NO serving authority.
 */
final class SignedRevocationCursorCandidateVerifier
{
    public const SCHEMA='imagine.portal.f392.signed-intent-cursor.v1';
    private const DOMAIN='imagine.portal.f392.ed25519-cursor-domain.v1';
    private const KEYS=[
        'schema','organization','instance',
        'after_sequence','observed_sequence','observed_session_floor',
        'intent_id','session_sha256','intent_sequence','intent_session_floor',
        'issued_at','expires_at','nonce','status','signature',
    ];

    public function verify(
        array $receipt,
        string $serverOrganization,
        string $serverInstance,
        int $requestedAfter,
        string $freshNonce,
        string $trustedSigningKeyHex,
        int $now,
    ):array{
        if(!extension_loaded('sodium')
            ||array_keys($receipt)!==self::KEYS
            ||($receipt['schema']??null)!==self::SCHEMA
            ||!self::scope($serverOrganization)
            ||!self::scope($serverInstance)
            ||$requestedAfter<0||$requestedAfter>1000000000
            ||!self::hex($freshNonce,64)
            ||!self::hex($trustedSigningKeyHex,64)
            ||!self::hex($receipt['nonce']??null,64)
            ||!self::hex($receipt['signature']??null,128)
            ||!is_int($receipt['after_sequence']??null)
            ||!is_int($receipt['observed_sequence']??null)
            ||!is_int($receipt['observed_session_floor']??null)
            ||$receipt['after_sequence']<0
            ||$receipt['after_sequence']>1000000000
            ||$receipt['observed_sequence']<1
            ||$receipt['observed_sequence']>1000000000
            ||$receipt['observed_session_floor']<1
            ||$receipt['observed_session_floor']>1000000000
            ||!is_int($receipt['intent_sequence']??null)
            ||!is_int($receipt['intent_session_floor']??null)
            ||!is_string($receipt['intent_id']??null)
            ||!is_string($receipt['session_sha256']??null)
            ||!in_array($receipt['status']??null,[
                'pending_local_reconciliation','no_intent_after_cursor',
            ],true)
            ||!self::scope($receipt['organization']??null)
            ||!self::scope($receipt['instance']??null)
        ){
            return self::no('CURSOR_CONTRACT_DENIED');
        }
        if($receipt['after_sequence']!==$requestedAfter
            ||!hash_equals($freshNonce,$receipt['nonce'])){
            return self::no('CURSOR_REQUEST_BINDING_DENIED');
        }
        if($receipt['observed_sequence']<$requestedAfter){
            return self::no('CURSOR_WITNESS_ROLLBACK_DENIED');
        }
        if($receipt['status']==='pending_local_reconciliation'){
            if(!self::hex($receipt['intent_id'],32)
                ||!self::hex($receipt['session_sha256'],64)
                ||$receipt['intent_sequence']<1
                ||$receipt['intent_sequence']>1000000000
                ||$receipt['intent_sequence']<=$requestedAfter
                ||$receipt['intent_sequence']>$receipt['observed_sequence']
                ||$receipt['intent_session_floor']<1
                ||$receipt['intent_session_floor']>$receipt['observed_session_floor']){
                return self::no('CURSOR_CONTRACT_DENIED');
            }
        }elseif($receipt['intent_id']!==''
            ||$receipt['session_sha256']!==''
            ||$receipt['intent_sequence']!==0
            ||$receipt['intent_session_floor']!==0){
            return self::no('CURSOR_CONTRACT_DENIED');
        }
        $issued=$receipt['issued_at']??null;
        $expires=$receipt['expires_at']??null;
        if(!is_int($issued)||!is_int($expires)||$issued<1
            ||$issued<$now-30||$issued>$now+5||$expires<=$now
            ||$expires<=$issued||$expires>$issued+30){
            return self::no('CURSOR_FRESHNESS_DENIED');
        }
        $key=hex2bin($trustedSigningKeyHex);
        $sig=hex2bin($receipt['signature']);
        if(!is_string($key)||!is_string($sig)
            ||!sodium_crypto_sign_verify_detached(
                $sig,self::canonical($receipt),$key)){
            return self::no('CURSOR_SIGNATURE_DENIED');
        }
        if($receipt['organization']!==$serverOrganization
            ||$receipt['instance']!==$serverInstance){
            return self::no('CURSOR_SCOPE_DENIED');
        }
        return self::no(
            $receipt['status']==='pending_local_reconciliation'
                ?'CURSOR_INTENT_PENDING_STILL_NO_SERVING'
                :'CURSOR_NO_INTENT_NOT_AUTHORITY'
        );
    }

    public static function canonical(array $r):string{
        return implode("\n",[
            self::DOMAIN,self::SCHEMA,
            (string)$r['organization'],(string)$r['instance'],
            (string)$r['after_sequence'],(string)$r['observed_sequence'],
            (string)$r['observed_session_floor'],
            (string)$r['intent_id'],(string)$r['session_sha256'],
            (string)$r['intent_sequence'],(string)$r['intent_session_floor'],
            (string)$r['issued_at'],(string)$r['expires_at'],
            (string)$r['nonce'],(string)$r['status'],
        ]);
    }

    private static function scope(mixed $scope):bool{
        return is_string($scope)
            &&preg_match('/\A[A-Za-z0-9_-]{1,64}\z/D',$scope)===1;
    }
    private static function hex(mixed $value,int $size):bool{
        return is_string($value)&&strlen($value)===$size
            &&preg_match('/\A[a-f0-9]+\z/D',$value)===1;
    }
    private static function no(string $code):array{
        return [
            'schema'=>'imagine.portal.f392.cursor-assessment.v1',
            'reasonCode'=>$code,
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
