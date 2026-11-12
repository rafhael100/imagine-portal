<?php
declare(strict_types=1);
namespace App\Domain\SeoReview;

use PDO;
use Throwable;

/**
 * PostgreSQL-backed pending PKCE secret ledger. No OAuth network client.
 *
 * Uses a row-unique state digest and an atomic UPDATE...RETURNING to prevent
 * replay, even across concurrent PHP workers. Verifiers are AEAD encrypted
 * using a key supplied by the trusted backend (never committed or logged).
 * The secret returned on successful consumption is server-side only.
 */
final class GoogleSearchConsoleOAuthStateLedger
{
    private readonly string $encryptionKey;

    public function __construct(
        private readonly PDO $pdo,
        #[\SensitiveParameter] string $encryptionKey
    ) {
        if (strlen($encryptionKey)!==32 || !extension_loaded('sodium')) {
            throw new \InvalidArgumentException('GSC state ledger: invalid server configuration');
        }
        $this->encryptionKey=$encryptionKey;
    }

    public function store(mixed $serverOnly, mixed $trustedNow): bool
    {
        if (!self::validRecord($serverOnly)
            || !is_int($trustedNow)
            || $trustedNow!==$serverOnly['issuedAt'])return false;

        $hash=$serverOnly['stateSha256'];
        $tenant=$serverOnly['tenantRef'];
        $actor=$serverOnly['actorRef'];
        $expires=$serverOnly['expiresAt'];
        $aad=self::aad($hash,$tenant,$actor,$expires);
        $nonce=random_bytes(SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_NPUBBYTES);
        $sealed=sodium_crypto_aead_xchacha20poly1305_ietf_encrypt(
            $serverOnly['codeVerifier'],$aad,$nonce,$this->encryptionKey
        );
        $serialized='v1.'.base64_encode($nonce.$sealed);

        try {
            $sql='INSERT INTO portal_gsc_oauth_states
                (state_hash,tenant_ref,actor_ref,sealed_verifier,issued_at,expires_at)
                VALUES (:hash,:tenant,:actor,:sealed,:issued,:expires)
                ON CONFLICT (state_hash) DO NOTHING';
            $stmt=$this->pdo->prepare($sql);
            if (!$stmt)return false;
            $stmt->execute([
                'hash'=>$hash,'tenant'=>$tenant,'actor'=>$actor,
                'sealed'=>$serialized,'issued'=>$trustedNow,'expires'=>$expires
            ]);
            return $stmt->rowCount()===1;
        } catch(Throwable) {
            return false;
        }
    }

