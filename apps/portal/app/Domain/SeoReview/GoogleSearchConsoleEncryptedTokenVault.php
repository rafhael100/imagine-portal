<?php
declare(strict_types=1);

namespace App\Domain\SeoReview;

use PDO;
use Throwable;

/**
 * S — encrypted storage of *candidate* GSC OAuth tokens in a restricted
 * PostgreSQL review schema. No public routes or authorized Google traffic.
 *
 * Caller MUST independently authenticate the tenant/actor, verify live Google
 * property access and scope, and supply the 32-byte key from a trusted vault.
 * This class itself NEVER marks those requirements as satisfied.
 */
final class GoogleSearchConsoleEncryptedTokenVault
{
    private readonly \SensitiveParameterValue $key;
    private readonly ?\SensitiveParameterValue $previousKey;

    public function __construct(
        private readonly PDO $pdo,
        #[\SensitiveParameter] string $encryptionKey,
        private readonly int $keyVersion=1,
        #[\SensitiveParameter] ?string $previousEncryptionKey=null
    ) {
        if(strlen($encryptionKey)!==32 ||
            !in_array($keyVersion,[1,2],true) ||
            ($previousEncryptionKey!==null &&
                (strlen($previousEncryptionKey)!==32 || $keyVersion!==2 ||
                 hash_equals($previousEncryptionKey,$encryptionKey))) ||
            !extension_loaded('sodium'))
            throw new \InvalidArgumentException('GSC vault: invalid server config');
        $this->key=new \SensitiveParameterValue($encryptionKey);
        $this->previousKey=$previousEncryptionKey===null?null:
            new \SensitiveParameterValue($previousEncryptionKey);
    }

