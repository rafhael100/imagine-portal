<?php

declare(strict_types=1);

namespace App\Infrastructure\Identity;

use App\Domain\Identity\Browser\PortalBrowserAuthentication;
use App\Domain\Identity\Security\PortalPasswordCredential;
use App\Domain\Identity\Security\PortalTotpSecretCipher;
use App\Domain\Identity\Security\PortalTotpVerifier;
use PDO;
use RuntimeException;
use SensitiveParameter;
use Throwable;

/**
 * Candidate Portal-only adapter; not registered in the application container.
 * SQL owner is a separate NOLOGIN role; this connection only executes functions.
 * SQL installation, keys, session locking and operational review are prerequisites.
 */
final class PostgresPortalBrowserAuthentication implements PortalBrowserAuthentication
{
    private readonly string $dummyHash;
    private readonly PDO $db;
    private readonly string $database;

    // F318: var_export() reveals private fields, ignoring __debugInfo().
    // Store operational credentials only in a WeakMap entry keyed to the
    // verified adapter instance; this is NOT a defense against arbitrary
    // code execution or full process-memory inspection.
    private static ?\WeakMap $privateKeys = null;

    public function __construct(
        PDO $db,
        string $database,
        #[SensitiveParameter] string $proofKey,
        #[SensitiveParameter] string $totpKey,
        #[SensitiveParameter] string $scopePepper,
    ) {
        if (preg_match('/\\A[a-f0-9]{64}\\z/D', $proofKey) !== 1
            || strlen($totpKey) !== 32 || strlen($scopePepper) < 32 || strlen($scopePepper) > 128) {
            throw new RuntimeException('Portal authentication key configuration required.');
        }
        $this->db = $db;
        $this->database = $database;
        self::$privateKeys ??= new \WeakMap();
        self::$privateKeys[$this] = [
            'proof' => $proofKey,
            'totp' => $totpKey,
            'scope' => $scopePepper,
        ];
        $this->ready();
        $this->dummyHash = PortalPasswordCredential::makeHash(bin2hex(random_bytes(24)))
            ?? throw new RuntimeException('Portal password hashing unavailable.');
    }

    public function __debugInfo(): array
    {
        return ['backend' => 'Portal PostgreSQL', 'keys' => '[redacted]'];
    }

    public function __serialize(): array
    {
        throw new RuntimeException('Authentication adapters cannot be serialized.');
    }

    public function __unserialize(array $data): void
    {
        throw new RuntimeException('Authentication adapters cannot be unserialized.');
    }

    public function __wakeup(): void
    {
        throw new RuntimeException('Authentication adapters cannot be unserialized.');
    }

    private function __clone(): void
    {
    }

    private function privateKey(string $name): string
    {
        if (self::$privateKeys === null || !isset(self::$privateKeys[$this])) {
            throw new RuntimeException('Portal authentication key configuration required.');
        }
        $keys = self::$privateKeys[$this];
        return $keys[$name] ?? throw new RuntimeException('Portal authentication key configuration required.');
    }


