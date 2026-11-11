<?php

declare(strict_types=1);

namespace Imagine\Portal\Labs\CommercialIntakeF119;

use InvalidArgumentException;
use PDO;
use Throwable;
use Imagine\Portal\Labs\CommercialIntakeF117\PortalCommercialMetadataInspector;

require_once __DIR__ . '/../commercial-intake-f117/PortalCommercialMetadataInspector.php';

/**
 * Synthetic SERVICE-authenticated metadata observation, NOT CRM delivery.
 * Only executable in a fresh, ephemeral GitHub-hosted PostgreSQL 18 CI job.
 *
 * No web entrypoint, customer data, shared logins, real service identity,
 * durable CRM receipt, network callback, live route, or migrations.
 */
final class PortalF119SyntheticServiceReservation
{
    public const SCHEMA = 'imagine.portal.synthetic-service-intake.v1';
    public const DOMAIN = 'imagine.portal.synthetic-service-request.v1';

    private const REFERENCES = [
        'organization_reference', 'binding_reference', 'submission_reference',
        'site_reference', 'form_reference', 'destination_reference',
    ];

    public function __construct(private readonly string $testKey)
    {
        if (strlen($testKey) < 32) {
            throw new InvalidArgumentException('F119: fixture key needs 32 bytes');
        }
    }

    /** @param array<string,mixed> $envelope @param array<string,mixed> $binding
     *  @param array<string,mixed> $authentication
     *  @return array<string,mixed>
     */
    public function observe(
        PDO $pdo, array $envelope, array $binding, array $authentication
    ): array {
        if (PortalCommercialMetadataInspector::inspect($envelope, $binding)['reasonCode']
            !== 'STRUCTURE_MATCH_UNTRUSTED') {
            return self::result('PINNED_METADATA_REJECTED');
        }

        if (count($authentication) !== 4
            || !array_key_exists('issued_at', $authentication)
            || !array_key_exists('expires_at', $authentication)
            || !array_key_exists('nonce', $authentication)
            || !array_key_exists('mac', $authentication)
            || !is_int($authentication['issued_at'])
            || !is_int($authentication['expires_at'])
            || !is_string($authentication['nonce'])
            || !is_string($authentication['mac'])
            || preg_match('/\A[0-9a-f]{8}(?:-[0-9a-f]{4}){3}-[0-9a-f]{12}\z/D', $authentication['nonce']) !== 1
            || $authentication['nonce'] === '00000000-0000-0000-0000-000000000000'
            || preg_match('/\A[0-9a-f]{64}\z/D', $authentication['mac']) !== 1) {
            return self::result('SYNTHETIC_AUTH_FORMAT_INVALID');
        }
        $now = time();
        $issued = $authentication['issued_at'];
        $expires = $authentication['expires_at'];
        if ($issued <= 0 || $expires <= $issued || $expires - $issued > 300
            || $issued > $now + 30 || $expires <= $now) {
            return self::result('SYNTHETIC_AUTH_WINDOW_INVALID');
        }
        $message = self::canonical($envelope, $issued, $expires, $authentication['nonce']);
        if (!hash_equals(hash_hmac('sha256', $message, $this->testKey),
            $authentication['mac'])) {
            return self::result('SYNTHETIC_AUTH_MAC_MISMATCH');
        }
        // Prevent accidental invocation on a persistent database, even if the
        // caller supplies a valid fixture key and syntactically signed input.
        if (getenv('GITHUB_ACTIONS') !== 'true'
            || getenv('RUNNER_ENVIRONMENT') !== 'github-hosted'
            || getenv('PORTAL_F119_PG_LAB') !== '1'
            || getenv('DB_HOST') !== '127.0.0.1'
            || getenv('DB_DATABASE') !== 'portal_commercial_lab') {
            return self::result('EPHEMERAL_CI_REQUIRED');
        }

        try {
            if ($pdo->inTransaction()
                || $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) !== 'pgsql'
                || $pdo->query('SELECT current_database()')->fetchColumn() !== 'portal_commercial_lab') {
                return self::result('EPHEMERAL_DATABASE_REQUIRED');
            }
            $pdo->beginTransaction();
            // These roles are NOLOGIN and fixed to synthetic organization A.
            // Switching from the fixture's superuser is CI-only, never a
            // production authorization method.
            $pdo->exec('SET LOCAL ROLE portal_commercial_f118_a');
            $stmt = $pdo->prepare(<<<'SQL'
WITH nonce_record AS (
 INSERT INTO portal_commercial_ci.service_request_nonce
 (organization_reference,nonce,submission_reference)
 VALUES (CAST(:org AS uuid),CAST(:nonce AS uuid),CAST(:submission AS uuid))
 ON CONFLICT (organization_reference,nonce) DO NOTHING
 RETURNING organization_reference,submission_reference
), created AS (
 INSERT INTO portal_commercial_ci.metadata_ledger
 (organization_reference,submission_reference,binding_reference,
  site_reference,form_reference,destination_reference)
 SELECT organization_reference,submission_reference,
  CAST(:binding AS uuid),CAST(:site AS uuid),
  CAST(:form AS uuid),CAST(:destination AS uuid)
 FROM nonce_record
 ON CONFLICT (organization_reference,submission_reference) DO NOTHING
 RETURNING organization_reference,submission_reference
), queued AS (
 INSERT INTO portal_commercial_ci.pending_outbox
 (organization_reference,submission_reference,event_kind)
 SELECT organization_reference,submission_reference,'pending_review'
 FROM created
 RETURNING submission_reference
)
SELECT
 (SELECT count(*) FROM nonce_record) AS nonce_rows,
 (SELECT count(*) FROM created) AS created_rows,
 (SELECT count(*) FROM queued) AS queued_rows
SQL);
            $stmt->execute([
                'org' => strtolower($envelope['organization_reference']),
                'nonce' => $authentication['nonce'],
                'submission' => strtolower($envelope['submission_reference']),
                'binding' => strtolower($envelope['binding_reference']),
                'site' => strtolower($envelope['site_reference']),
                'form' => strtolower($envelope['form_reference']),
                'destination' => strtolower($envelope['destination_reference']),
            ]);
            $counts = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!is_array($counts)) {
                $pdo->rollBack();
                return self::result('EPHEMERAL_PG_STATE_INVALID');
            }
            $nonceRows = (int)$counts['nonce_rows'];
            $createdRows = (int)$counts['created_rows'];
            $queuedRows = (int)$counts['queued_rows'];

