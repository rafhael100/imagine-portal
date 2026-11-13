<?php

declare(strict_types=1);

namespace Tests\Support;

use PDO;
use RuntimeException;
use Throwable;

/**
 * Offline, laboratory-only first-invitation transaction. This test helper
 * NEVER establishes login, verifies email, enrolls MFA or grants permissions.
 * It is not in the application namespace, console commands or HTTP routing.
 */
final readonly class DisposableFirstInvitationBootstrap
{
    public function __construct(private PDO $db)
    {
    }

    /**
     * @return array{identity:string,organization:string,membership:string}
     */
    public function reserve(string $email, string $reference, string $displayName): array
    {
        // This rehearsal cannot be called against a real customer/production
        // database or with a real operator email.
        if (getenv('APP_ENV') !== 'testing'
            || getenv('PORTAL_BROWSER_PG_LAB') !== 'DISPOSABLE_PG18'
            || getenv('PGHOST') !== '127.0.0.1'
            || getenv('PGPORT') !== '5432'
            || getenv('PGDATABASE') !== 'portal_browser_lab'
            || preg_match('/\A[a-z0-9._%+-]+@example\.invalid\z/D', $email) !== 1
            || strlen($email) > 254
            || preg_match('/\A[a-z0-9][a-z0-9-]{2,60}\z/D', $reference) !== 1
            || $displayName === '' || trim($displayName) !== $displayName
            || strlen($displayName) > 160
            || preg_match('/[\x00-\x1f\x7f]/', $displayName) === 1) {
            throw new RuntimeException('Disposable invitation preflight refused.');
        }

        try {
            if ($this->db->getAttribute(PDO::ATTR_DRIVER_NAME) !== 'pgsql'
                || $this->db->inTransaction()) {
                throw new RuntimeException('Disposable invitation connection refused.');
            }

            $identity = $this->db->query(
                "SELECT current_database() AS db, current_user AS actor,
                        session_user AS login, pg_is_in_recovery() AS standby"
            )->fetch(PDO::FETCH_ASSOC);
            if (!is_array($identity) || $identity['db'] !== 'portal_browser_lab'
                || $identity['actor'] !== 'postgres' || $identity['login'] !== 'postgres'
                || in_array($identity['standby'], [true, 1, '1', 't'], true)) {
                throw new RuntimeException('Disposable invitation authority refused.');
            }

            $this->db->beginTransaction();
            try {
                // Serialize both the empty-database check and the whole
                // invitation reservation. No competing first account may pass.
                $this->db->exec('LOCK TABLE
                    public.portal_identities, public.portal_organizations,
                    public.portal_memberships, public.portal_membership_grants,
                    public.portal_auth_sessions, public.portal_auth_attempt_buckets
                    IN ACCESS EXCLUSIVE MODE');
                foreach ([
                    'portal_identities', 'portal_organizations', 'portal_memberships',
                    'portal_membership_grants', 'portal_auth_sessions',
                    'portal_auth_attempt_buckets',
                ] as $table) {
                    if ((int) $this->db->query(
                        'SELECT count(*) FROM public.' . $table
                    )->fetchColumn() !== 0) {
                        throw new RuntimeException('Disposable identity database is not empty.');
                    }
                }

                $org = $this->db->prepare(
                    "INSERT INTO public.portal_organizations
                       (id, reference, display_name, status)
                     VALUES(gen_random_uuid(), ?, ?, 'active') RETURNING id"
                );
                $org->execute([$reference, $displayName]);
                $orgId = $org->fetchColumn();

                // Invited and unverified: no password, TOTP, enrollment token,
                // bootstrap bearer, e-mail verification, or login session.
                $person = $this->db->prepare(
                    "INSERT INTO public.portal_identities
                       (id, email_normalized, status)
                     VALUES(gen_random_uuid(), ?, 'invited') RETURNING id"
                );
                $person->execute([$email]);
                $personId = $person->fetchColumn();

                $membership = $this->db->prepare(
                    "INSERT INTO public.portal_memberships
                       (id, identity_id, organization_id, role, status)
                     VALUES(gen_random_uuid(), ?, ?, 'owner', 'invited') RETURNING id"
                );
                $membership->execute([$personId, $orgId]);
                $memberId = $membership->fetchColumn();
                if (!is_string($personId) || !is_string($orgId)
                    || !is_string($memberId)) {
                    throw new RuntimeException('Disposable invitation not persisted.');
                }

                $this->db->commit();
                return ['identity' => $personId, 'organization' => $orgId,
                    'membership' => $memberId];
            } catch (Throwable) {
                if ($this->db->inTransaction()) {
                    $this->db->rollBack();
                }
                throw new RuntimeException('Disposable invitation transaction refused.');
            }
        } catch (Throwable) {
            // No raw SQL, email, credential or tenant values in diagnostics.
            throw new RuntimeException('Disposable invitation unavailable.');
        }
    }
}
