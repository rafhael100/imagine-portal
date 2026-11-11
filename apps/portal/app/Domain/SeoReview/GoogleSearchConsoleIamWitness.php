<?php

declare(strict_types=1);

namespace App\Domain\SeoReview;

use App\Domain\Authorization\Permission;
use PDO;
use Throwable;

/**
 * GSC-F133: transactional PostgreSQL witness for the REAL Portal F66/F76/F113
 * identity and tenant schema and F123 property-candidate schema.
 *
 * This is NOT an operational PortalSessionAuthority implementation, cannot
 * issue credentials, and is intentionally NOT registered in AppServiceProvider.
 * It only verifies that a server-supplied session digest, tenant and candidate
 * currently match the database's explicit IAM records.
 *
 * The caller MUST already own a trusted, first-party session context and
 * approved SQL role/RLS policies. Neither is available in this release.
 * In CI only, the privileged admin tests the predicate, while an ordinary
 * role with no RLS policies remains blocked.
 *
 * FOR SHARE locks remain held by the CALLER's transaction and serialize
 * concurrent UPDATE/DELETE revocations. A successful witness still does not
 * prove Google ownership, provider OAuth or authorization to start HTTP.
 */
final readonly class GoogleSearchConsoleIamWitness
{
    public function __construct(private PDO $database)
    {
    }

    public function inspect(
        #[\SensitiveParameter] ?string $portalSessionSha256,
        ?string $organizationUuid,
        mixed $requestedSiteUrl,
    ): GoogleSearchConsoleIamWitnessReview {
        $propertySha256 = GoogleSearchConsolePropertyIdentifier::fingerprint(
            $requestedSiteUrl,
        );

        if ($portalSessionSha256 === null
            || preg_match('/\A[a-f0-9]{64}\z/D', $portalSessionSha256) !== 1
            || $organizationUuid === null
            || preg_match(
                '/\A[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}\z/D',
                $organizationUuid,
            ) !== 1
            || $propertySha256 === null) {
            return GoogleSearchConsoleIamWitnessReview::blocked();
        }

        try {
            // Never make a read outside a caller-managed transaction: the
            // witness row locks must outlive the query to protect the next
            // audited and separately-approved state issuance operation.
            if (!$this->database->inTransaction()
                || $this->database->getAttribute(PDO::ATTR_DRIVER_NAME) !== 'pgsql'
                || $this->database->getAttribute(PDO::ATTR_EMULATE_PREPARES) !== false) {
                return GoogleSearchConsoleIamWitnessReview::blocked();
            }

            $sql = <<<'SQL'
SELECT 1
  FROM public.portal_auth_sessions AS s
  JOIN public.portal_identities AS i
    ON i.id = s.identity_id
  JOIN public.portal_organizations AS o
    ON o.id = s.organization_id
  JOIN public.portal_memberships AS m
    ON m.identity_id = s.identity_id
   AND m.organization_id = s.organization_id
  JOIN public.portal_membership_grants AS g
    ON g.membership_id = m.id
  JOIN public.portal_gsc_property_candidates AS p
    ON p.organization_id = s.organization_id
 WHERE s.session_sha256 = :session_digest
   AND s.organization_id = CAST(:organization_id AS uuid)
   AND p.site_fingerprint_sha256 = :property_digest
   AND s.status = 'active'
   AND s.revoked_at IS NULL
   AND s.issued_at <= clock_timestamp()
   AND s.expires_at > clock_timestamp()
   AND s.expires_at <= s.issued_at + interval '1 day'
   AND s.mfa_verified_at IS NOT NULL
   AND s.mfa_verified_at >= s.issued_at
   AND s.mfa_verified_at <= clock_timestamp()
   AND i.status = 'active'
   AND i.email_verified_at IS NOT NULL
   AND i.mfa_confirmed_at IS NOT NULL
   AND i.security_revision = s.identity_revision
   AND o.status = 'active'
   AND s.organization_revision IS NOT NULL
   AND s.organization_revision = o.security_revision
   AND m.status = 'active'
   AND m.membership_revision = s.membership_revision
   AND g.permission_code = :permission
   AND p.status = 'candidate'
   AND p.revoked_at IS NULL
 LIMIT 1
 FOR SHARE OF s, i, o, m, g, p
SQL;
            $statement = $this->database->prepare($sql);
            if ($statement === false
                || !$statement->execute([
                    'session_digest' => $portalSessionSha256,
                    'organization_id' => $organizationUuid,
                    'property_digest' => $propertySha256,
                    'permission' => Permission::ConnectGoogleSearchConsole->value,
                ])) {
                return GoogleSearchConsoleIamWitnessReview::blocked();
            }

            return $statement->fetchColumn() === 1
                || $statement->fetchColumn() === '1'
                ? GoogleSearchConsoleIamWitnessReview::syntheticDatabaseWitness()
                : GoogleSearchConsoleIamWitnessReview::blocked();
        } catch (Throwable) {
            // Prevent SQL error messages, actor IDs and tenant data leaking.
            return GoogleSearchConsoleIamWitnessReview::blocked();
        }
    }
}