    /**
     * No automatic approval from candidate parsing or from a SQL grant row.
     * This method must NOT be connected to an HTTP route before AppSec review.
     */
    public function storeSyntheticCandidate(
        mixed $trustedTenant,
        mixed $trustedActor,
        mixed $propertyFingerprint,
        GoogleSearchConsoleOAuthTokenCandidate $candidate,
        mixed $trustedNow
    ):bool {
        if(!self::validContext($trustedTenant,$trustedActor,$propertyFingerprint,
            $trustedNow))return false;
        $data=$candidate->takeForTrustedVault();
        $safe=$candidate->safeReview();
        if(!self::validTokens($data) ||
            $safe['tokenPersistenceAuthorized']!==false ||
            $safe['customerDisplayAuthorized']!==false ||
            $data['expiresAt']<=$trustedNow ||
            $data['expiresAt']>$trustedNow+3600)return false;
        $expires=$data['expiresAt'];
        $aad=self::aad($trustedTenant,$trustedActor,$propertyFingerprint,$expires);
        $nonce=random_bytes(SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_NPUBBYTES);
        $cipher=sodium_crypto_aead_xchacha20poly1305_ietf_encrypt(
            json_encode($data,JSON_THROW_ON_ERROR),$aad,$nonce,
            $this->key->getValue()
        );
        $sealed='v'.$this->keyVersion.'.'.base64_encode($nonce.$cipher);
        try {
            $stmt=$this->pdo->prepare('INSERT INTO portal_gsc_oauth_token_candidates
                (tenant_ref,actor_ref,property_fingerprint,scope,
                 sealed_tokens,issued_at,expires_at)
                VALUES (:tenant,:actor,:property,:scope,:sealed,:issued,:expires)
                ON CONFLICT (tenant_ref,actor_ref,property_fingerprint) DO NOTHING');
            if(!$stmt)return false;
            $stmt->execute([
                'tenant'=>$trustedTenant,'actor'=>$trustedActor,
                'property'=>$propertyFingerprint,
                'scope'=>GoogleSearchConsoleOAuthPreflight::READONLY_SCOPE,
                'sealed'=>$sealed,
                'issued'=>$trustedNow,'expires'=>$expires
            ]);
            return $stmt->rowCount()===1;
        }catch(Throwable){return false;}
    }

    /**
     * Backend-only lookup, returns an opaque secret, not customer payload.
     */
    public function inspectSyntheticCandidate(
        mixed $trustedTenant,
        mixed $trustedActor,
        mixed $propertyFingerprint,
        mixed $trustedNow
    ):?GoogleSearchConsoleEncryptedTokenSecret {
        if(!self::validContext($trustedTenant,$trustedActor,
            $propertyFingerprint,$trustedNow))return null;
        try{
            $stmt=$this->pdo->prepare('SELECT sealed_tokens,scope,expires_at
                FROM portal_gsc_oauth_token_candidates
                WHERE tenant_ref=:tenant AND actor_ref=:actor
                AND property_fingerprint=:property AND revoked_at IS NULL
                AND issued_at<=:now_issued AND expires_at>=:now_expires
                LIMIT 1');
            if(!$stmt)return null;
            $stmt->execute([
                'tenant'=>$trustedTenant,'actor'=>$trustedActor,
                'property'=>$propertyFingerprint,'now_issued'=>$trustedNow,
                'now_expires'=>$trustedNow
            ]);
            $row=$stmt->fetch(PDO::FETCH_ASSOC);
            if(!is_array($row)||
                $row['scope']!==GoogleSearchConsoleOAuthPreflight::READONLY_SCOPE)
                return null;
            $expires=(int)$row['expires_at'];
            $sealed=$row['sealed_tokens'];
            if(!is_string($sealed)||
               preg_match('/\Av([12])\./D',$sealed,$m)!==1)return null;
            $sealedVersion=(int)$m[1];
            $key=null;
            if($sealedVersion===$this->keyVersion){
                $key=$this->key->getValue();
            }elseif($sealedVersion===1 && $this->keyVersion===2 &&
                    $this->previousKey!==null){
                $key=$this->previousKey->getValue();
            }
            if($key===null)return null;
            $bytes=base64_decode(substr($sealed,3),true);
            if(!is_string($bytes) ||
                strlen($bytes)<SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_NPUBBYTES+16)
                return null;
            $nonce=substr($bytes,0,SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_NPUBBYTES);
            $cipher=substr($bytes,SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_NPUBBYTES);
            $plain=sodium_crypto_aead_xchacha20poly1305_ietf_decrypt(
                $cipher,self::aad($trustedTenant,$trustedActor,
                    $propertyFingerprint,$expires),
                $nonce,$key
            );
            if(!is_string($plain))return null;
            $data=json_decode($plain,true,8,JSON_THROW_ON_ERROR);
            if(!self::validTokens($data) || $data['expiresAt']!==$expires)
                return null;
            return new GoogleSearchConsoleEncryptedTokenSecret($data);
        }catch(Throwable){return null;}
    }

    /**
     * One-record compare-and-swap re-encryption from key version 1 to 2.
     * The new-version vault must be configured with current and previous key.
     * Never returns token material; read windows stay isolated by tenant.
     */
    public function rotateSyntheticCandidate(
        mixed $trustedTenant,
        mixed $trustedActor,
        mixed $propertyFingerprint,
        mixed $trustedNow
    ):bool {
        if($this->keyVersion!==2 || $this->previousKey===null ||
            !self::validContext($trustedTenant,$trustedActor,
                $propertyFingerprint,$trustedNow))return false;
        try {
            $stmt=$this->pdo->prepare('SELECT sealed_tokens,expires_at
                FROM portal_gsc_oauth_token_candidates
                WHERE tenant_ref=:tenant AND actor_ref=:actor
                AND property_fingerprint=:property AND revoked_at IS NULL
                AND issued_at<=:now_issued AND expires_at>=:now_expires
                LIMIT 1');
            if(!$stmt)return false;
            $stmt->execute([
                'tenant'=>$trustedTenant,'actor'=>$trustedActor,
                'property'=>$propertyFingerprint,
                'now_issued'=>$trustedNow,'now_expires'=>$trustedNow
            ]);
            $row=$stmt->fetch(PDO::FETCH_ASSOC);
            if(!is_array($row)||!is_string($row['sealed_tokens'])||
                !str_starts_with($row['sealed_tokens'],'v1.'))return false;
            $old=$row['sealed_tokens'];
            $secret=$this->inspectSyntheticCandidate(
                $trustedTenant,$trustedActor,$propertyFingerprint,$trustedNow
            );
            if($secret===null)return false;
            $data=$secret->takeForTrustedBackend();
            if(!self::validTokens($data))return false;
            $expires=(int)$row['expires_at'];
            if($expires!==$data['expiresAt'])return false;
            $nonce=random_bytes(SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_NPUBBYTES);
            $cipher=sodium_crypto_aead_xchacha20poly1305_ietf_encrypt(
                json_encode($data,JSON_THROW_ON_ERROR),
                self::aad($trustedTenant,$trustedActor,$propertyFingerprint,$expires),
                $nonce,$this->key->getValue()
            );
            $updated='v2.'.base64_encode($nonce.$cipher);
            $update=$this->pdo->prepare('UPDATE portal_gsc_oauth_token_candidates
                SET sealed_tokens=:updated
                WHERE tenant_ref=:tenant AND actor_ref=:actor
                AND property_fingerprint=:property
                AND sealed_tokens=:old AND revoked_at IS NULL
                AND issued_at<=:now_issued AND expires_at>=:now_expires');
            if(!$update)return false;
            $update->execute([
                'updated'=>$updated,'tenant'=>$trustedTenant,
                'actor'=>$trustedActor,'property'=>$propertyFingerprint,
                'old'=>$old,'now_issued'=>$trustedNow,'now_expires'=>$trustedNow
            ]);
            return $update->rowCount()===1;
        }catch(Throwable){return false;}
    }

    public function revokeSyntheticCandidate(
        mixed $trustedTenant,
        mixed $trustedActor,
        mixed $propertyFingerprint,
        mixed $trustedNow
    ):bool {
        if(!self::validContext($trustedTenant,$trustedActor,
            $propertyFingerprint,$trustedNow))return false;
        try{
            $stmt=$this->pdo->prepare('UPDATE portal_gsc_oauth_token_candidates
                SET revoked_at=:revoked
                WHERE tenant_ref=:tenant AND actor_ref=:actor
                AND property_fingerprint=:property AND revoked_at IS NULL
                AND issued_at<=:now_issued
                RETURNING property_fingerprint');
            if(!$stmt)return false;
            $stmt->execute([
                'revoked'=>$trustedNow,'tenant'=>$trustedTenant,
                'actor'=>$trustedActor,'property'=>$propertyFingerprint,
                'now_issued'=>$trustedNow
            ]);
            return is_array($stmt->fetch(PDO::FETCH_ASSOC));
        }catch(Throwable){return false;}
    }

    public static function authorizesGoogleHttp():bool{return false;}
    public static function authorizesTokenUse():bool{return false;}
    public static function authorizesCustomerDisplay():bool{return false;}

    private static function aad(string $tenant,string $actor,string $property,int $expires):string
    {
        return 'gsc-oauth-token-candidate-v1|'.$tenant.'|'.$actor.'|'.$property.'|'.$expires;
    }
    private static function validContext(mixed $tenant,mixed $actor,mixed $property,mixed $now):bool
    {
        return self::validRef($tenant) && self::validRef($actor) &&
            is_string($property) &&
            preg_match('/\A[0-9a-f]{64}\z/D',$property)===1 &&
            is_int($now)&&$now>=1600000000&&$now<=2200000000;
    }
    private static function validRef(mixed $v):bool
    {
        return is_string($v) &&
            preg_match('/\A[a-z0-9][a-z0-9_-]{2,63}\z/D',$v)===1;
    }
    private static function validTokens(mixed $v):bool
    {
        if(!is_array($v)||array_is_list($v))return false;
        $keys=array_keys($v);sort($keys);
        $required=['accessToken','expiresAt','scope'];
        $withRefresh=[...$required,'refreshToken'];
        sort($required);sort($withRefresh);
        if($keys!==$required && $keys!==$withRefresh)return false;
        if(!is_int($v['expiresAt']) ||
           $v['scope']!==GoogleSearchConsoleOAuthPreflight::READONLY_SCOPE)
            return false;
        foreach(['accessToken','refreshToken'] as $name){
            if(!array_key_exists($name,$v))continue;
            if(!is_string($v[$name]) ||
               preg_match('/\A[\x21-\x7e]{20,4096}\z/D',$v[$name])!==1)
                return false;
        }
        return true;
    }
}

final class GoogleSearchConsoleEncryptedTokenSecret
{
    private readonly \SensitiveParameterValue $value;
    public function __construct(#[\SensitiveParameter] array $value)
    {
        $this->value=new \SensitiveParameterValue($value);
    }
    public function safeReview():array
    {
        return [
            'contractVersion'=>'portal-gsc-encrypted-token-candidate-review-v1',
            'scope'=>GoogleSearchConsoleOAuthPreflight::READONLY_SCOPE,
            'expiresAt'=>$this->value->getValue()['expiresAt'],
            'containsRefreshToken'=>array_key_exists(
                'refreshToken',$this->value->getValue()),
            'providerOriginVerified'=>false,
            'tenantGrantVerified'=>false,
            'tokenUseAuthorized'=>false,
            'customerDisplayAuthorized'=>false,
            'releaseStatus'=>'HOLD_AUTHENTICATED_BACKEND',
        ];
    }
    public function takeForTrustedBackend():array{return $this->value->getValue();}
    public function __debugInfo():array{return $this->safeReview();}
}
