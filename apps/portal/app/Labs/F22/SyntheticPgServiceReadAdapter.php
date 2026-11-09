<?php

declare(strict_types=1);

namespace App\Labs\F22;

use App\Domain\ClientOverview\DemoServiceRelationPage;
use App\Domain\Organizations\OrganizationId;
use App\Labs\F24\F24LabSessionProof;
use App\Labs\F28\F28RevocationFenceInspector;
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
        ?F24LabSessionProof $labSessionProof = null,
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

        // F25: acquire tenant's shared SESSION advisory lock BEFORE the
        // first REPEATABLE READ statement establishes the DB snapshot.
        // The F25 disposable triggers acquire the matching exclusive XACT
        // lock on a session/member write. A privileged writer can bypass
        // triggers; this coordination is NEVER a production authorization.
        $fenceKey = $labSessionProof?->syntheticFenceKey();
        $fenceHeld = false;
        try {
            if ($fenceKey !== null) {
                $this->pdo->exec("SET lock_timeout = '2000ms'");
                $statement = $this->pdo->query(
                    'SELECT pg_catalog.pg_advisory_lock_shared('.$fenceKey.')'
                );
                $statement->closeCursor();
                $fenceHeld = true;
            }
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

            // F23 NEGATIVE SECURITY PROBE. Before reading, check the
            // database-side guarantees assumed by this exact F21 fixture.
            // A name match alone cannot prove that RLS remains enabled.
            // This is NOT a signed schema attestation or production IAM.
            $assurance = $this->pdo->query(
                "SELECT (
                    SELECT count(*)::int
                      FROM pg_catalog.pg_class c
                      JOIN pg_catalog.pg_namespace n ON n.oid = c.relnamespace
                     WHERE n.nspname = 'f21'
                       AND c.relname IN ('service', 'request', 'contract')
                       AND c.relkind = 'r'
                       AND c.relrowsecurity AND c.relforcerowsecurity
                ) AS protected_tables,
                (
                    SELECT ('security_invoker=true' = ANY(c.reloptions))
                      FROM pg_catalog.pg_class c
                      JOIN pg_catalog.pg_namespace n ON n.oid = c.relnamespace
                     WHERE n.nspname = 'f21'
                       AND c.relname = 'service_summary'
                       AND c.relkind = 'v'
                ) AS invoker_view,
                (
                    SELECT (NOT r.rolsuper AND NOT r.rolbypassrls AND r.rolcanlogin)
                      FROM pg_catalog.pg_roles r WHERE r.rolname = current_user
                ) AS restricted_role",
            )->fetch(PDO::FETCH_ASSOC);
            if (!is_array($assurance)
                || !in_array($assurance['protected_tables'] ?? null, [3, '3'], true)
                || !$this->pgTrue($assurance['invoker_view'] ?? null)
                || !$this->pgTrue($assurance['restricted_role'] ?? null)) {
                $this->pdo->rollBack();
                return DemoServiceRelationPage::blocked('unsafe_db_schema');
            }

            // F28: The F25 lock is meaningful only while every F24
            // session/membership writer trigger is still in place. Check
            // metadata and the canonical fixture trigger body BEFORE
            // consulting session state or the service view. This is only
            // negative drift detection within the disposable database.
            if ($labSessionProof !== null
                && !(new F28RevocationFenceInspector())->intact($this->pdo)) {
                $this->pdo->rollBack();
                return DemoServiceRelationPage::blocked('unsafe_revocation_fence');
            }

            // F24 synthetic proof is checked INSIDE the same read snapshot.
            // The F22 unguarded entrypoint remains only for older lab tests;
            // F24's facade NEVER invokes it without a verified proof.
            if ($labSessionProof !== null
                && !$labSessionProof->matchesCurrentSyntheticDatabase($this->pdo, $organization)) {
                $this->pdo->rollBack();
                return DemoServiceRelationPage::blocked('revoked_lab_identity');
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
        } finally {
            if ($fenceHeld) {
                try {
                    // Releases after COMMIT/ROLLBACK, never before SELECT.
                    // If the socket dies, PostgreSQL frees session locks
                    // when the connection closes (not a formal SLA).
                    $unlock = $this->pdo->query(
                        'SELECT pg_catalog.pg_advisory_unlock_shared('.$fenceKey.')'
                    );
                    $unlock->closeCursor();
                } catch (Throwable) {
                    // The user-facing result remains deny/preview only;
                    // never elevate or retry with an admin connection.
                }
            }
            if ($fenceKey !== null) {
                try {
                    $this->pdo->exec('RESET lock_timeout');
                } catch (Throwable) {
                    // Broken connection is handled by the caller's lab.
                }
            }
        }
    }

    private function pgTrue(mixed $value): bool
    {
        // PDO_pgsql may fetch PostgreSQL bool as bool, 't' or '1'.
        return in_array($value, [true, 't', 'true', 1, '1'], true);
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
