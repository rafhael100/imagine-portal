<?php
declare(strict_types=1);

namespace App\Infrastructure\Recovery;

/**
 * F379: non-authoritative verifier of a fresh nonce-bound Ed25519 receipt.
 *
 * Caller MUST use a fresh cryptographically random nonce for EVERY remote
 * witness read, and a public key pinned independently of the restored DB.
 * Never use this result to authorize serving, authentication, recovery, etc.
 */
final class ChallengeBoundWitnessCandidateVerifier
{
    public const SCHEMA = 'imagine.portal.f379.challenged-witness.v1';
    private const DOMAIN = 'imagine.portal.f379.ed25519-witness-domain.v1';

    private const SNAPSHOT_KEYS = [
        'organization','instance','generation','session_revision',
        'membership_revision','witnessed_sequence',
    ];

    private const RECEIPT_KEYS = [
        'schema','organization','instance','generation_floor','session_floor',
        'membership_floor','sequence','issued_at','expires_at','nonce','signature',
    ];

    public function verify(
        array $snapshot,
        array $receipt,
        string $expectedNonce,
        string $trustedPublicKeyHex,
        int $now,
    ): array {
        if (!extension_loaded('sodium')
            || array_keys($snapshot)!==self::SNAPSHOT_KEYS
            || array_keys($receipt)!==self::RECEIPT_KEYS
            || ($receipt['schema']??null)!==self::SCHEMA
            || !self::hex($expectedNonce,64)
            || !self::hex($trustedPublicKeyHex,64)
            || !self::hex($receipt['nonce']??null,64)
            || !self::hex($receipt['signature']??null,128)
            || $now<1) {
            return self::denied('WITNESS_CONTRACT_DENIED');
        }

        foreach(['organization','instance']as$key) {
            if(!self::scope($snapshot[$key])||!self::scope($receipt[$key])) {
                return self::denied('WITNESS_CONTRACT_DENIED');
            }
        }
        foreach(['generation','session_revision','membership_revision','witnessed_sequence']as$key) {
            if(!self::number($snapshot[$key]))return self::denied('WITNESS_CONTRACT_DENIED');
        }
        foreach(['generation_floor','session_floor','membership_floor','sequence']as$key) {
            if(!self::number($receipt[$key]))return self::denied('WITNESS_CONTRACT_DENIED');
        }
        if (!hash_equals($expectedNonce,$receipt['nonce'])) {
            return self::denied('WITNESS_NONCE_DENIED');
        }

        $issued=$receipt['issued_at'];
        $expires=$receipt['expires_at'];
        if (!is_int($issued)||!is_int($expires)
            ||$issued<1||$issued<$now-30||$issued>$now+5
            ||$expires<=$now||$expires<=$issued||$expires>$issued+30) {
            return self::denied('WITNESS_FRESHNESS_DENIED');
        }

        $sig=hex2bin($receipt['signature']);
        $public=hex2bin($trustedPublicKeyHex);
        if(!is_string($sig)||!is_string($public)
            ||!sodium_crypto_sign_verify_detached(
                $sig,self::canonical($receipt),$public
            )) {
            return self::denied('WITNESS_SIGNATURE_DENIED');
        }
        if($snapshot['organization']!==$receipt['organization']
            ||$snapshot['instance']!==$receipt['instance']) {
            return self::denied('WITNESS_SCOPE_DENIED');
        }
        if($snapshot['generation']!==$receipt['generation_floor']
            ||$snapshot['session_revision']!==$receipt['session_floor']
            ||$snapshot['membership_revision']!==$receipt['membership_floor']
            ||$snapshot['witnessed_sequence']!==$receipt['sequence']) {
            return self::denied('WITNESS_EPOCH_CONFLICT');
        }
        return self::denied('WITNESS_MATCHED_STILL_NO_SERVING');
    }

    public static function canonical(array $receipt):string
    {
        return implode("\n",[
            self::DOMAIN,self::SCHEMA,
            (string)$receipt['organization'],(string)$receipt['instance'],
            (string)$receipt['generation_floor'],(string)$receipt['session_floor'],
            (string)$receipt['membership_floor'],(string)$receipt['sequence'],
            (string)$receipt['issued_at'],(string)$receipt['expires_at'],
            (string)$receipt['nonce'],
        ]);
    }

    private static function hex(mixed $value,int $length):bool
    {
        return is_string($value)
            &&strlen($value)===$length
            &&preg_match('/\A[a-f0-9]+\z/D',$value)===1;
    }

    private static function scope(mixed $value):bool
    {
        return is_string($value)
            &&preg_match('/\A[A-Za-z0-9_-]{1,64}\z/D',$value)===1;
    }

    private static function number(mixed $value):bool
    {
        return is_int($value)&&$value>=1&&$value<=1000000000;
    }

    private static function denied(string $reason):array
    {
        return [
            'schema'=>'imagine.portal.f379.nonce-witness-assessment.v1',
            'reasonCode'=>$reason,
            'canAuthorizeHttp'=>false,
            'canAuthorizeBusiness'=>false,
            'canIssueSession'=>false,
            'canRecoverFromRestore'=>false,
            'canDeploy'=>false,
        ];
    }
}
