<?php

declare(strict_types=1);

namespace App\Labs\F22;

use App\Domain\ClientOverview\DemoServiceRelationPage;
use App\Domain\Organizations\OrganizationId;
use PDO;
use Throwable;

/**
 * F22 laboratory-only, opt-in adapter for F21's DISPOSABLE PostgreSQL schema.
 *
 * Not a Laravel production repository, identity authenticator, RLS policy,
 * customer data API or entitlement source. No route or container binding.
 * The PDO connection must be made outside this class as a distinct
 * non-superuser database identity, using disposable credentials.
 */
final readonly class SyntheticPgServiceReadAdapter
{
    /** @var array<string,string> */
    private const LAB_IDENTITIES = [
        'org-a' => 'f21_org_a',
        'org-b' => 'f21_org_b',
    ];

    public function __construct(private PDO $pdo)
    {
        // This opt-in is a NON-SECURITY safety guard against accidental
        // registration, not a trusted identity source or production flag.
        if (getenv('F22_ENABLE_LAB') !== 'NON_OPERATIONAL_F22') {
            throw new \LogicException('F22 adapter is only for an explicitly isolated fixture.');
        }
        // No SQLite fallback, real customer tables, or MySQL integration.
        if ($pdo->getAttribute(PDO::ATTR_DRIVER_NAME) !== 'pgsql') {
            throw new \InvalidArgumentException('F22 requires PostgreSQL lab driver.');
        }
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->setAttribute(PDO::ATTR_EMULATE_PREPARES, false);
    }

    public function read(
        ?OrganizationId $organization,
        int $limit = 10,
        ?string $after = null,
    ): DemoServiceRelationPage {
        if ($organization === null) {
            return DemoServiceRelationPage::blocked('missing_organization');
        }
        if ($limit < 1 || $limit > 20
            || ($after !== null && preg_match('/\ADEMO-SVC-[A-Z0-9-]{1,40}\z/D', $after) !== 1)) {
            return DemoServiceRelationPage::blocked('invalid_pagination');
        }

        // An organization chosen by caller is NOT IAM. For this lab only,
        // demand a corresponding SQL session identity before any SELECT.
        $identity = self::LAB_IDENTITIES[$organization->value] ?? null;
        if ($identity === null) {
            return DemoServiceRelationPage::blocked('untrusted_db_session');
        }

        // Reusing a caller's unknown transaction could defeat isolation or
        // create unexpected DB side effects. This lab owns its transaction.
        if ($this->pdo->inTransaction()) {
            return DemoServiceRelationPage::blocked('source_unavailable');
        }

        try {
            $this->pdo->beginTransaction();
            $this->pdo->exec('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ, READ ONLY');
            $this->pdo->exec("SET LOCAL statement_timeout = '2000ms'");

            $session = $this->pdo->query(
                'SELECT session_user AS session_identity, current_user AS active_identity',
            )->fetch(PDO::FETCH_ASSOC);
            if (!is_array($session)
                || $session['session_identity'] !== $identity
                || $session['active_identity'] !== $identity) {
                $this->pdo->rollBack();
                return DemoServiceRelationPage::blocked('untrusted_db_session');
            }

            // The schema/view are FIXED lab identifiers. No user-supplied SQL.
            // The filter is a parameter in a real server-side prepared query.
            // Always fetch the full bounded fixture collection before slicing.
            $statement = $this->pdo->prepare(
                'SELECT organization_id, service_reference, label, requests_count, contracts_count
                   FROM f21.service_summary
                  WHERE organization_id = :organization_id
                  ORDER BY service_reference ASC
                  LIMIT 51',
            );
            $statement->bindValue(':organization_id', $organization->value, PDO::PARAM_STR);
            $statement->execute();
            $rawRows = $statement->fetchAll(PDO::FETCH_ASSOC);
            if (!is_array($rawRows) || count($rawRows) < 1 || count($rawRows) > 50) {
                $this->pdo->rollBack();
                return DemoServiceRelationPage::blocked('source_unavailable');
            }

            $rows = [];
            $lastRef = null;
            $requests = 0;
            $contracts = 0;
            foreach ($rawRows as $raw) {
                if (!is_array($raw)
                    || $raw['organization_id'] !== $organization->value
                    || !is_string($raw['service_reference'])
                    || preg_match('/\ADEMO-SVC-[A-Z0-9-]{1,40}\z/D', $raw['service_reference']) !== 1
                    || ($lastRef !== null && strcmp($lastRef, $raw['service_reference']) >= 0)
                    || !is_string($raw['label']) || trim($raw['label']) === ''
                    || strlen($raw['label']) > 2000) {
                    $this->pdo->rollBack();
                    return DemoServiceRelationPage::blocked('source_unavailable');
                }
                $r = $this->boundedCount($raw['requests_count'] ?? null);
                $c = $this->boundedCount($raw['contracts_count'] ?? null);
                if ($r === null || $c === null) {
                    $this->pdo->rollBack();
                    return DemoServiceRelationPage::blocked('source_unavailable');
                }
                $lastRef = $raw['service_reference'];
                $rows[] = [
                    'reference' => $lastRef,
                    'service' => $raw['label'],
                    'request_count' => $r,
                    'contract_count' => $c,
                ];
                $requests += $r;
                $contracts += $c;
            }
            if ($requests > 200 || $contracts > 200) {
                $this->pdo->rollBack();
                return DemoServiceRelationPage::blocked('source_unavailable');
            }

            $offset = 0;
            if ($after !== null) {
                $refList = array_column($rows, 'reference');
                $position = array_search($after, $refList, true);
                if ($position === false || $position + 1 >= count($rows)) {
                    $this->pdo->rollBack();
                    return DemoServiceRelationPage::blocked('unknown_cursor');
                }
                $offset = $position + 1;
            }
            $page = array_slice($rows, $offset, $limit);
            $nextCursor = $offset + count($page) < count($rows)
                ? $page[count($page) - 1]['reference']
                : null;

            // Construct/validate output and commit only after all checks.
            $result = DemoServiceRelationPage::prepared(
                $page, count($rows), $requests, $contracts, $nextCursor,
            );
            $this->pdo->commit();
            return $result;
        } catch (Throwable) {
            // A failed SQL connection, missing view, revoked privilege,
            // timeout, or changed schema MUST NOT reveal partial results.
            try {
                if ($this->pdo->inTransaction()) {
                    $this->pdo->rollBack();
                }
            } catch (Throwable) {
                // fail closed even if the connection is already broken
            }
            return DemoServiceRelationPage::blocked('source_unavailable');
        }
    }

    private function boundedCount(mixed $raw): ?int
    {
        if (!is_int($raw) && !(is_string($raw) && preg_match('/\A[0-9]{1,3}\z/D', $raw) === 1)) {
            return null;
        }
        if (filter_var($raw, FILTER_VALIDATE_INT, ['options' => ['min_range' => 0, 'max_range' => 200]]) === false) {
            return null;
        }
        return (int) $raw;
    }
}
