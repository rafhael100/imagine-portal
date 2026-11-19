<?php
declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Extend the candidate-only schema. Mandate transitions and their events
 * must commit together, even when the mutation is performed directly via SQL.
 */
return new class extends Migration {
    public function up(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            throw new RuntimeException('PostgreSQL required for account mandate audit');
        }
        DB::unprepared(<<<'SQL'
CREATE FUNCTION portal_performance_account_mandate_append_event() RETURNS trigger
LANGUAGE plpgsql SECURITY INVOKER AS $function$
DECLARE
    kind text;
BEGIN
    IF TG_OP = 'INSERT' THEN
        kind := 'created';
    ELSIF NEW.status = 'revoked' THEN
        kind := 'revoked';
    ELSIF NEW.status = 'suspended' THEN
        kind := 'suspended';
    ELSE
        kind := 'revised';
    END IF;
    INSERT INTO portal_performance_account_mandate_events
        (id,mandate_id,company_id,service_principal,revision,event_kind,correlation_id)
    VALUES
        (gen_random_uuid(),NEW.id,NEW.company_id,NEW.service_principal,
         NEW.revision,kind,gen_random_uuid());
    RETURN NEW;
END;
$function$;

CREATE TRIGGER portal_performance_account_mandate_auto_audit
 AFTER INSERT OR UPDATE ON portal_performance_account_mandates
 FOR EACH ROW EXECUTE FUNCTION portal_performance_account_mandate_append_event();

CREATE FUNCTION portal_performance_account_mandate_prevent_delete() RETURNS trigger
LANGUAGE plpgsql SECURITY INVOKER AS $function$
BEGIN
    RAISE EXCEPTION 'Account mandate history cannot be deleted or truncated';
END;
$function$;

CREATE TRIGGER portal_performance_account_mandate_no_delete
 BEFORE DELETE ON portal_performance_account_mandates
 FOR EACH ROW EXECUTE FUNCTION portal_performance_account_mandate_prevent_delete();
CREATE TRIGGER portal_performance_account_mandate_no_truncate
 BEFORE TRUNCATE ON portal_performance_account_mandates
 FOR EACH STATEMENT EXECUTE FUNCTION portal_performance_account_mandate_prevent_delete();
CREATE TRIGGER portal_performance_account_event_no_truncate
 BEFORE TRUNCATE ON portal_performance_account_mandate_events
 FOR EACH STATEMENT EXECUTE FUNCTION portal_performance_account_mandate_prevent_delete();
SQL);
    }

    public function down(): void
    {
        throw new RuntimeException('Mandate history is append-only; no destructive rollback');
    }
};
