<?php

declare(strict_types=1);

namespace App\Labs\IdentityF70;

use PDO;
use Throwable;

/**
 * F70: test-only PostgreSQL CAS for the monotonic TOTP matched counter.
 *
 * NOT an operational MFA handler. Caller-constructed IDs and verification
 * states are insufficient for login: future code must obtain them from
 * authenticated Portal-only authorities and protect rollback with SEC-R03.
 */
final class PortalMfaReplayFence
{
    public function __construct(private readonly PDO $db)
    {
    }

    public function claim(string $identityUuid, int $expectedSecurityRevision, int $matchedCounter): bool
    {
        if (PHP_SAPI !== 'cli'
            || getenv('APP_ENV') !== 'testing'
            || getenv('PORTAL_F70_PG_LAB') !== '1'
            || getenv('PGHOST') !== '127.0.0.1'
            || getenv('PGPORT') !== '5432'
            || getenv('PGDATABASE') !== 'portal_identity_lab'
            || $this->db->inTransaction()
            || $expectedSecurityRevision < 1
            || $matchedCounter < 0 || $matchedCounter > 136748161
            || preg_match(
                '/\A[0-9a-f]{8}-[0-9a-f]{4}-[1-8][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}\z/D',
                $identityUuid,
            ) !== 1) {
            return false;
        }

        try {
            $source = $this->db->query(
                'SELECT current_database() AS db, current_user AS role'
            )->fetch(PDO::FETCH_ASSOC);
            if (!is_array($source)
                || $source['db'] !== 'portal_identity_lab'
                || $source['role'] !== 'postgres') {
                return false;
            }

            $statement = $this->db->prepare(
                'UPDATE portal_identities
                    SET mfa_last_used_counter = :counter, updated_at = now()
                  WHERE id = :identity
                    AND security_revision = :revision
                    AND status = \'active\'
                    AND mfa_confirmed_at IS NOT NULL
                    AND mfa_last_used_counter < :counter_check
                  RETURNING mfa_last_used_counter'
            );
            $statement->execute([
                'counter' => $matchedCounter,
                'identity' => $identityUuid,
                'revision' => $expectedSecurityRevision,
                'counter_check' => $matchedCounter,
            ]);

            $row = $statement->fetch(PDO::FETCH_ASSOC);
            return is_array($row)
                && (int) $row['mfa_last_used_counter'] === $matchedCounter;
        } catch (Throwable) {
            // Errors, lock timeouts and source failures must deny, not allow.
            return false;
        }
    }

    public static function mayIssueSession(): bool
    {
        return false;
    }
}
