<?php

declare(strict_types=1);

namespace App\Labs\IdentityF75;

use App\Domain\Identity\Security\PortalTotpVerifier;
use App\Domain\Identity\Security\PortalTotpSecretCipher;
use PDO;
use Throwable;

/**
 * F75 offline PostgreSQL-only proof: TOTP secret is decrypted from the
 * currently locked identity row. No caller-supplied TOTP secret is accepted.
 * The caller supplies a disposable server key in CLI tests only. Real use
 * requires independently audited KMS/key rotation, rate limits, session
 * cookies, RLS application roles and external anti-rollback witness.
 * NEVER register this laboratory class as a route, provider or job.
 */
final class PortalEnrolledMfaSessionCompleter
{
    public function __construct(private readonly PDO $db)
    {
    }

    public function complete(
        string $opaqueBearer,
        string $serverEncryptionKey,
        string $otpCode,
        int $nowEpoch,
    ): bool
    {
        if (PHP_SAPI !== 'cli'
            || getenv('APP_ENV') !== 'testing'
            || getenv('PORTAL_F75_PG_LAB') !== '1'
            || getenv('PGHOST') !== '127.0.0.1'
            || getenv('PGPORT') !== '5432'
            || getenv('PGDATABASE') !== 'portal_identity_lab'
            || $nowEpoch < 100000000 || $nowEpoch > 4102444800
            || strlen($opaqueBearer) < 32 || strlen($opaqueBearer) > 128
            || preg_match('/\A[A-Za-z0-9_-]+\z/D', $opaqueBearer) !== 1
            || !extension_loaded('sodium')
            || strlen($serverEncryptionKey) !== SODIUM_CRYPTO_SECRETBOX_KEYBYTES
            || $this->db->inTransaction()) {
            return false;
        }

        try {
            $identity = $this->db->query(
                'SELECT current_database() AS db, current_user AS role'
            )->fetch(PDO::FETCH_ASSOC);
            if (!is_array($identity)
                || $identity['db'] !== 'portal_identity_lab'
                || $identity['role'] !== 'postgres') {
                return false;
            }

            $this->db->beginTransaction();
            // All three mutable authority rows are locked in one transaction,
            // so a separate session cannot reuse the already-claimed counter.
            $query = $this->db->prepare(
                'SELECT s.id AS session_id, s.status AS session_status,
                        s.identity_id, s.identity_revision, s.membership_revision AS session_member_revision,
                        EXTRACT(EPOCH FROM s.issued_at)::bigint AS issued_epoch,
                        EXTRACT(EPOCH FROM s.expires_at)::bigint AS expires_epoch,
                        i.status AS identity_status, i.security_revision, i.mfa_confirmed_at,
                        i.mfa_secret_ciphertext AS sealed_secret,
                        i.mfa_last_used_counter, m.status AS member_status,
                        m.membership_revision AS current_member_revision,
                        o.status AS organization_status
                   FROM portal_auth_sessions s
                   JOIN portal_identities i ON i.id=s.identity_id
                   JOIN portal_memberships m ON m.identity_id=s.identity_id
                                            AND m.organization_id=s.organization_id
                   JOIN portal_organizations o ON o.id=s.organization_id
                  WHERE s.session_sha256=:sha256
                  FOR UPDATE OF s,i,m,o'
            );
            $query->execute(['sha256' => hash('sha256', $opaqueBearer)]);
            $current = $query->fetch(PDO::FETCH_ASSOC);

            if (!is_array($current)
                || $current['session_status'] !== 'pending_mfa'
                || $current['identity_status'] !== 'active'
                || $current['member_status'] !== 'active'
                || $current['organization_status'] !== 'active'
                || $current['mfa_confirmed_at'] === null
                || !is_string($current['sealed_secret'])
                || $current['sealed_secret'] === ''
                || (int)$current['identity_revision'] !== (int)$current['security_revision']
                || (int)$current['session_member_revision'] !== (int)$current['current_member_revision']
                || $nowEpoch < (int)$current['issued_epoch']
                || $nowEpoch >= (int)$current['expires_epoch']
                || (int)$current['expires_epoch'] - (int)$current['issued_epoch'] > 86400) {
                $this->db->rollBack();
                return false;
            }

            // Only decrypt the sealed secret SELECTed from the same locked
            // identity row; never trust a TOTP seed from the request.
            $enrolledSecret = PortalTotpSecretCipher::open(
                $current['sealed_secret'],
                $serverEncryptionKey,
            );
            if ($enrolledSecret === null) {
                $this->db->rollBack();
                return false;
            }
            try {
                $matchedCounter = PortalTotpVerifier::matchCounter(
                    $enrolledSecret,
                    $otpCode,
                    $nowEpoch,
                    (int)$current['mfa_last_used_counter'],
                );
            } finally {
                if (function_exists('sodium_memzero')) {
                    sodium_memzero($enrolledSecret);
                }
            }
            if ($matchedCounter === null) {
                $this->db->rollBack();
                return false;
            }

            $counterWrite = $this->db->prepare(
                "UPDATE portal_identities SET mfa_last_used_counter=:counter, updated_at=now()
                  WHERE id=:identity_id AND security_revision=:revision
                    AND status='active' AND mfa_confirmed_at IS NOT NULL
                    AND mfa_last_used_counter<:check_counter"
            );
            $counterWrite->execute([
                'counter' => $matchedCounter,
                'identity_id' => $current['identity_id'],
                'revision' => $current['security_revision'],
                'check_counter' => $matchedCounter,
            ]);
            if ($counterWrite->rowCount() !== 1) {
                $this->db->rollBack();
                return false;
            }

            $sessionWrite = $this->db->prepare(
                "UPDATE portal_auth_sessions
                    SET status='active', mfa_verified_at=to_timestamp(:now_epoch), updated_at=now()
                  WHERE id=:session_id AND status='pending_mfa'
                    AND identity_revision=:identity_revision
                    AND membership_revision=:member_revision"
            );
            $sessionWrite->execute([
                'now_epoch' => $nowEpoch,
                'session_id' => $current['session_id'],
                'identity_revision' => $current['identity_revision'],
                'member_revision' => $current['session_member_revision'],
            ]);
            if ($sessionWrite->rowCount() !== 1) {
                $this->db->rollBack();
                return false;
            }

            $this->db->commit();
            return true;
        } catch (Throwable) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            return false;
        }
    }

    public static function mayIssueCustomerSession(): bool
    {
        return false;
    }
}