    /**
     * Server-only inspection for the separate callback-state classifier.
     * Does not consume and does not authorize a code exchange.
     * NEVER pass the returned record to the client/browser/logs.
     */
    public function inspectPending(
        mixed $callbackState,mixed $trustedContext,mixed $trustedNow
    ): ?array {
        $arg=self::validLookup($callbackState,$trustedContext,$trustedNow);
        if ($arg===null)return null;
        try {
            $stmt=$this->pdo->prepare('SELECT state_hash,tenant_ref,actor_ref,
                sealed_verifier,issued_at,expires_at,consumed_at
                FROM portal_gsc_oauth_states
                WHERE state_hash=:hash AND tenant_ref=:tenant AND actor_ref=:actor
                AND issued_at<=:now_start AND expires_at>=:now_end AND consumed_at IS NULL');
            if (!$stmt)return null;
            $stmt->execute([
                'hash'=>$arg['hash'],'tenant'=>$arg['tenant'],
                'actor'=>$arg['actor'],'now_start'=>$trustedNow,
                'now_end'=>$trustedNow
            ]);
            $row=$stmt->fetch(PDO::FETCH_ASSOC);
            if (!is_array($row))return null;
            $verifier=$this->open($row);
            if ($verifier===null)return null;
            return [
                'tenantRef'=>$row['tenant_ref'],
                'actorRef'=>$row['actor_ref'],
                'stateSha256'=>$row['state_hash'],
                'codeVerifier'=>$verifier,
                'issuedAt'=>(int)$row['issued_at'],
                'expiresAt'=>(int)$row['expires_at'],
                'consumed'=>false
            ];
        } catch(Throwable) {
            return null;
        }
    }

    /**
     * Exactly one winner across competing workers. The consumed verifier
     * is deliberately returned as an object with private storage, not a JSON
     * payload. Caller MUST use it only in a reviewed trusted OAuth backend.
     */
    public function consume(
        mixed $callbackState,mixed $trustedContext,mixed $trustedNow
    ): ?GoogleSearchConsoleOAuthConsumedVerifier {
        $arg=self::validLookup($callbackState,$trustedContext,$trustedNow);
        if ($arg===null)return null;
        try {
            $stmt=$this->pdo->prepare('UPDATE portal_gsc_oauth_states
                SET consumed_at=:consumed
                WHERE state_hash=:hash AND tenant_ref=:tenant AND actor_ref=:actor
                AND issued_at<=:now_start AND expires_at>=:now_end AND consumed_at IS NULL
                RETURNING state_hash,tenant_ref,actor_ref,sealed_verifier,
                    issued_at,expires_at,consumed_at');
            if (!$stmt)return null;
            $stmt->execute([
                'hash'=>$arg['hash'],'tenant'=>$arg['tenant'],
                'actor'=>$arg['actor'],'now_start'=>$trustedNow,
                'now_end'=>$trustedNow,'consumed'=>$trustedNow
            ]);
            $row=$stmt->fetch(PDO::FETCH_ASSOC);
            if (!is_array($row))return null;
            $verifier=$this->open($row);
            if ($verifier===null)return null; // fail closed; state stays consumed
            return new GoogleSearchConsoleOAuthConsumedVerifier($verifier);
        } catch(Throwable) {
            return null;
        }
    }

    public static function authorizesOutboundGoogleHttp(): bool{return false;}
    public static function authorizesCustomerDisplay(): bool{return false;}

    private function open(array $row): ?string
    {
        $sealed=$row['sealed_verifier']??null;
        if (!is_string($sealed)||!str_starts_with($sealed,'v1.'))return null;
        $bytes=base64_decode(substr($sealed,3),true);
        if (!is_string($bytes)||strlen($bytes)<42)return null;
        $nonce=substr($bytes,0,SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_NPUBBYTES);
        $ciphertext=substr($bytes,SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_NPUBBYTES);
        $aad=self::aad((string)$row['state_hash'],(string)$row['tenant_ref'],
            (string)$row['actor_ref'],(int)$row['expires_at']);
        $plain=sodium_crypto_aead_xchacha20poly1305_ietf_decrypt(
            $ciphertext,$aad,$nonce,$this->encryptionKey
        );
        return is_string($plain) && self::validVerifier($plain)?$plain:null;
    }

    private static function aad(string $hash,string $tenant,string $actor,int $expires): string
    {
        return 'gsc-pkce-state-v1|'.$hash.'|'.$tenant.'|'.$actor.'|'.$expires;
    }

    private static function validRecord(mixed $r): bool
    {
        if (!self::exact($r,[
            'tenantRef','actorRef','stateSha256','codeVerifier',
            'issuedAt','expiresAt','consumed'
        ]))return false;
        return self::validRef($r['tenantRef'])&&self::validRef($r['actorRef'])
            &&is_string($r['stateSha256'])
            &&preg_match('/\A[0-9a-f]{64}\z/D',$r['stateSha256'])===1
            &&self::validVerifier($r['codeVerifier'])
            &&is_int($r['issuedAt']) && is_int($r['expiresAt'])
            &&$r['issuedAt']>=1600000000 &&$r['issuedAt']<=2200000000
            &&$r['expiresAt']-$r['issuedAt']===600
            &&$r['consumed']===false;
    }

    private static function validLookup(mixed $state,mixed $context,mixed $now): ?array
    {
        if (!is_string($state)||preg_match('/\A[A-Za-z0-9_-]{43}\z/D',$state)!==1
            ||!self::exact($context,['tenantRef','actorRef'])
            ||!self::validRef($context['tenantRef'])
            ||!self::validRef($context['actorRef'])
            ||!is_int($now)||$now<1600000000||$now>2200000600)return null;
        return [
            'hash'=>hash('sha256',$state),
            'tenant'=>$context['tenantRef'],
            'actor'=>$context['actorRef'],
            'now'=>$now
        ];
    }
    private static function validVerifier(mixed $value): bool
    {
        return is_string($value)
            &&preg_match('/\A[A-Za-z0-9_-]{43}\z/D',$value)===1;
    }
    private static function validRef(mixed $ref): bool
    {
        return is_string($ref)
            &&preg_match('/\A[a-z0-9][a-z0-9_-]{2,63}\z/D',$ref)===1;
    }
    private static function exact(mixed $value,array $allowed): bool
    {
        if (!is_array($value)||array_is_list($value)||count($value)!==count($allowed))
            return false;
        $keys=array_keys($value);
        sort($keys);sort($allowed);
        return $keys===$allowed;
    }
}

/**
 * Opaque server-only secret. json_encode() has no public fields to serialize.
 * An authenticated token-exchange implementation must explicitly request it.
 */
final class GoogleSearchConsoleOAuthConsumedVerifier
{
    private readonly \SensitiveParameterValue $secret;

    public function __construct(#[\SensitiveParameter] string $verifier)
    {
        $this->secret=new \SensitiveParameterValue($verifier);
    }

    public function revealForTrustedBackend(): string
    {
        return $this->secret->getValue();
    }

    public function __debugInfo(): array
    {
        return ['codeVerifier'=>'[REDACTED]'];
    }
}
