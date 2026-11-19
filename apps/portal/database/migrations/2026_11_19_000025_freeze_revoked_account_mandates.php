<?php
declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Lab-only fail-closed invariant. A revoked mandate is a terminal snapshot:
 * even a same-status UPDATE must not rewrite references, expiry or audit
 * chronology after revocation. No new SQL grants or runtime pathways.
 */
return new class extends Migration {
    public function up(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            throw new RuntimeException('PostgreSQL required for revoked mandate guard');
        }
        DB::unprepared(<<<'SQL'
CREATE FUNCTION portal_performance_account_reject_revoked_update() RETURNS trigger
LANGUAGE plpgsql SECURITY INVOKER AS $function$
BEGIN
    IF OLD.status = 'revoked' THEN
        RAISE EXCEPTION 'Revoked account mandate is immutable'
            USING ERRCODE = '23514';
    END IF;
    RETURN NEW;
END;
$function$;

CREATE TRIGGER portal_performance_account_revoked_immutable
 BEFORE UPDATE ON portal_performance_account_mandates
 FOR EACH ROW EXECUTE FUNCTION portal_performance_account_reject_revoked_update();
SQL);
    }

    public function down(): void
    {
        throw new RuntimeException('Revoked mandate history cannot be silently made mutable');
    }
};
