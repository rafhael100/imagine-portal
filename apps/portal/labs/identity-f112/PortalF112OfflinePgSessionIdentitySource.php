<?php

declare(strict_types=1);

require_once dirname(__DIR__, 2).'/vendor/autoload.php';

use App\Domain\Identity\Invitations\PortalInvitationActorEvidence;
use App\Domain\Identity\Invitations\PortalInvitationIdentitySource;
use App\Domain\Identity\PortalUserId;
use App\Domain\Organizations\OrganizationId;

/**
 * F112: CLI-only test implementation of F110's FIRST-PARTY Portal session
 * contract. It reads a disposable PostgreSQL database through an isolated
 * EXECUTE-only role, never caller-supplied identity/MFA/revision metadata.
 *
 * This file intentionally lives in labs/, outside Laravel's app/ autoload,
 * HTTP routes and the F97 staging Docker image. It cannot issue sessions,
 * consume invitations, assert a verified user in production, or grant HTTP.
 */
final readonly class PortalF112OfflinePgSessionIdentitySource implements PortalInvitationIdentitySource
{
    public function __construct(private PDO $reader)
    {
    }

    public function findFor(
        string $opaquePortalSessionReference,
        OrganizationId $selectedOrganization,
    ): ?PortalInvitationActorEvidence {
        if (PHP_SAPI !== 'cli'
            || getenv('APP_ENV') !== 'testing'
            || getenv('PORTAL_F112_PG_LAB') !== '1'
            || getenv('PGHOST') !== '127.0.0.1'
            || getenv('PGPORT') !== '5432'
            || getenv('PGDATABASE') !== 'portal_identity_lab'
            || $this->reader->inTransaction()
            || strlen($opaquePortalSessionReference) !== 43
            || preg_match('/\A[A-Za-z0-9_-]{43}\z/D', $opaquePortalSessionReference) !== 1
            || preg_match(
                '/\A[0-9a-f]{8}-[0-9a-f]{4}-[1-8][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}\z/D',
                $selectedOrganization->value,
            ) !== 1) {
            return null;
        }

        $raw = base64_decode(strtr($opaquePortalSessionReference, '-_', '+/').'=', true);
        if (!is_string($raw)
            || strlen($raw) !== 32
            || rtrim(strtr(base64_encode($raw), '+/', '-_'), '=') !== $opaquePortalSessionReference) {
            return null;
        }

        try {
            $metadata = $this->reader->query(
                'SELECT current_user,current_database()'
            )->fetch(PDO::FETCH_NUM);
            if ($metadata !== ['portal_f112_reader', 'portal_identity_lab']) {
                return null;
            }

            $statement = $this->reader->prepare(
                'SELECT * FROM public.portal_f112_verified_session_evidence('
                .':digest,CAST(:organization AS uuid))'
            );
            $statement->execute([
                'digest' => hash('sha256', $opaquePortalSessionReference),
                'organization' => $selectedOrganization->value,
            ]);
            $row = $statement->fetch(PDO::FETCH_ASSOC);
            if (!is_array($row) || $statement->fetch(PDO::FETCH_ASSOC) !== false) {
                return null;
            }

            // ONLY the database-backed first-party lab evidence supplies
            // actor, organization, factor timestamps and revisions.
            return new PortalInvitationActorEvidence(
                actor: new PortalUserId($row['actor_id']),
                organization: new OrganizationId($row['organization_id']),
                sessionActive: true,
                emailVerified: true,
                mfaVerified: true,
                sessionIssuedAt: (int)$row['session_issued_at'],
                sessionExpiresAt: (int)$row['session_expires_at'],
                emailVerifiedAt: (int)$row['email_verified_at'],
                mfaVerifiedAt: (int)$row['mfa_verified_at'],
                identityRevision: (int)$row['identity_revision'],
                sessionIdentityRevision: (int)$row['session_identity_revision'],
                membershipRevision: (int)$row['membership_revision'],
                sessionMembershipRevision: (int)$row['session_membership_revision'],
                organizationRevision: (int)$row['organization_revision'],
                sessionOrganizationRevision: (int)$row['session_organization_revision'],
            );
        } catch (Throwable) {
            // Never leak SQL, candidate hashes, actor IDs, tenant IDs or
            // whether an otherwise secret first-party session exists.
            return null;
        }
    }

    public static function canAuthenticateCustomers(): bool
    {
        return false;
    }

    public static function canAuthorizeHttp(): bool
    {
        return false;
    }
}
