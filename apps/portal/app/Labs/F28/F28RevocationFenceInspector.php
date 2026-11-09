<?php

declare(strict_types=1);

namespace App\Labs\F28;

use PDO;
use Throwable;

/**
 * Negative integrity check for F25's *synthetic* trigger protocol.
 *
 * The canonical fixture function body is versioned alongside code to
 * catch ordinary schema drift (including a replaced no-op trigger).
 * It is NOT an independent witness: DB superusers, code deployers or
 * a coordinated restore can alter both metadata and this reference.
 */
final readonly class F28RevocationFenceInspector
{
    private const EXPECTED_FUNCTION_BODY = <<<'PLPGSQL'
DECLARE
    source_org text;
    fence_key bigint;
BEGIN
    source_org := CASE WHEN TG_OP = 'DELETE' THEN OLD.organization_id ELSE NEW.organization_id END;
    fence_key := CASE source_org
        WHEN 'org-a' THEN 9402400001::bigint
        WHEN 'org-b' THEN 9402400002::bigint
        ELSE NULL::bigint
    END;
    IF fence_key IS NULL THEN
        RAISE EXCEPTION 'F25: unknown synthetic organization for revocation gate';
    END IF;

    -- A writer cannot mutate a session/member while a read holding a
    -- SHARED advisory SESSION lock is in progress. Writer lock is xact-scoped.
    -- Privileged administrators CAN disable triggers; this is not an
    -- independent security authority or production revocation protocol.
    PERFORM pg_catalog.pg_advisory_xact_lock(fence_key);
    IF TG_OP = 'DELETE' THEN
        RETURN OLD;
    END IF;
    RETURN NEW;
END
PLPGSQL;

    /**
     * Caller must already own the transaction and hold the F25
     * shared advisory lock; this check does not obtain/release locks.
     */
    public function intact(PDO $pdo): bool
    {
        if ($pdo->getAttribute(PDO::ATTR_DRIVER_NAME) !== 'pgsql'
            || !$pdo->inTransaction()) {
            return false;
        }

        try {
            $rows = $pdo->query(
                "SELECT c.relname AS table_name, t.tgname AS trigger_name,
                        t.tgenabled AS enable_mode, t.tgtype AS event_mask,
                        t.tgqual IS NULL AS has_no_when_clause,
                        fn.nspname AS function_schema, p.proname AS function_name,
                        p.prosecdef AS security_definer, p.prosrc AS function_body
                   FROM pg_catalog.pg_trigger t
                   JOIN pg_catalog.pg_class c ON c.oid=t.tgrelid
                   JOIN pg_catalog.pg_namespace n ON n.oid=c.relnamespace
                   JOIN pg_catalog.pg_proc p ON p.oid=t.tgfoid
                   JOIN pg_catalog.pg_namespace fn ON fn.oid=p.pronamespace
                  WHERE n.nspname='f24'
                    AND c.relname IN ('session_fixture','membership_fixture')
                    AND NOT t.tgisinternal"
            )->fetchAll(PDO::FETCH_ASSOC);
            if (!is_array($rows) || count($rows) !== 2) {
                return false;
            }

            $required = [
                'session_fixture' => 'f25_session_write',
                'membership_fixture' => 'f25_membership_write',
            ];
            foreach ($rows as $row) {
                if (!is_array($row)
                    || !is_string($row['table_name'] ?? null)
                    || !isset($required[$row['table_name']])
                    || $row['trigger_name'] !== $required[$row['table_name']]
                    || $row['enable_mode'] !== 'O'
                    // PG trigger bits: ROW=1 BEFORE=2 INSERT=4 DELETE=8 UPDATE=16.
                    || !in_array($row['event_mask'], [31, '31'], true)
                    || !$this->pgTrue($row['has_no_when_clause'] ?? null)
                    || $row['function_schema'] !== 'f24'
                    || $row['function_name'] !== 'synthetic_revocation_writer_gate'
                    || !$this->pgFalse($row['security_definer'] ?? null)
                    || !is_string($row['function_body'])
                    || trim($row['function_body']) !== self::EXPECTED_FUNCTION_BODY) {
                    return false;
                }
                unset($required[$row['table_name']]);
            }

            return $required === [];
        } catch (Throwable) {
            return false;
        }
    }

    private function pgTrue(mixed $value): bool
    {
        return in_array($value, [true, 1, '1', 't', 'true'], true);
    }

    private function pgFalse(mixed $value): bool
    {
        return in_array($value, [false, 0, '0', 'f', 'false'], true);
    }
}
