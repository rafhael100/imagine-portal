<?php
declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Schema-only candidate. No operational principal receives SQL grants.
 * Never promotes a synthetic consent row into authority to use Ads APIs.
 */
return new class extends Migration {
    public function up(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            throw new RuntimeException('PostgreSQL required for Performance account mandates');
        }
        DB::unprepared(<<<'SQL'
CREATE TABLE portal_performance_account_mandates (
    id uuid PRIMARY KEY,
    company_id uuid NOT NULL REFERENCES portal_performance_services(company_id) ON DELETE RESTRICT,
    provider text NOT NULL CHECK (provider IN ('google_ads','meta_ads')),
    account_id text NOT NULL CHECK (
        (provider='google_ads' AND account_id ~ '^[0-9]{10}$')
        OR (provider='meta_ads' AND account_id ~ '^[0-9]{5,32}$')
    ),
    service_principal text NOT NULL CHECK (service_principal ~ '^[A-Za-z0-9_-]{8,96}$'),
    operation text NOT NULL DEFAULT 'reports.read' CHECK (operation='reports.read'),
    purpose text NOT NULL DEFAULT 'ads_reporting_read' CHECK (purpose='ads_reporting_read'),
    consent_reference text NOT NULL CHECK (consent_reference ~ '^[A-Za-z0-9][A-Za-z0-9_.:-]{7,127}$'),
    credential_ref text NOT NULL CHECK (credential_ref ~ '^[A-Za-z0-9][A-Za-z0-9_.:-]{7,127}$'),
    status text NOT NULL CHECK (status IN ('candidate','suspended','revoked')),
    source_kind text NOT NULL DEFAULT 'synthetic_candidate' CHECK (source_kind='synthetic_candidate'),
    operational_enabled boolean NOT NULL DEFAULT false CHECK (operational_enabled=false),
    revision bigint NOT NULL CHECK (revision>0),
    expires_at bigint NOT NULL CHECK (expires_at>0),
    revoked_at bigint,
    created_at timestamptz NOT NULL DEFAULT now(),
    updated_at timestamptz NOT NULL DEFAULT now(),
    CONSTRAINT account_mandate_revocation_consistency CHECK (
      (status='revoked' AND revoked_at IS NOT NULL)
      OR (status<>'revoked' AND revoked_at IS NULL)
    ),
    CONSTRAINT account_mandate_unique_scope UNIQUE(company_id,provider,account_id,service_principal,operation),
    CONSTRAINT account_mandate_event_identity UNIQUE(id,company_id,service_principal)
);
ALTER TABLE portal_performance_account_mandates ENABLE ROW LEVEL SECURITY;
ALTER TABLE portal_performance_account_mandates FORCE ROW LEVEL SECURITY;
CREATE POLICY portal_performance_account_scope ON portal_performance_account_mandates
 USING (
    company_id::text = current_setting('app.performance_company',true)
    AND service_principal = current_setting('app.performance_principal',true)
 ) WITH CHECK (
    company_id::text = current_setting('app.performance_company',true)
    AND service_principal = current_setting('app.performance_principal',true)
 );
CREATE TABLE portal_performance_account_mandate_events (
    id uuid PRIMARY KEY,
    mandate_id uuid NOT NULL,
    company_id uuid NOT NULL,
    service_principal text NOT NULL,
    revision bigint NOT NULL CHECK (revision>0),
    event_kind text NOT NULL CHECK (event_kind IN ('created','revised','suspended','revoked')),
    correlation_id uuid NOT NULL,
    occurred_at timestamptz NOT NULL DEFAULT now(),
    CONSTRAINT account_mandate_event_parent FOREIGN KEY(mandate_id,company_id,service_principal)
      REFERENCES portal_performance_account_mandates(id,company_id,service_principal) ON DELETE RESTRICT
);
ALTER TABLE portal_performance_account_mandate_events ENABLE ROW LEVEL SECURITY;
ALTER TABLE portal_performance_account_mandate_events FORCE ROW LEVEL SECURITY;
CREATE POLICY portal_performance_account_event_scope ON portal_performance_account_mandate_events
 USING (
    company_id::text = current_setting('app.performance_company',true)
    AND service_principal = current_setting('app.performance_principal',true)
 ) WITH CHECK (
    company_id::text = current_setting('app.performance_company',true)
    AND service_principal = current_setting('app.performance_principal',true)
 );
CREATE FUNCTION prevent_portal_performance_account_event_mutation() RETURNS trigger
LANGUAGE plpgsql AS $function$
BEGIN
    RAISE EXCEPTION 'Account mandate events are append-only';
END;
$function$;
CREATE TRIGGER prevent_portal_performance_account_event_updates
 BEFORE UPDATE OR DELETE ON portal_performance_account_mandate_events
 FOR EACH ROW EXECUTE FUNCTION prevent_portal_performance_account_event_mutation();
SQL);
    }

    public function down(): void
    {
        throw new RuntimeException('Preserve mandate and audit history; disable candidate instead of rollback');
    }
};
