<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * PORTAL-AUTH-PERF-08: authority-shaped SCHEMA only.
 * No real login/contract/membership data, and no operational HTTP adapter.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (DB::connection()->getDriverName()!=='pgsql') {
            throw new RuntimeException('Portal F08 authority schema requires PostgreSQL.');
        }
        Schema::create('portal_perf_authority_sessions',function(Blueprint $table):void {
            $table->uuid('id')->primary();
            $table->char('session_hash',64)->unique();
            $table->string('session_ref',128)->unique();
            $table->string('actor_ref',128);
            $table->string('organization_ref',128);
            $table->string('status',24);
            $table->timestampTz('mfa_verified_at')->nullable();
            $table->timestampTz('issued_at');
            $table->timestampTz('expires_at');
            $table->unsignedInteger('account_revision');
            $table->unsignedInteger('session_account_revision');
            $table->timestampsTz();
        });
        Schema::create('portal_perf_authority_memberships',function(Blueprint $table):void {
            $table->uuid('id')->primary();
            $table->string('actor_ref',128);
            $table->string('organization_ref',128);
            $table->string('status',24);
            $table->string('permission',48);
            $table->unsignedInteger('membership_revision');
            $table->timestampsTz();
            $table->unique(
                ['actor_ref','organization_ref'],
                'portal_f08_member_actor_organization_uq'
            );
        });
        Schema::create('portal_perf_authority_services',function(Blueprint $table):void {
            $table->uuid('id')->primary();
            $table->string('organization_ref',128);
            $table->string('tenant_ref',128);
            $table->string('service_ref',128);
            $table->string('contract_state',32);
            $table->unsignedInteger('service_revision');
            $table->unsignedInteger('recovery_epoch');
            $table->timestampsTz();
            $table->unique(
                ['organization_ref','service_ref'],
                'portal_f08_service_org_ref_uq'
            );
            $table->unique(
                ['tenant_ref','service_ref'],
                'portal_f08_service_tenant_ref_uq'
            );
        });

        DB::statement(
            "ALTER TABLE portal_perf_authority_sessions ADD CONSTRAINT "
            ."portal_f08_session_status_ck CHECK(status IN ('active','revoked'))"
        );
        DB::statement(
            "ALTER TABLE portal_perf_authority_sessions ADD CONSTRAINT "
            ."portal_f08_session_revision_ck CHECK("
            ."account_revision>0 AND session_account_revision>0 "
            ."AND expires_at>issued_at)"
        );
        DB::statement(
            "ALTER TABLE portal_perf_authority_memberships ADD CONSTRAINT "
            ."portal_f08_membership_status_ck CHECK(status IN ('active','revoked'))"
        );
        DB::statement(
            "ALTER TABLE portal_perf_authority_memberships ADD CONSTRAINT "
            ."portal_f08_membership_permission_ck CHECK("
            ."permission='campaigns:read' AND membership_revision>0)"
        );
        DB::statement(
            "ALTER TABLE portal_perf_authority_services ADD CONSTRAINT "
            ."portal_f08_service_contract_ck CHECK("
            ."contract_state IN ('active_mock','pending_mock','revoked_mock') "
            ."AND service_revision>0 AND recovery_epoch>0)"
        );

        foreach ([
            'portal_perf_authority_sessions',
            'portal_perf_authority_memberships',
            'portal_perf_authority_services',
        ] as $table) {
            DB::statement("ALTER TABLE \"{$table}\" ENABLE ROW LEVEL SECURITY");
            DB::statement("ALTER TABLE \"{$table}\" FORCE ROW LEVEL SECURITY");
        }
        DB::statement(
            "CREATE POLICY portal_f08_session_opaque_hash ON "
            ."portal_perf_authority_sessions FOR SELECT "
            ."USING(session_hash = NULLIF("
            ."current_setting('app.portal_session_hash',true),''))"
        );
        $organizationPredicate="organization_ref=NULLIF("
            ."current_setting('app.portal_organization_ref',true),'')";
        DB::statement(
            "CREATE POLICY portal_f08_membership_org ON "
            ."portal_perf_authority_memberships FOR SELECT "
            ."USING({$organizationPredicate})"
        );
        DB::statement(
            "CREATE POLICY portal_f08_service_org ON "
            ."portal_perf_authority_services FOR SELECT "
            ."USING({$organizationPredicate})"
        );
    }

    public function down(): void
    {
        if (DB::connection()->getDriverName()!=='pgsql') {
            throw new RuntimeException('Portal F08 rollback requires PostgreSQL.');
        }
        Schema::dropIfExists('portal_perf_authority_services');
        Schema::dropIfExists('portal_perf_authority_memberships');
        Schema::dropIfExists('portal_perf_authority_sessions');
    }
};
