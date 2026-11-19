<?php
declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Lab-only audit invariant: a direct INSERT cannot pre-seed an event with a
 * future revision and make the automatic event fail later. Existing history
 * and RLS are preserved; this grants no role access to candidate mandates.
 */
return new class extends Migration {
    public function up(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            throw new RuntimeException('PostgreSQL required for mandate audit revision guard');
        }
        DB::unprepared(<<<'SQL'
CREATE FUNCTION portal_performance_account_event_current_revision() RETURNS trigger
LANGUAGE plpgsql SECURITY INVOKER AS $function$
DECLARE
    current_revision bigint;
BEGIN
    SELECT m.revision INTO current_revision
      FROM portal_performance_account_mandates AS m
     WHERE m.id = NEW.mandate_id
       AND m.company_id = NEW.company_id
       AND m.service_principal = NEW.service_principal
     FOR KEY SHARE;

    IF current_revision IS NULL OR current_revision <> NEW.revision THEN
        RAISE EXCEPTION 'Account mandate event revision does not match current mandate'
            USING ERRCODE = '23514';
    END IF;
    RETURN NEW;
END;
$function$;

CREATE TRIGGER portal_performance_account_event_current_revision_guard
 BEFORE INSERT ON portal_performance_account_mandate_events
 FOR EACH ROW EXECUTE FUNCTION portal_performance_account_event_current_revision();
SQL);
    }

    public function down(): void
    {
        throw new RuntimeException('Candidate mandate audit revision guard cannot be silently removed');
    }
};
