<?php
declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Candidate-only integrity guard: every mandate starts at revision 1 in
 * candidate state. An INSERT already revoked/suspended or at a later revision
 * would conceal historical transitions from the automatic audit trigger.
 * No operational authorization, SQL grants or source promotion is added.
 */
return new class extends Migration {
    public function up(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            throw new RuntimeException('PostgreSQL required for initial account mandate guard');
        }
        DB::unprepared(<<<'SQL'
CREATE FUNCTION portal_performance_account_check_initial_state() RETURNS trigger
LANGUAGE plpgsql SECURITY INVOKER AS $function$
BEGIN
    IF NEW.status <> 'candidate' OR NEW.revision <> 1 OR NEW.revoked_at IS NOT NULL THEN
        RAISE EXCEPTION 'Account mandate must start as candidate at revision one'
            USING ERRCODE = '23514';
    END IF;
    RETURN NEW;
END;
$function$;

CREATE TRIGGER portal_performance_account_initial_state
 BEFORE INSERT ON portal_performance_account_mandates
 FOR EACH ROW EXECUTE FUNCTION portal_performance_account_check_initial_state();
SQL);
    }

    public function down(): void
    {
        throw new RuntimeException('Initial mandate audit invariants cannot be silently removed');
    }
};
