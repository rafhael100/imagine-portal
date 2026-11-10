<?php

declare(strict_types=1);

namespace Imagine\Portal\Labs\CommercialIntakeF118;

use PDO;
use Throwable;

require_once __DIR__ . '/../commercial-intake-f117/PortalCommercialMetadataInspector.php';

use Imagine\Portal\Labs\CommercialIntakeF117\PortalCommercialMetadataInspector;

/**
 * F118: use real PG18 only in a disposable CI database. Test helper only.
 * NOT a Portal service, authenticated endpoint, migration, or CRM connector.
 */
final class PortalF118SyntheticMetadataReservation
{
    public const SCHEMA = 'imagine.portal.synthetic-pg-reservation.v1';

    /** @param array<string,mixed> $envelope @param array<string,mixed> $binding */
    public static function inspectAndReserve(PDO $pdo, array $envelope, array $binding): array
    {
        $inspection = PortalCommercialMetadataInspector::inspect($envelope, $binding);
        if ($inspection['reasonCode'] !== 'STRUCTURE_MATCH_UNTRUSTED') {
            return self::result('STRUCTURAL_OR_BINDING_REJECTED');
        }

        // No user-supplied DSN or credentials, no dynamic role or schema names.
        // The only valid target is a CI database named for this specific test.
        try {
            if ($pdo->inTransaction() ||
                $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) !== 'pgsql' ||
                $pdo->query('SELECT current_database()')->fetchColumn() !== 'portal_commercial_lab') {
                return self::result('EPHEMERAL_DATABASE_REQUIRED');
            }

            $pdo->beginTransaction();
            // SET LOCAL ROLE enforces RLS and least privilege for each SQL.
            // Superuser used to switch test roles is a CI ONLY convenience.
            $pdo->exec('SET LOCAL ROLE portal_commercial_f118_a');

            $sql = <<<'SQL'
WITH created AS (
  INSERT INTO portal_commercial_ci.metadata_ledger
    (organization_reference,submission_reference,binding_reference,
     site_reference,form_reference,destination_reference)
  VALUES (:org,:submission,:binding,:site,:form,:destination)
  ON CONFLICT (organization_reference,submission_reference) DO NOTHING
  RETURNING organization_reference,submission_reference
), pending AS (
  INSERT INTO portal_commercial_ci.pending_outbox
    (organization_reference,submission_reference,event_kind)
  SELECT organization_reference,submission_reference,'pending_review'
  FROM created
  RETURNING submission_reference
)
SELECT CASE WHEN EXISTS(SELECT 1 FROM pending) THEN 'new' ELSE 'duplicate' END
SQL;
            $stmt = $pdo->prepare($sql);
            $stmt->execute([
                'org' => strtolower($envelope['organization_reference']),
                'submission' => strtolower($envelope['submission_reference']),
                'binding' => strtolower($envelope['binding_reference']),
                'site' => strtolower($envelope['site_reference']),
                'form' => strtolower($envelope['form_reference']),
                'destination' => strtolower($envelope['destination_reference']),
            ]);
            $state = $stmt->fetchColumn();
            if ($state === 'new') {
                $pdo->commit();
                return self::result('LAB_METADATA_AND_OUTBOX_WRITTEN');
            }
            if ($state !== 'duplicate') {
                $pdo->rollBack();
                return self::result('UNEXPECTED_DATABASE_STATE');
            }

            // Primary key prevents duplicate rows, but the original binding
            // MUST remain pinned. Never label a changed destination a retry.
            $check = $pdo->prepare(<<<'SQL'
SELECT binding_reference,site_reference,form_reference,destination_reference
FROM portal_commercial_ci.metadata_ledger
WHERE organization_reference=:org AND submission_reference=:submission
SQL);
            $check->execute([
                'org' => strtolower($envelope['organization_reference']),
                'submission' => strtolower($envelope['submission_reference']),
            ]);
            $original = $check->fetch(PDO::FETCH_ASSOC);
            if (!is_array($original)) {
                $pdo->rollBack();
                return self::result('DUPLICATE_CONTEXT_UNVERIFIED');
            }
            foreach ([
                'binding_reference','site_reference',
                'form_reference','destination_reference',
            ] as $field) {
                if (strtolower($original[$field]) !== strtolower($envelope[$field])) {
                    $pdo->rollBack();
                    return self::result('DUPLICATE_PINNED_CONTEXT_CONFLICT');
                }
            }
            $check = $pdo->prepare(<<<'SQL'
SELECT count(*) FROM portal_commercial_ci.pending_outbox
WHERE organization_reference=:org AND submission_reference=:submission
  AND event_kind='pending_review'
SQL);
            $check->execute([
                'org' => strtolower($envelope['organization_reference']),
                'submission' => strtolower($envelope['submission_reference']),
            ]);
            if ((int)$check->fetchColumn() !== 1) {
                $pdo->rollBack();
                return self::result('DUPLICATE_OUTBOX_MISSING');
            }
            $pdo->commit();
            return self::result('LAB_DUPLICATE_METADATA_NOT_DELIVERED');
        } catch (Throwable) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            // No SQL exception, UUIDs, key, or submitted fields in response.
            return self::result('EPHEMERAL_PG_OPERATION_REJECTED');
        }
    }

    /** @return array<string,mixed> */
    private static function result(string $code): array
    {
        return [
            'schema' => self::SCHEMA,
            'reasonCode' => $code,
            'mode' => 'POSTGRES18_DISPOSABLE_SYNTHETIC_METADATA_ONLY',
            'visitorInputAccepted' => false,
            'tenantAuthenticated' => false,
            'crmPersisted' => false,
            'canAcknowledgeVisitor' => false,
            'canDeliverToCrm' => false,
            'productionAuthorized' => false,
        ];
    }
}
