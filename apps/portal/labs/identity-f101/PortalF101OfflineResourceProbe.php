<?php

declare(strict_types=1);

/**
 * PORTAL-F101. Disposable PostgreSQL 18 CLI fixture, not a Portal authority.
 * F76 verifies the first-party session / explicit grant; F101 additionally
 * looks up resource ownership on the server side. Both are laboratory-only.
 * No user, resource or bearer metadata leaves this method.
 */
final readonly class PortalF101OfflineResourceProbe
{
    public function __construct(private PDO $reader)
    {
    }

    public function check(
        string $syntheticBearer,
        string $organizationId,
        string $resourceId,
        string $permission,
    ): bool {
        if (PHP_SAPI !== 'cli'
            || getenv('APP_ENV') !== 'testing'
            || getenv('PORTAL_F101_PG_LAB') !== '1'
            || getenv('PGHOST') !== '127.0.0.1'
            || getenv('PGPORT') !== '5432'
            || getenv('PGDATABASE') !== 'portal_identity_lab'
            || $this->reader->inTransaction()
            || strlen($syntheticBearer) !== 43
            || preg_match('/\A[A-Za-z0-9_-]{43}\z/D', $syntheticBearer) !== 1
            || !self::validUuid($organizationId)
            || !self::validUuid($resourceId)
            || !in_array($permission, ['services.view', 'services.manage'], true)
        ) {
            return false;
        }

        $raw = base64_decode(strtr($syntheticBearer, '-_', '+/').'=', true);
        if (!is_string($raw) || strlen($raw) !== 32
            || rtrim(strtr(base64_encode($raw), '+/', '-_'), '=') !== $syntheticBearer) {
            return false;
        }

        try {
            $identity = $this->reader->query('SELECT current_user, current_database()')
                ->fetch(PDO::FETCH_NUM);
            if ($identity !== ['portal_f101_reader', 'portal_identity_lab']) {
                return false;
            }

            $stmt = $this->reader->prepare(
                'SELECT public.portal_f101_resource_check('
                . ':digest, CAST(:organization AS uuid), CAST(:resource AS uuid), :permission)'
            );
            $stmt->execute([
                'digest' => hash('sha256', $syntheticBearer),
                'organization' => $organizationId,
                'resource' => $resourceId,
                'permission' => $permission,
            ]);

            return in_array($stmt->fetchColumn(), [true, 't', '1', 1], true);
        } catch (Throwable) {
            return false;
        }
    }

    private static function validUuid(string $uuid): bool
    {
        return preg_match(
            '/\A[0-9a-f]{8}-[0-9a-f]{4}-[1-8][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}\z/D',
            $uuid
        ) === 1;
    }

    public static function authorizesOperationalHttp(): bool
    {
        return false;
    }
}
