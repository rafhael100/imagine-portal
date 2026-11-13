<?php
declare(strict_types=1);

namespace App\Infrastructure\Recovery;

/**
 * F387 only: validate receipt from independent intent readback.
 * Confirmation of recording != confirmation of local PostgreSQL revocation.
 * NEVER authorize HTTP, login, business grants, recovery or deploy.
 */
final class SignedRevocationIntentReadbackCandidateVerifier
{
    public const SCHEMA='imagine.portal.f387.revocation-intent-ack.v1';
    private const DOMAIN='imagine.portal.f387.ed25519-intent-ack-domain.v1';

    private const KEYS=[
        'schema','organization','instance','intent_id','session_sha256',
        'intent_sequence','observed_sequence','session_floor',
        'issued_at','expires_at','nonce','status','signature',
    ];

    public function verify(
        array $receipt,
        string $serverOrganization,
        string $serverInstance,
        string $expectedIntentId,
        string $expectedSessionSha256,
        string $freshNonce,
        string $trustedSigningPublicKeyHex,
        int $now,
    ):array{
        if(!extension_loaded('sodium')
            ||array_keys($receipt)!==self::KEYS
            ||($receipt['schema']??null)!==self::SCHEMA
            ||($receipt['status']??null)!=='pending_local_reconciliation'
            ||!self::scope($serverOrganization)||!self::scope($serverInstance)
            ||!self::hex($expectedIntentId,32)
            ||!self::hex($expectedSessionSha256,64)
            ||!self::hex($freshNonce,64)
            ||!self::hex($trustedSigningPublicKeyHex,64)
            ||!self::hex($receipt['intent_id']??null,32)
            ||!self::hex($receipt['session_sha256']??null,64)
            ||!self::hex($receipt['nonce']??null,64)
            ||!self::hex($receipt['signature']??null,128)) {
            return self::denied('INTENT_CONTRACT_DENIED');
        }
        foreach(['intent_sequence','observed_sequence','session_floor']as$key){
            if(!is_int($receipt[$key])
                ||$receipt[$key]<1||$receipt[$key]>1000000000){
                return self::denied('INTENT_CONTRACT_DENIED');
            }
        }
        if(!hash_equals($expectedIntentId,$receipt['intent_id'])
            ||!hash_equals($expectedSessionSha256,$receipt['session_sha256'])
            ||!hash_equals($freshNonce,$receipt['nonce'])) {
            return self::denied('INTENT_REQUEST_BINDING_DENIED');
        }
        $issued=$receipt['issued_at'];
        $expires=$receipt['expires_at'];
        if(!is_int($issued)||!is_int($expires)
            ||$issued<1||$issued<$now-30||$issued>$now+5
            ||$expires<=$now||$expires<=$issued||$expires>$issued+30){
            return self::denied('INTENT_FRESHNESS_DENIED');
        }
        $public=hex2bin($trustedSigningPublicKeyHex);
        $signature=hex2bin($receipt['signature']);
        if(!is_string($public)||!is_string($signature)
            ||!sodium_crypto_sign_verify_detached(
                $signature,self::canonical($receipt),$public)){
            return self::denied('INTENT_SIGNATURE_DENIED');
        }
        if($receipt['organization']!==$serverOrganization
            ||$receipt['instance']!==$serverInstance
            ||!self::scope($receipt['organization'])
            ||!self::scope($receipt['instance'])) {
            return self::denied('INTENT_SCOPE_DENIED');
        }
        if($receipt['observed_sequence']<$receipt['intent_sequence']){
            return self::denied('INTENT_WITNESS_ROLLBACK_DENIED');
        }
        return self::denied('INTENT_CONFIRMED_PENDING_RECONCILIATION_NO_SERVING');
    }

    public static function canonical(array $receipt):string
    {
        return implode("\n",[
            self::DOMAIN,self::SCHEMA,
            (string)$receipt['organization'],(string)$receipt['instance'],
            (string)$receipt['intent_id'],(string)$receipt['session_sha256'],
            (string)$receipt['intent_sequence'],(string)$receipt['observed_sequence'],
            (string)$receipt['session_floor'],(string)$receipt['issued_at'],
            (string)$receipt['expires_at'],(string)$receipt['nonce'],
            (string)$receipt['status'],
        ]);
    }

    private static function scope(mixed $value):bool{
        return is_string($value)
            &&preg_match('/\A[A-Za-z0-9_-]{1,64}\z/D',$value)===1;
    }
    private static function hex(mixed $value,int $length):bool{
        return is_string($value)&&strlen($value)===$length
            &&preg_match('/\A[a-f0-9]+\z/D',$value)===1;
    }
    private static function denied(string $reason):array{
        return [
            'schema'=>'imagine.portal.f387.revocation-lookup-assessment.v1',
            'reasonCode'=>$reason,
            'canAuthorizeHttp'=>false,
            'canAuthorizeBusiness'=>false,
            'canIssueSession'=>false,
            'canRecoverFromRestore'=>false,
            'canDeploy'=>false,
            'canClaimPostgresRevoked'=>false,
        ];
    }
}
