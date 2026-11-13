<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Domain\Organizations\OrganizationId;
use App\Domain\ServiceRequests\RequestInboxPreview;
use App\Domain\ServiceRequests\RequestInboxPreviewResult;
use App\Domain\ServiceRequests\ServiceRequestSnapshot;
use App\Domain\ServiceRequests\ServiceRequestStatus;
use PDO;
use RuntimeException;
use Throwable;

/**
 * F14 — a genuine disposable PG18 -> typed domain -> UI projection.
 *
 * Deliberately inside tests/Support, never app/. The connection is an
 * administrator of a disposable CI database, NOT a production reader.
 * A syntactically valid tenant input is NOT proof of authentication.
 * No grants, policy, route, controller, or customer response are created.
 */
final readonly class DisposablePg18ProjectInboxReader
{
    public function __construct(private PDO $database)
    {
        if (getenv('APP_ENV') !== 'testing'
            || getenv('PORTAL_BROWSER_PG_LAB') !== 'DISPOSABLE_PG18'
            || getenv('PGHOST') !== '127.0.0.1'
            || getenv('PGPORT') !== '5432'
            || getenv('PGDATABASE') !== 'portal_browser_lab'
            || $database->getAttribute(PDO::ATTR_DRIVER_NAME) !== 'pgsql'
            || $database->inTransaction()
            || $database->query('SELECT current_database()')->fetchColumn() !== 'portal_browser_lab'
            || (int) $database->query(
                "SELECT current_setting('server_version_num')::integer/10000"
            )->fetchColumn() !== 18) {
            throw new RuntimeException('PG18-to-UI projection is available only in disposable CI.');
        }
    }

    public function project(
        OrganizationId $organization,
        string $projectId,
    ): RequestInboxPreviewResult {
        $uuid = '/\A[a-f0-9]{8}-[a-f0-9]{4}-4[a-f0-9]{3}-[89ab][a-f0-9]{3}-[a-f0-9]{12}\z/D';
        if (preg_match($uuid, $organization->value) !== 1
            || preg_match($uuid, $projectId) !== 1
            || $this->database->inTransaction()) {
            return RequestInboxPreviewResult::rejected('invalid_preview_input');
        }

        try {
            // The fixture reader MUST see a single consistent source snapshot:
            // an organization/project must not be retired mid-projection.
            $this->database->beginTransaction();
            $this->database->exec('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ, READ ONLY');

            $project = $this->database->prepare(
                'SELECT p.project_ref,p.state,o.status AS organization_state,
                        p.source_claim_id,c.state AS claim_state
                   FROM public.portal_service_projects p
                   JOIN public.portal_organizations o ON o.id=p.organization_id
                   LEFT JOIN public.portal_service_resource_claims c
                     ON c.id=p.source_claim_id
                    AND c.organization_id=p.organization_id
                    AND c.product_code=p.product_code
                  WHERE p.id=CAST(? AS uuid)
                    AND p.organization_id=CAST(? AS uuid)'
            );
            $project->execute([$projectId, $organization->value]);
            $row = $project->fetch(PDO::FETCH_ASSOC);
            if (!is_array($row)
                || !in_array($row['state'], ['draft', 'review'], true)
                || $row['organization_state'] !== 'active'
                || ($row['source_claim_id'] !== null && $row['claim_state'] !== 'candidate')) {
                $this->database->rollBack();
                return RequestInboxPreviewResult::rejected('invalid_preview_input');
            }
            $serviceRef = $row['project_ref'];
            $source = $this->database->prepare(
                'SELECT request_ref,status,request_revision
                   FROM public.portal_service_requests
                  WHERE organization_id=CAST(? AS uuid)
                    AND project_id=CAST(? AS uuid)
                  ORDER BY request_ref ASC
                  LIMIT 51'
            );
            $source->execute([$organization->value, $projectId]);
            $records = $source->fetchAll(PDO::FETCH_ASSOC);
            if (!is_array($records) || count($records) > 50) {
                $this->database->rollBack();
                return RequestInboxPreviewResult::rejected('invalid_preview_input');
            }

            $snapshots = [];
            foreach ($records as $record) {
                $status = ServiceRequestStatus::tryFrom((string) $record['status']);
                $revision = filter_var(
                    $record['request_revision'],
                    FILTER_VALIDATE_INT,
                    ['options' => ['min_range' => 1, 'max_range' => 1000000000]],
                );
                if ($status === null || $revision === false) {
                    $this->database->rollBack();
                    return RequestInboxPreviewResult::rejected('invalid_preview_input');
                }
                $snapshots[] = new ServiceRequestSnapshot(
                    $record['request_ref'], $organization, $serviceRef,
                    $status, $revision,
                );
            }
            $result = (new RequestInboxPreview())->project(
                $snapshots, $organization, limit: 50,
            );
            $this->database->commit();
            return $result;
        } catch (Throwable) {
            if ($this->database->inTransaction()) {
                $this->database->rollBack();
            }
            return RequestInboxPreviewResult::rejected('invalid_preview_input');
        }
    }

    public static function authorizesCustomerRead(): bool
    {
        return false;
    }

    public static function enablesRoute(): bool
    {
        return false;
    }
}
