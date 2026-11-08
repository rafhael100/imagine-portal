<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Disposable PostgreSQL schema for the Portal auth/tenant lab only.
 * NOT in database/migrations, never loaded by the production migrator.
 * The runner must pre-create a distinct portal_lab_runtime LOGIN role.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! app()->environment('testing') || getenv('PORTAL_CLIENT_LAB_ENABLED') !== '1') {
            throw new RuntimeException('Lab schema is allowed exclusively in CLI testing.');
        }

        Schema::connection('pgsql')->create('lab_portal_users', static function (Blueprint $t): void {
            $t->string('id', 64)->primary();
            $t->string('email', 190)->unique();
            $t->string('password_hash', 255);
            $t->string('status', 16)->default('active');
            $t->unsignedInteger('session_revision')->default(1);
        });

        Schema::connection('pgsql')->create('lab_portal_organizations', static function (Blueprint $t): void {
            $t->string('id', 64)->primary();
            $t->string('name', 120);
            $t->string('status', 16)->default('active');
        });

        Schema::connection('pgsql')->create('lab_portal_memberships', static function (Blueprint $t): void {
            $t->string('org_id', 64);
            $t->string('user_id', 64);
            $t->string('status', 16)->default('active');
            $t->primary(['org_id', 'user_id']);
            $t->foreign('org_id')->references('id')->on('lab_portal_organizations');
            $t->foreign('user_id')->references('id')->on('lab_portal_users');
        });

        Schema::connection('pgsql')->create('lab_portal_services', static function (Blueprint $t): void {
            $t->string('org_id', 64);
            $t->string('id', 64);
            $t->string('label', 120);
            $t->primary(['org_id', 'id']);
            $t->foreign('org_id')->references('id')->on('lab_portal_organizations');
        });

        Schema::connection('pgsql')->create('lab_portal_requests', static function (Blueprint $t): void {
            $t->string('org_id', 64);
            $t->string('id', 64);
            $t->string('service_id', 64);
            $t->string('title', 120);
            $t->string('status', 16)->default('received');
            $t->primary(['org_id', 'id']);
            $t->foreign(['org_id', 'service_id'])
                ->references(['org_id', 'id'])->on('lab_portal_services');
        });

        // The runtime role can read but cannot grant rights, update users,
        // create accounts, modify memberships or write service records.
        foreach (['lab_portal_users', 'lab_portal_organizations', 'lab_portal_memberships', 'lab_portal_services'] as $table) {
            DB::connection('pgsql')->statement('REVOKE ALL ON TABLE '.$table.' FROM PUBLIC');
            DB::connection('pgsql')->statement('GRANT SELECT ON TABLE '.$table.' TO portal_lab_runtime');
        }

        DB::connection('pgsql')->statement('REVOKE ALL ON TABLE lab_portal_requests FROM PUBLIC');
        DB::connection('pgsql')->statement('GRANT SELECT, INSERT ON TABLE lab_portal_requests TO portal_lab_runtime');
        DB::connection('pgsql')->statement('ALTER TABLE lab_portal_requests ENABLE ROW LEVEL SECURITY');
        DB::connection('pgsql')->statement('ALTER TABLE lab_portal_requests FORCE ROW LEVEL SECURITY');

        DB::connection('pgsql')->statement('ALTER TABLE lab_portal_services ENABLE ROW LEVEL SECURITY');
        DB::connection('pgsql')->statement('ALTER TABLE lab_portal_services FORCE ROW LEVEL SECURITY');
        DB::connection('pgsql')->statement(<<<'SQL'
CREATE POLICY portal_lab_services_select ON lab_portal_services
FOR SELECT TO portal_lab_runtime USING (
    org_id = NULLIF(current_setting('portal.lab_tenant', true), '')
    AND EXISTS (
        SELECT 1 FROM lab_portal_memberships m
        JOIN lab_portal_organizations o ON o.id = m.org_id
        JOIN lab_portal_users u ON u.id = m.user_id
        WHERE m.org_id = lab_portal_services.org_id
          AND m.user_id = NULLIF(current_setting('portal.lab_actor', true), '')
          AND m.status = 'active'
          AND o.status = 'active'
          AND u.status = 'active'
    )
)
SQL);

        DB::connection('pgsql')->statement(<<<'SQL'
CREATE POLICY portal_lab_requests_select ON lab_portal_requests
FOR SELECT TO portal_lab_runtime USING (
    org_id = NULLIF(current_setting('portal.lab_tenant', true), '')
    AND EXISTS (
        SELECT 1 FROM lab_portal_memberships m
        JOIN lab_portal_organizations o ON o.id = m.org_id
        JOIN lab_portal_users u ON u.id = m.user_id
        WHERE m.org_id = lab_portal_requests.org_id
          AND m.user_id = NULLIF(current_setting('portal.lab_actor', true), '')
          AND m.status = 'active'
          AND o.status = 'active'
          AND u.status = 'active'
    )
)
SQL);
        DB::connection('pgsql')->statement(<<<'SQL'
CREATE POLICY portal_lab_requests_insert ON lab_portal_requests
FOR INSERT TO portal_lab_runtime WITH CHECK (
    org_id = NULLIF(current_setting('portal.lab_tenant', true), '')
    AND EXISTS (
        SELECT 1 FROM lab_portal_memberships m
        JOIN lab_portal_organizations o ON o.id = m.org_id
        JOIN lab_portal_users u ON u.id = m.user_id
        WHERE m.org_id = lab_portal_requests.org_id
          AND m.user_id = NULLIF(current_setting('portal.lab_actor', true), '')
          AND m.status = 'active'
          AND o.status = 'active'
          AND u.status = 'active'
    )
)
SQL);
    }

    public function down(): void
    {
        // The lab owns these isolated tables. Never call on live environments.
        if (! app()->environment('testing') || getenv('PORTAL_CLIENT_LAB_ENABLED') !== '1') {
            throw new RuntimeException('Refusing lab cleanup outside testing.');
        }
        foreach (['lab_portal_requests', 'lab_portal_services', 'lab_portal_memberships', 'lab_portal_organizations', 'lab_portal_users'] as $table) {
            Schema::connection('pgsql')->dropIfExists($table);
        }
    }
};
