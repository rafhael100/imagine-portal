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
            $t->unsignedInteger('revision')->default(1);
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
            $t->string('idempotency_digest', 64)->nullable();
            $t->string('payload_digest', 64)->nullable();
            $t->string('status', 16)->default('received');
            $t->primary(['org_id', 'id']);
            $t->unique(['org_id', 'idempotency_digest']);
            $t->foreign(['org_id', 'service_id'])
                ->references(['org_id', 'id'])->on('lab_portal_services');
        });

        // F7 — DB-local outbox and fake delivery receipts, not external effects.
        // Membership revision is captured at creation to prevent replay after
        // revoke+reactivate. A full backup rollback can still roll it back.
        Schema::connection('pgsql')->create('lab_portal_outbox', static function (Blueprint $t): void {
            $t->string('org_id', 64);
            $t->string('id', 64);
            $t->string('actor_id', 64);
            $t->string('request_id', 64);
            $t->string('event_type', 50);
            $t->unsignedInteger('membership_revision');
            $t->unsignedInteger('account_revision');
            $t->string('status', 16)->default('pending');
            $t->unsignedInteger('attempts')->default(0);
            $t->primary(['org_id', 'id']);
            $t->unique(['org_id', 'request_id', 'event_type']);
            $t->foreign(['org_id', 'request_id'])->references(['org_id', 'id'])
                ->on('lab_portal_requests');
            $t->foreign(['org_id', 'actor_id'])->references(['org_id', 'user_id'])
                ->on('lab_portal_memberships');
        });

        Schema::connection('pgsql')->create('lab_portal_delivery_receipts', static function (Blueprint $t): void {
            $t->string('org_id', 64);
            $t->string('event_id', 64);
            $t->string('effect_type', 40);
            $t->primary(['org_id', 'event_id']);
            $t->foreign(['org_id', 'event_id'])->references(['org_id', 'id'])
                ->on('lab_portal_outbox');
        });

        // Synthetic MFA and server-side token records only. Secrets below
        // are artificial test fixtures, NEVER an operational secret store.
        Schema::connection('pgsql')->create('lab_portal_auth_factors', static function (Blueprint $t): void {
            $t->string('user_id', 64)->primary();
            $t->string('secret_hex', 40);
            $t->bigInteger('last_step')->default(-1);
            $t->foreign('user_id')->references('id')->on('lab_portal_users');
        });

        Schema::connection('pgsql')->create('lab_portal_auth_sessions', static function (Blueprint $t): void {
            $t->string('token_digest', 64)->primary();
            $t->string('user_id', 64);
            $t->unsignedInteger('account_revision');
            $t->bigInteger('expires_at');
            $t->boolean('mfa_verified')->default(false);
            $t->boolean('revoked')->default(false);
            $t->foreign('user_id')->references('id')->on('lab_portal_users');
        });

        Schema::connection('pgsql')->create('lab_portal_login_limits', static function (Blueprint $t): void {
            $t->string('principal_digest', 64)->primary();
            $t->unsignedInteger('failures')->default(0);
            $t->bigInteger('locked_until')->default(0);
        });

        // The runtime role can read but cannot grant rights, update users,
        // create accounts, modify memberships or write service records.
        foreach (['lab_portal_users', 'lab_portal_organizations', 'lab_portal_memberships', 'lab_portal_services'] as $table) {
            DB::connection('pgsql')->statement('REVOKE ALL ON TABLE '.$table.' FROM PUBLIC');
            DB::connection('pgsql')->statement('GRANT SELECT ON TABLE '.$table.' TO portal_lab_runtime');
        }


        // These privileges remain in the disposable test role. In production
        // they would require further separation, encryption and auditing.
        foreach ([
            'lab_portal_auth_factors' => 'SELECT, UPDATE',
            'lab_portal_auth_sessions' => 'SELECT, INSERT, UPDATE',
            'lab_portal_login_limits' => 'SELECT, INSERT, UPDATE',
        ] as $table => $verbs) {
            DB::connection('pgsql')->statement('REVOKE ALL ON TABLE '.$table.' FROM PUBLIC');
            DB::connection('pgsql')->statement('GRANT '.$verbs.' ON TABLE '.$table.' TO portal_lab_runtime');
        }

        // Publisher role is constrained by RLS and cannot consume/update.
        DB::connection('pgsql')->statement('REVOKE ALL ON TABLE lab_portal_outbox FROM PUBLIC');
        DB::connection('pgsql')->statement('GRANT SELECT, INSERT ON TABLE lab_portal_outbox TO portal_lab_runtime');
        // A DIFFERENT synthetic worker role can consume but cannot create
        // memberships, users, requests, or use the HTTP runtime's DB login.
        foreach ([
            'lab_portal_outbox' => 'SELECT, UPDATE',
            'lab_portal_delivery_receipts' => 'SELECT, INSERT',
            'lab_portal_memberships' => 'SELECT',
            'lab_portal_users' => 'SELECT',
            'lab_portal_organizations' => 'SELECT',
        ] as $table => $verbs) {
            DB::connection('pgsql')->statement('GRANT '.$verbs.' ON TABLE '.$table.' TO portal_lab_worker');
        }
        DB::connection('pgsql')->statement('REVOKE ALL ON TABLE lab_portal_delivery_receipts FROM PUBLIC');

        DB::connection('pgsql')->statement('ALTER TABLE lab_portal_outbox ENABLE ROW LEVEL SECURITY');
        DB::connection('pgsql')->statement('ALTER TABLE lab_portal_outbox FORCE ROW LEVEL SECURITY');
        DB::connection('pgsql')->statement(<<<'SQL'
CREATE POLICY portal_lab_outbox_pub_read ON lab_portal_outbox
FOR SELECT TO portal_lab_runtime USING (
    org_id = NULLIF(current_setting('portal.lab_tenant', true), '')
    AND actor_id = NULLIF(current_setting('portal.lab_actor', true), '')
    AND status = 'pending'
    AND EXISTS (
        SELECT 1 FROM lab_portal_memberships m
        JOIN lab_portal_organizations o ON o.id = m.org_id
        JOIN lab_portal_users u ON u.id = m.user_id
        WHERE m.org_id = lab_portal_outbox.org_id
          AND m.user_id = lab_portal_outbox.actor_id
          AND m.status = 'active'
          AND o.status = 'active'
          AND u.status = 'active'
          AND m.revision = lab_portal_outbox.membership_revision
          AND u.session_revision = lab_portal_outbox.account_revision
    )
)
SQL);
        DB::connection('pgsql')->statement(<<<'SQL'
CREATE POLICY portal_lab_outbox_pub_insert ON lab_portal_outbox
FOR INSERT TO portal_lab_runtime WITH CHECK (
    org_id = NULLIF(current_setting('portal.lab_tenant', true), '')
    AND actor_id = NULLIF(current_setting('portal.lab_actor', true), '')
    AND event_type = 'request.received.synthetic'
    AND status = 'pending'
    AND EXISTS (
        SELECT 1 FROM lab_portal_memberships m
        JOIN lab_portal_organizations o ON o.id = m.org_id
        JOIN lab_portal_users u ON u.id = m.user_id
        WHERE m.org_id = lab_portal_outbox.org_id
          AND m.user_id = lab_portal_outbox.actor_id
          AND m.status = 'active'
          AND o.status = 'active'
          AND u.status = 'active'
          AND m.revision = lab_portal_outbox.membership_revision
          AND u.session_revision = lab_portal_outbox.account_revision
    )
)
SQL);
        DB::connection('pgsql')->statement(
            "CREATE POLICY portal_lab_outbox_worker_all ON lab_portal_outbox FOR ALL TO portal_lab_worker USING (true) WITH CHECK (true)"
        );

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
        foreach (['lab_portal_delivery_receipts', 'lab_portal_outbox', 'lab_portal_login_limits', 'lab_portal_auth_sessions', 'lab_portal_auth_factors', 'lab_portal_requests', 'lab_portal_services', 'lab_portal_memberships', 'lab_portal_organizations', 'lab_portal_users'] as $table) {
            Schema::connection('pgsql')->dropIfExists($table);
        }
    }
};
