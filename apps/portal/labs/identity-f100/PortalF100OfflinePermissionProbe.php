<?php

declare(strict_types=1);

/**
 * PORTAL-F100: CLI-only bridge between PHP and the existing F76 PostgreSQL
 * EXECUTE-only permission predicate. This class deliberately lives in labs/,
 * outside Composer's app/ autoload and outside the private Docker image.
 *
 * This verifies a SYNTHETIC bearer against a disposable PG18 database.
 * It neither issues nor authenticates a customer session, returns actor info,
 * reads resource rows, creates a trusted Portal authority or approves HTTP.
 */
final readonly class PortalF100OfflinePermissionProbe
{
    public function __construct(private PDO $reader)
    {
    }

    public function check(string $syntheticBearer, string $organizationId, string $permission): bool
    {
        if (PHP_SAPI !== 'cli'
            || getenv('APP_ENV') !== 'testing'
            || getenv('PORTAL_F100_PG_LAB') !== '1'
            || getenv('PGHOST') !== '127.0.0.1'
            || getenv('PGPORT') !== '5432'
            || getenv('PGDATABASE') !== 'portal_identity_lab'
            || $this->reader->inTransaction()
            || strlen($syntheticBearer) !== 43
            || preg_match('/\A[A-Za-z0-9_-]{43}\z/D', $syntheticBearer) !== 1
            || preg_match('/\A[0-9a-f]{8}-[0-9a-f]{4}-[1-8][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}\z/D', $organizationId) !== 1
            || !in_array($permission, [
                'services.view', 'services.manage', 'billing.view', 'members.manage',
            ], true)) {
            return false;
        }

        $decoded = base64_decode(strtr($syntheticBearer, '-_', '+/').'=', true);
        if (!is_string($decoded)
            || strlen($decoded) !== 32
            || rtrim(strtr(base64_encode($decoded), '+/', '-_'), '=') !== $syntheticBearer) {
            return false;
        }

        try {
            // The role is intentionally incapable of SELECT on identity tables.
            // Reject accidental injection of a postgres / privileged PDO handle.
            $metadata = $this->reader->query('SELECT current_user, current_database()')
                ->fetch(PDO::FETCH_NUM);
            if (!is_array($metadata)
                || $metadata !== ['portal_f76_reader', 'portal_identity_lab']) {
                return false;
            }
            $statement = $this->reader->prepare(
                'SELECT public.portal_f76_permission_check(:digest,CAST(:organization AS uuid),:permission)'
            );
            $statement->execute([
                'digest' => hash('sha256', $syntheticBearer),
                'organization' => $organizationId,
                'permission' => $permission,
            ]);

            // Strict PostgreSQL boolean representation; no data or SQL errors
            // leave this laboratory method, not even the caller's bearer hash.
            return in_array($statement->fetchColumn(), [true, 't', '1', 1], true);
        } catch (Throwable) {
            return false;
        }
    }

    public static function canAuthorizeHttp(): bool
    {
        return false;
    }
}
