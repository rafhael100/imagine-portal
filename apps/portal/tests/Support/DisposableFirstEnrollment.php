<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Domain\Identity\Security\PortalPasswordCredential;
use App\Domain\Identity\Security\PortalTotpSecretCipher;
use App\Domain\Identity\Security\PortalTotpVerifier;
use PDO;
use RuntimeException;
use SensitiveParameter;
use Throwable;

/**
 * Lab-only enrollment rehearsal, NEVER registered as an app service or route.
 * The email proof is returned ONLY to the test harness, not delivered to an
 * operator. This is not a real email provider, invite issuer or admin flow.
 */
final readonly class DisposableFirstEnrollment
{
    public function __construct(private PDO $db)
    {
    }

    /** Random proof for a fictional email; never use with actual people. */
    public function issueEmailProof(string $identityId): string
    {
        $this->requireLab();
        $token = bin2hex(random_bytes(32));
        return $this->transaction(function () use ($identityId, $token): string {
            $identity = $this->identity($identityId);
            if ($identity === null || $identity['status'] !== 'invited'
                || $identity['email_verified_at'] !== null
                || !str_ends_with($identity['email_normalized'], '@example.invalid')) {
                throw new RuntimeException('Enrollment precondition refused.');
            }
            $query = $this->db->prepare(
                "INSERT INTO portal_browser_private.lab_initial_email_proofs
                    (identity_id,proof_sha256,expires_at)
                 VALUES(CAST(? AS uuid),?,clock_timestamp()+interval '10 minutes')
                 ON CONFLICT (identity_id) DO NOTHING"
            );
            $query->execute([$identityId, hash('sha256', $token)]);
            if ($query->rowCount() !== 1) {
                throw new RuntimeException('Enrollment proof already issued.');
            }
            return $token;
        });
    }

    public function confirmEmail(string $identityId, #[SensitiveParameter] string $proof): bool
    {
        $this->requireLab();
        if (preg_match('/\A[a-f0-9]{64}\z/D', $proof) !== 1) {
            return false;
        }
        return $this->transaction(function () use ($identityId, $proof): bool {
            $identity = $this->identity($identityId);
            if ($identity === null || $identity['status'] !== 'invited'
                || $identity['email_verified_at'] !== null
                || !str_ends_with($identity['email_normalized'], '@example.invalid')) {
                return false;
            }
            $claim = $this->db->prepare(
                "UPDATE portal_browser_private.lab_initial_email_proofs
                 SET consumed_at=clock_timestamp()
                 WHERE identity_id=CAST(? AS uuid) AND proof_sha256=?
                   AND consumed_at IS NULL AND expires_at>clock_timestamp()"
            );
            $claim->execute([$identityId, hash('sha256', $proof)]);
            if ($claim->rowCount() !== 1) {
                return false;
            }
            $update = $this->db->prepare(
                "UPDATE public.portal_identities
                 SET email_verified_at=clock_timestamp(), updated_at=clock_timestamp()
                 WHERE id=CAST(? AS uuid) AND status='invited'
                   AND email_verified_at IS NULL"
            );
            $update->execute([$identityId]);
            if ($update->rowCount() !== 1) {
                throw new RuntimeException('Enrollment email update unavailable.');
            }
            return true;
        });
    }

    public function setFirstPassword(string $identityId, #[SensitiveParameter] string $password): bool
    {
        $this->requireLab();
        // Never accept an actual customer's password in this fixture.
        if (!str_starts_with($password, 'synthetic-')) {
            return false;
        }
        $hash = PortalPasswordCredential::makeHash($password);
        if ($hash === null) {
            return false;
        }
        return $this->transaction(function () use ($identityId, $hash): bool {
            $identity = $this->identity($identityId);
            if ($identity === null || $identity['status'] !== 'invited'
                || $identity['email_verified_at'] === null
                || $identity['password_hash'] !== null) {
                return false;
            }
            $query = $this->db->prepare(
                "UPDATE public.portal_identities
                 SET password_hash=?, updated_at=clock_timestamp()
                 WHERE id=CAST(? AS uuid) AND status='invited'
                   AND email_verified_at IS NOT NULL AND password_hash IS NULL
                   AND mfa_secret_ciphertext IS NULL"
            );
            $query->execute([$hash, $identityId]);
            return $query->rowCount() === 1;
        });
    }

    public function confirmMfaAndActivate(
        string $identityId,
        string $organizationId,
        #[SensitiveParameter] string $base32Secret,
        #[SensitiveParameter] string $code,
        #[SensitiveParameter] string $key,
    ): bool {
        $this->requireLab();
        // Public RFC example secret is safe ONLY as a disposable test fixture.
        if ($base32Secret !== 'GEZDGNBVGY3TQOJQGEZDGNBVGY3TQOJQ') {
            return false;
        }
        $sealed = PortalTotpSecretCipher::seal($base32Secret, $key);
        if ($sealed === null) {
            return false;
        }
        return $this->transaction(function () use ($identityId, $organizationId, $base32Secret, $code, $sealed): bool {
            $q = $this->db->prepare(
                "SELECT i.status AS identity_status,i.email_normalized,
                        i.email_verified_at,i.password_hash,i.mfa_secret_ciphertext,
                        i.mfa_confirmed_at,i.mfa_last_used_counter,
                        m.id AS membership_id,m.status AS member_status,m.role,
                        o.status AS organization_status
                 FROM public.portal_identities i
                 JOIN public.portal_memberships m ON m.identity_id=i.id
                 JOIN public.portal_organizations o ON o.id=m.organization_id
                 WHERE i.id=CAST(? AS uuid) AND o.id=CAST(? AS uuid)
                 FOR UPDATE OF i,m,o"
            );
            $q->execute([$identityId,$organizationId]);
            $row = $q->fetch(PDO::FETCH_ASSOC);
            if (!is_array($row) || $row['identity_status'] !== 'invited'
                || $row['member_status'] !== 'invited' || $row['role'] !== 'owner'
                || $row['organization_status'] !== 'active'
                || !str_ends_with($row['email_normalized'], '@example.invalid')
                || $row['email_verified_at'] === null
                || $row['password_hash'] === null
                || $row['mfa_secret_ciphertext'] !== null
                || $row['mfa_confirmed_at'] !== null
                || (int) $row['mfa_last_used_counter'] !== -1) {
                return false;
            }
            foreach ([
                ['SELECT count(*) FROM public.portal_auth_sessions WHERE identity_id=CAST(? AS uuid)', $identityId],
                ['SELECT count(*) FROM public.portal_membership_grants WHERE membership_id=CAST(? AS uuid)', $row['membership_id']],
            ] as [$sql,$id]) {
                $check = $this->db->prepare($sql);
                $check->execute([$id]);
                if ((int) $check->fetchColumn() !== 0) {
                    return false;
                }
            }
            $claim = $this->db->prepare(
                "SELECT consumed_at FROM portal_browser_private.lab_initial_email_proofs
                 WHERE identity_id=CAST(? AS uuid) FOR UPDATE"
            );
            $claim->execute([$identityId]);
            $receipt = $claim->fetch(PDO::FETCH_ASSOC);
            if (!is_array($receipt) || $receipt['consumed_at'] === null) {
                return false;
            }
            $now = (int) $this->db->query(
                'SELECT floor(extract(epoch FROM clock_timestamp()))::bigint'
            )->fetchColumn();
            $counter = PortalTotpVerifier::matchCounter($base32Secret, $code, $now, -1);
            if ($counter === null) {
                return false;
            }
            $person = $this->db->prepare(
                "UPDATE public.portal_identities
                 SET status='active',mfa_secret_ciphertext=?,mfa_confirmed_at=clock_timestamp(),
                     mfa_last_used_counter=?,updated_at=clock_timestamp()
                 WHERE id=CAST(? AS uuid) AND status='invited'
                   AND email_verified_at IS NOT NULL AND password_hash IS NOT NULL
                   AND mfa_secret_ciphertext IS NULL AND mfa_last_used_counter=-1"
            );
            $person->execute([$sealed,$counter,$identityId]);
            if ($person->rowCount() !== 1) {
                throw new RuntimeException('Enrollment identity promotion failed.');
            }
            $membership = $this->db->prepare(
                "UPDATE public.portal_memberships
                 SET status='active', updated_at=clock_timestamp()
                 WHERE id=CAST(? AS uuid) AND status='invited' AND role='owner'"
            );
            $membership->execute([$row['membership_id']]);
            if ($membership->rowCount() !== 1) {
                throw new RuntimeException('Enrollment membership promotion failed.');
            }
            return true;
        });
    }

    /** @return array<string,mixed>|null */
    private function identity(string $id): ?array
    {
        $q = $this->db->prepare(
            "SELECT id,email_normalized,status,email_verified_at,password_hash
             FROM public.portal_identities WHERE id=CAST(? AS uuid) FOR UPDATE"
        );
        $q->execute([$id]);
        $row = $q->fetch(PDO::FETCH_ASSOC);
        return is_array($row) ? $row : null;
    }

    private function requireLab(): void
    {
        try {
            if (getenv('APP_ENV') !== 'testing'
                || getenv('PORTAL_BROWSER_PG_LAB') !== 'DISPOSABLE_PG18'
                || getenv('PGHOST') !== '127.0.0.1'
                || getenv('PGPORT') !== '5432'
                || getenv('PGDATABASE') !== 'portal_browser_lab'
                || $this->db->getAttribute(PDO::ATTR_DRIVER_NAME) !== 'pgsql'
                || $this->db->inTransaction()) {
                throw new RuntimeException('Lab gate refused.');
            }
            $context = $this->db->query(
                'SELECT current_database() AS db,current_user AS actor,session_user AS login'
            )->fetch(PDO::FETCH_ASSOC);
            if (!is_array($context) || $context['db'] !== 'portal_browser_lab'
                || $context['actor'] !== 'postgres' || $context['login'] !== 'postgres') {
                throw new RuntimeException('Lab actor refused.');
            }
        } catch (Throwable) {
            throw new RuntimeException('Disposable enrollment unavailable.');
        }
    }

    private function transaction(callable $fn): mixed
    {
        try {
            $this->db->beginTransaction();
            try {
                $result = $fn();
                $this->db->commit();
                return $result;
            } catch (Throwable) {
                if ($this->db->inTransaction()) {
                    $this->db->rollBack();
                }
                throw new RuntimeException('Disposable enrollment transaction refused.');
            }
        } catch (Throwable) {
            throw new RuntimeException('Disposable enrollment unavailable.');
        }
    }
}