    public function beginPassword(string $email, #[SensitiveParameter] string $password, string $organization, string $sourceIp): ?string
    {
        if ($email !== strtolower($email) || strlen($email) > 254
            || filter_var($email, FILTER_VALIDATE_EMAIL) === false
            || preg_match('/\\A[0-9a-f]{8}-[0-9a-f]{4}-[1-8][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}\\z/D', $organization) !== 1
            || strlen($password) > 1024 || filter_var($sourceIp, FILTER_VALIDATE_IP) === false) {
            return null;
        }
        $this->ready();
        if (!$this->claim('ip|' . $sourceIp, 'login') || !$this->claim('account|' . $email, 'login')) {
            return null;
        }
        $row = $this->row('SELECT * FROM public.portal_browser_material(?,CAST(? AS uuid))', [$email,$organization]);
        $valid = PortalPasswordCredential::verify($password, $row['password_hash'] ?? $this->dummyHash);
        if (!$valid || $row === null) {
            return null;
        }
        $reference = bin2hex(random_bytes(32));
        $digest = hash('sha256', $reference);
        $credential = $row['credential_fingerprint'];
        $payload = 'portal-browser-v1|password|' . $email . '|' . $organization . '|' . $digest . '|' . $credential;
        return $this->boolean('SELECT public.portal_browser_begin(?,CAST(? AS uuid),?,?,?)',
            [$email,$organization,$digest,$credential,$this->proof($payload)]) ? $reference : null;
    }

    public function completeMfa(string $pendingReference, #[SensitiveParameter] string $code, string $sourceIp): ?string
    {
        if (!$this->validReference($pendingReference) || preg_match('/\\A[0-9]{6}\\z/D', $code) !== 1
            || filter_var($sourceIp, FILTER_VALIDATE_IP) === false) {
            return null;
        }
        $this->ready();
        if (!$this->claim('ip|' . $sourceIp, 'totp')) {
            return null;
        }
        $pending = hash('sha256', $pendingReference);
        $row = $this->row('SELECT * FROM public.portal_browser_pending(?)', [$pending]);
        if ($row === null || !$this->claim('identity|' . $row['identity_id'], 'totp')) {
            return null;
        }
        $secret = PortalTotpSecretCipher::open($row['mfa_ciphertext'], $this->privateKey('totp'));
        if ($secret === null) {
            return null;
        }
        try {
            $counter = PortalTotpVerifier::matchCounter($secret,$code,(int) $row['epoch'],(int) $row['last_counter']);
        } finally {
            if (function_exists('sodium_memzero')) {
                sodium_memzero($secret);
            }
        }
        if ($counter === null) {
            return null;
        }
        $reference = bin2hex(random_bytes(32));
        $active = hash('sha256', $reference);
        $fingerprint = $row['secret_fingerprint'];
        $payload = 'portal-browser-v1|mfa|' . $pending . '|' . $active . '|' . $counter . '|' . $fingerprint;
        return $this->boolean('SELECT public.portal_browser_complete(?,?,CAST(? AS bigint),?,?)',
            [$pending,$active,$counter,$fingerprint,$this->proof($payload)]) ? $reference : null;
    }

    public function isActiveBusinessSession(string $reference): bool
    {
        if (!$this->validReference($reference)) {
            return false;
        }
        $this->ready();
        return $this->boolean('SELECT public.portal_browser_active(?)', [hash('sha256',$reference)]);
    }

    public function revoke(string $reference): bool
    {
        if (!$this->validReference($reference)) {
            return false;
        }
        $this->ready();
        $digest = hash('sha256',$reference);
        return $this->boolean('SELECT public.portal_browser_revoke(?,?)',
            [$digest,$this->proof('portal-browser-v1|revoke|' . $digest)]);
    }

    private function ready(): void
    {
        // Never acknowledge durable success within a caller-owned transaction.
        if ($this->db->getAttribute(PDO::ATTR_DRIVER_NAME) !== 'pgsql' || $this->db->inTransaction()) {
            throw new RuntimeException('Dedicated autocommit PostgreSQL connection required.');
        }
        $r = $this->row("SELECT current_database() AS db,current_user AS actor,session_user AS login,
            rolsuper,rolbypassrls,rolcreatedb,rolcreaterole,rolinherit,
            EXISTS(SELECT 1 FROM pg_auth_members WHERE member=r.oid) AS inherited
            FROM pg_roles r WHERE rolname=current_user", []);
        if ($r === null || $r['db'] !== $this->database
            || $r['actor'] !== 'portal_browser_runtime' || $r['login'] !== 'portal_browser_runtime') {
            throw new RuntimeException('Dedicated Portal authentication role required.');
        }
        foreach (['rolsuper','rolbypassrls','rolcreatedb','rolcreaterole','rolinherit','inherited'] as $field) {
            if ($this->truth($r[$field])) {
                throw new RuntimeException('Privileged authentication role refused.');
            }
        }
    }

    private function claim(string $scope, string $action): bool
    {
        return $this->boolean('SELECT public.portal_browser_claim_attempt(?,?)',
            [hash_hmac('sha256','portal-browser-v1|' . $scope,$this->privateKey('scope')),$action]);
    }

    private function proof(string $payload): string
    {
        return hash_hmac('sha256',$payload,$this->privateKey('proof'));
    }

    private function validReference(string $value): bool
    {
        return preg_match('/\\A[0-9a-f]{64}\\z/D', $value) === 1;
    }

    private function truth(mixed $value): bool
    {
        return in_array($value,[true,1,'1','t'],true);
    }

    private function boolean(string $sql, array $parameters): bool
    {
        return $this->truth($this->execute($sql,$parameters)->fetchColumn());
    }

    private function row(string $sql, array $parameters): ?array
    {
        $row = $this->execute($sql,$parameters)->fetch(PDO::FETCH_ASSOC);
        return is_array($row) ? $row : null;
    }

    private function execute(string $sql, array $parameters): \PDOStatement
    {
        try {
            $query = $this->db->prepare($sql);
            $query->execute($parameters);
            return $query;
        } catch (Throwable) {
            // No PDO diagnostic, password, OTP, bearer or key in HTTP/log errors.
            throw new RuntimeException('Portal authentication database unavailable.');
        }
    }
}