            if ($nonceRows === 0) {
                $pdo->rollBack();
                return self::result('SYNTHETIC_NONCE_REPLAY');
            }
            if ($nonceRows !== 1 || $createdRows > 1 || $queuedRows > 1
                || ($createdRows === 1 && $queuedRows !== 1)) {
                $pdo->rollBack();
                return self::result('EPHEMERAL_PG_STATE_INVALID');
            }
            if ($createdRows === 1) {
                $pdo->commit();
                return self::result('LAB_METADATA_AND_OUTBOX_WRITTEN');
            }

            // Different nonce for existing logical submission is not a new
            // lead. Check that the pinned context + outbox still match.
            $existing = $pdo->prepare(<<<'SQL'
SELECT binding_reference,site_reference,form_reference,destination_reference
FROM portal_commercial_ci.metadata_ledger
WHERE organization_reference=CAST(:org AS uuid)
  AND submission_reference=CAST(:submission AS uuid)
SQL);
            $existing->execute([
                'org' => strtolower($envelope['organization_reference']),
                'submission' => strtolower($envelope['submission_reference']),
            ]);
            $stored = $existing->fetch(PDO::FETCH_ASSOC);
            if (!is_array($stored)) {
                $pdo->rollBack();
                return self::result('LOGICAL_SUBMISSION_UNVERIFIED');
            }
            foreach (['binding_reference','site_reference','form_reference','destination_reference'] as $field) {
                if (strtolower($stored[$field]) !== strtolower($envelope[$field])) {
                    $pdo->rollBack();
                    return self::result('LOGICAL_SUBMISSION_CONTEXT_CONFLICT');
                }
            }
            $outbox = $pdo->prepare(<<<'SQL'
SELECT count(*) FROM portal_commercial_ci.pending_outbox
WHERE organization_reference=CAST(:org AS uuid)
  AND submission_reference=CAST(:submission AS uuid)
  AND event_kind='pending_review'
SQL);
            $outbox->execute([
                'org' => strtolower($envelope['organization_reference']),
                'submission' => strtolower($envelope['submission_reference']),
            ]);
            if ((int)$outbox->fetchColumn() !== 1) {
                $pdo->rollBack();
                return self::result('LOGICAL_SUBMISSION_OUTBOX_MISSING');
            }
            $pdo->commit();
            return self::result('LAB_EXISTING_SUBMISSION_NOT_DELIVERED');
        } catch (Throwable) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            return self::result('EPHEMERAL_PG_OPERATION_REJECTED');
        }
    }

    /**
     * Mirror the CRM's six-UUID stable ordering, with a different domain
     * separator so this request signature CANNOT be misread as a CRM receipt.
     *
     * @param array<string,mixed> $envelope
     */
    private static function canonical(array $envelope, int $issued, int $expires, string $nonce): string
    {
        $fields = [self::DOMAIN];
        foreach (self::REFERENCES as $key) {
            $fields[] = strtolower($envelope[$key]);
        }
        $fields[] = (string)$issued;
        $fields[] = (string)$expires;
        $fields[] = $nonce;
        return implode("\n", $fields);
    }

    /** @return array<string,mixed> */
    private static function result(string $reasonCode): array
    {
        return [
            'schema' => self::SCHEMA,
            'reasonCode' => $reasonCode,
            'evidenceClass' => 'SYNTHETIC_SERVICE_MAC_AND_DISPOSABLE_PG_ONLY',
            'canAcceptVisitorData' => false,
            'tenantAuthenticatedForProduction' => false,
            'crmPersisted' => false,
            'canAcknowledgeVisitor' => false,
            'canDeliverToCrm' => false,
            'canAutoRetryUnknownOutcome' => false,
            'productionAuthorized' => false,
        ];
    }
}
