<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Domain\Identity\Security\PortalEmailProof;
use PDO;
use RuntimeException;
use SensitiveParameter;
use Throwable;

/**
 * Offline PG18-only rehearsal of an issuer/consumer for an immutable ledger.
 *
 * Deliberately lives under Tests\Support. No HTTP, Artisan command, provider,
 * mail transport, runtime SQL role, customer account or privileges are wired.
 * Returns a synthetic bearer only to the test process.
 */
final readonly class DisposablePersistedEmailProofLab
{
    public function __construct(
        private PDO $db,
        #[SensitiveParameter] private string $serverKey,
    ) {
    }

    public function issue(string $identityId, string $organizationId): string
    {
        $this->requireDisposableAdmin();

        return $this->atomic(function () use ($identityId, $organizationId): string {
            $actor = $this->actor($identityId, $organizationId);
            if (!$this->invited($actor) || !$this->soleMembership($identityId)
                || !$this->emptyAuthorization($identityId, $organizationId)) {
                throw new RuntimeException('Synthetic first proof precondition refused.');
            }

            $pair = PortalEmailProof::issue(
                $identityId, $organizationId, $actor['email_normalized'],
                (int) $actor['security_revision'], $this->serverKey,
            );
            if ($pair === null) {
                throw new RuntimeException('Synthetic first proof scope refused.');
            }

            $insert = $this->db->prepare(
                "INSERT INTO public.portal_email_proof_challenges
                     (identity_id,organization_id,email_normalized,identity_revision,
                      proof_digest,issued_at,expires_at)
                 VALUES(CAST(? AS uuid),CAST(? AS uuid),?,?,?,
                        clock_timestamp(),clock_timestamp()+interval '9 minutes')"
            );
            $insert->execute([
                $identityId, $organizationId, $actor['email_normalized'],
                $actor['security_revision'], $pair['digest'],
            ]);
            if ($insert->rowCount() !== 1) {
                throw new RuntimeException('Synthetic proof insertion refused.');
            }
            return $pair['token'];
        });
    }

    public function confirm(
        string $identityId,
        string $organizationId,
        #[SensitiveParameter] string $token,
    ): bool {
        $this->requireDisposableAdmin();

        return $this->atomic(function () use ($identityId, $organizationId, $token): bool {
            // Lock identity and membership before the proof row. Issuance uses
            // identical ordering, eliminating lost challenge/identity updates.
            $actor = $this->actor($identityId, $organizationId);
            if (!$this->invited($actor) || !$this->soleMembership($identityId)
                || !$this->emptyAuthorization($identityId, $organizationId)) {
                return false;
            }

            $q = $this->db->prepare(
                "SELECT organization_id,email_normalized,identity_revision,
                        proof_digest,status,attempts,consumed_at,
                        expires_at>clock_timestamp() AS fresh
                 FROM public.portal_email_proof_challenges
                 WHERE identity_id=CAST(? AS uuid) FOR UPDATE"
            );
            $q->execute([$identityId]);
            $proof = $q->fetch(PDO::FETCH_ASSOC);
            if (!is_array($proof) || $proof['status'] !== 'pending'
                || $proof['consumed_at'] !== null || (int) $proof['attempts'] >= 5
                || !in_array($proof['fresh'], [true, 't', 1, '1'], true)) {
                return false;
            }

            $matchingScope = $proof['organization_id'] === $organizationId
                && $proof['email_normalized'] === $actor['email_normalized']
                && (int) $proof['identity_revision'] === (int) $actor['security_revision'];
            $valid = $matchingScope && PortalEmailProof::matches(
                $token, trim((string) $proof['proof_digest']),
                $identityId, $organizationId, $actor['email_normalized'],
                (int) $actor['security_revision'], $this->serverKey,
            );

            if (!$valid) {
                // A failed guess is itself committed. The database trigger
                // fences skipped/reversed counters and resurrection at five.
                $update = $this->db->prepare(
                    "UPDATE public.portal_email_proof_challenges
                     SET attempts=attempts+1,
                         status=CASE WHEN attempts=4 THEN 'blocked' ELSE 'pending' END
                     WHERE identity_id=CAST(? AS uuid) AND status='pending'
                       AND attempts<5 AND expires_at>clock_timestamp()"
                );
                $update->execute([$identityId]);
                return false;
            }

            $consume = $this->db->prepare(
                "UPDATE public.portal_email_proof_challenges
                 SET status='consumed',consumed_at=clock_timestamp()
                 WHERE identity_id=CAST(? AS uuid) AND organization_id=CAST(? AS uuid)
                   AND proof_digest=? AND identity_revision=?
                   AND status='pending' AND attempts<5
                   AND expires_at>clock_timestamp()"
            );
            $consume->execute([
                $identityId, $organizationId,
                $proof['proof_digest'], $actor['security_revision'],
            ]);
            if ($consume->rowCount() !== 1) {
                return false;
            }

            // The same transaction advances the synthetic identity proof.
            // No password, MFA, membership/grant or session is issued here.
            $person = $this->db->prepare(
                "UPDATE public.portal_identities
                 SET email_verified_at=clock_timestamp(),updated_at=clock_timestamp()
                 WHERE id=CAST(? AS uuid) AND status='invited'
                   AND email_verified_at IS NULL AND security_revision=?"
            );
            $person->execute([$identityId, $actor['security_revision']]);
            if ($person->rowCount() !== 1) {
                throw new RuntimeException('Synthetic email identity update refused.');
            }
            return true;
        });
    }

    /** @return array<string,mixed>|null */
    private function actor(string $identityId, string $organizationId): ?array
    {
        $q = $this->db->prepare(
            "SELECT i.status AS identity_status,i.email_normalized,
                    i.email_verified_at,i.security_revision,
                    m.id AS membership_id,m.status AS member_status,
                    m.role,o.status AS organization_status
             FROM public.portal_identities i
             JOIN public.portal_memberships m ON m.identity_id=i.id
             JOIN public.portal_organizations o ON o.id=m.organization_id
             WHERE i.id=CAST(? AS uuid) AND o.id=CAST(? AS uuid)
             FOR UPDATE OF i,m,o"
        );
        $q->execute([$identityId, $organizationId]);
        $row = $q->fetch(PDO::FETCH_ASSOC);
        return is_array($row) ? $row : null;
    }

    private function invited(?array $actor): bool
    {
        return is_array($actor)
            && $actor['identity_status'] === 'invited'
            && $actor['member_status'] === 'invited'
            && $actor['role'] === 'owner'
            && $actor['organization_status'] === 'active'
            && $actor['email_verified_at'] === null
            && str_ends_with($actor['email_normalized'], '@example.invalid');
    }

    private function soleMembership(string $identityId): bool
    {
        $q = $this->db->prepare(
            'SELECT count(*) FROM public.portal_memberships WHERE identity_id=CAST(? AS uuid)'
        );
        $q->execute([$identityId]);
        return (int) $q->fetchColumn() === 1;
    }

    private function emptyAuthorization(string $identityId, string $organizationId): bool
    {
        $sessions = $this->db->prepare(
            'SELECT count(*) FROM public.portal_auth_sessions WHERE identity_id=CAST(? AS uuid)'
        );
        $sessions->execute([$identityId]);
        $grants = $this->db->prepare(
            "SELECT count(*) FROM public.portal_membership_grants g
             JOIN public.portal_memberships m ON m.id=g.membership_id
             WHERE m.identity_id=CAST(? AS uuid) AND m.organization_id=CAST(? AS uuid)"
        );
        $grants->execute([$identityId, $organizationId]);
        return (int) $sessions->fetchColumn() === 0
            && (int) $grants->fetchColumn() === 0;
    }

    private function requireDisposableAdmin(): void
    {
        try {
            if (getenv('APP_ENV') !== 'testing'
                || getenv('PORTAL_BROWSER_PG_LAB') !== 'DISPOSABLE_PG18'
                || getenv('PGHOST') !== '127.0.0.1'
                || getenv('PGPORT') !== '5432'
                || getenv('PGDATABASE') !== 'portal_browser_lab'
                || strlen($this->serverKey) !== 32
                || $this->db->getAttribute(PDO::ATTR_DRIVER_NAME) !== 'pgsql'
                || $this->db->inTransaction()) {
                throw new RuntimeException('Disposable proof preflight refused.');
            }
            $row = $this->db->query(
                "SELECT current_database() AS db,current_user AS actor,
                        session_user AS login,pg_is_in_recovery() AS standby"
            )->fetch(PDO::FETCH_ASSOC);
            if (!is_array($row) || $row['db'] !== 'portal_browser_lab'
                || $row['actor'] !== 'postgres' || $row['login'] !== 'postgres'
                || in_array($row['standby'], [true,'t',1,'1'], true)) {
                throw new RuntimeException('Disposable proof authority refused.');
            }
        } catch (Throwable) {
            throw new RuntimeException('Disposable persisted email proof unavailable.');
        }
    }

    private function atomic(callable $callback): mixed
    {
        try {
            $this->db->beginTransaction();
            try {
                $value = $callback();
                $this->db->commit();
                return $value;
            } catch (Throwable) {
                if ($this->db->inTransaction()) {
                    $this->db->rollBack();
                }
                throw new RuntimeException('Disposable persisted email proof transaction refused.');
            }
        } catch (Throwable) {
            // Generic diagnostics avoid exposing bearer, SQL, email or key.
            throw new RuntimeException('Disposable persisted email proof unavailable.');
        }
    }
}
