<?php

declare(strict_types=1);

namespace App\Labs\F31;

use App\Domain\Organizations\OrganizationId;
use PDO;
use Throwable;

/**
 * Read-only PDO reader for an independently started disposable PostgreSQL
 * witness container. An application-managed second connection is NOT a
 * cryptographic witness or a guarantee of durability outside backups.
 */
final readonly class F31ExternalWitnessReader
{
    public function __construct(private PDO $pdo)
    {
        if (getenv('F31_ENABLE_LAB') !== 'INDEPENDENT_DISPOSABLE_WITNESS'
            || getenv('F22_ENABLE_LAB') !== 'NON_OPERATIONAL_F22'
            || $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) !== 'pgsql') {
            throw new \LogicException('F31 only supports a disposable PG18 witness lab.');
        }
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->setAttribute(PDO::ATTR_EMULATE_PREPARES, false);
    }

    public function observe(OrganizationId $org): ?F31ExternalFloorSnapshot
    {
        if (!in_array($org->value, ['org-a', 'org-b'], true)
            || $this->pdo->inTransaction()) {
            return null;
        }

        try {
            $this->pdo->beginTransaction();
            $this->pdo->exec('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ, READ ONLY');
            $this->pdo->exec("SET LOCAL statement_timeout = '2000ms'");

            $identity = $this->pdo->query(
                "SELECT session_user AS session_identity, current_user AS active_identity,
                        current_setting('server_version_num')::int AS pg_version"
            )->fetch(PDO::FETCH_ASSOC);
            if (!is_array($identity)
                || $identity['session_identity'] !== 'f31_witness_reader'
                || $identity['active_identity'] !== 'f31_witness_reader'
                || (int) ($identity['pg_version'] ?? 0) < 180000) {
                $this->pdo->rollBack();
                return null;
            }

            // Negative drift check, not a full audit of SQL semantics.
            $metadata = $this->pdo->query(
                "SELECT c.relrowsecurity AS rls_on,
                        c.relforcerowsecurity AS rls_forced,
                        (SELECT count(*)::int FROM pg_catalog.pg_trigger t
                         WHERE t.tgrelid=c.oid AND NOT t.tgisinternal
                           AND t.tgname='f31_floor_monotonic'
                           AND t.tgenabled='O' AND t.tgtype=27) AS triggers_on,
                        (SELECT count(*)::int FROM pg_catalog.pg_trigger t
                         WHERE t.tgrelid=c.oid AND NOT t.tgisinternal) AS triggers_total,
                        (SELECT (NOT r.rolsuper AND NOT r.rolbypassrls
                                      AND r.rolcanlogin)
                         FROM pg_catalog.pg_roles r WHERE r.rolname=current_user) AS reader_is_restricted
                   FROM pg_catalog.pg_class c
                   JOIN pg_catalog.pg_namespace n ON n.oid=c.relnamespace
                  WHERE n.nspname='f31'
                    AND c.relname='revocation_floor' AND c.relkind='r'"
            )->fetchAll(PDO::FETCH_ASSOC);
            if (!is_array($metadata) || count($metadata) !== 1
                || !$this->pgTrue($metadata[0]['rls_on'] ?? null)
                || !$this->pgTrue($metadata[0]['rls_forced'] ?? null)
                || !$this->pgTrue($metadata[0]['reader_is_restricted'] ?? null)
                || !in_array($metadata[0]['triggers_on'] ?? null, [1, '1'], true)
                || !in_array($metadata[0]['triggers_total'] ?? null, [1, '1'], true)) {
                $this->pdo->rollBack();
                return null;
            }

            $query = $this->pdo->prepare(
                'SELECT organization_id, session_floor, membership_floor, sequence_number
                   FROM f31.revocation_floor
                  WHERE organization_id=:org
                  LIMIT 2'
            );
            $query->bindValue(':org', $org->value, PDO::PARAM_STR);
            $query->execute();
            $rows = $query->fetchAll(PDO::FETCH_ASSOC);
            if (count($rows) !== 1 || $rows[0]['organization_id'] !== $org->value) {
                $this->pdo->rollBack();
                return null;
            }

            $sessionFloor = $this->intInRange($rows[0]['session_floor'] ?? null, 1000000);
            $membershipFloor = $this->intInRange($rows[0]['membership_floor'] ?? null, 1000000);
            $sequenceNumber = $this->intInRange($rows[0]['sequence_number'] ?? null, 100000000);
            if ($sessionFloor === null || $membershipFloor === null
                || $sequenceNumber === null) {
                $this->pdo->rollBack();
                return null;
            }
            $snapshot = new F31ExternalFloorSnapshot(
                $org->value, $sessionFloor, $membershipFloor, $sequenceNumber,
            );
            $this->pdo->commit();
            return $snapshot;
        } catch (Throwable) {
            try {
                if ($this->pdo->inTransaction()) {
                    $this->pdo->rollBack();
                }
            } catch (Throwable) {
                // Keep the witness unavailable; never fall back to DB owner.
            }
            return null;
        }
    }

    private function intInRange(mixed $raw, int $max): ?int
    {
        if (!is_int($raw)
            && !(is_string($raw) && preg_match('/\A[0-9]{1,9}\z/D', $raw) === 1)) {
            return null;
        }
        $number = filter_var($raw, FILTER_VALIDATE_INT, [
            'options' => ['min_range' => 1, 'max_range' => $max],
        ]);
        return $number === false ? null : (int) $number;
    }

    private function pgTrue(mixed $value): bool
    {
        return in_array($value, [true, 't', 'true', 1, '1'], true);
    }
}
