<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * F77: persisted authentication abuse counter. Not a login route or a policy
 * that makes real authentication safe. Role-bound SQL entrypoint is LAB ONLY.
 * No plaintext IP/email/phone is stored; scope is an external HMAC digest.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            throw new RuntimeException('Portal auth throttling foundation requires PostgreSQL.');
        }

        Schema::create('portal_auth_attempt_buckets', function (Blueprint $table): void {
            $table->char('scope_hash', 64);
            $table->string('action_code', 16);
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->timestampTz('window_started_at');
            $table->timestampTz('last_seen_at');
            $table->primary(['scope_hash', 'action_code'], 'portal_auth_bucket_pk');
        });

        DB::statement(
            "ALTER TABLE portal_auth_attempt_buckets ADD CONSTRAINT portal_auth_bucket_scope_ck "
            . "CHECK (scope_hash ~ '^[a-f0-9]{64}$')"
        );
        DB::statement(
            "ALTER TABLE portal_auth_attempt_buckets ADD CONSTRAINT portal_auth_bucket_action_ck "
            . "CHECK (action_code IN ('login','totp','recovery'))"
        );
        DB::statement(
            'ALTER TABLE portal_auth_attempt_buckets ADD CONSTRAINT portal_auth_bucket_count_ck '
            . 'CHECK (attempts >= 0 AND attempts <= 6)'
        );
        DB::statement(
            'ALTER TABLE portal_auth_attempt_buckets ADD CONSTRAINT portal_auth_bucket_clock_ck '
            . 'CHECK (last_seen_at >= window_started_at)'
        );
        DB::statement('ALTER TABLE portal_auth_attempt_buckets ENABLE ROW LEVEL SECURITY');
        DB::statement('ALTER TABLE portal_auth_attempt_buckets FORCE ROW LEVEL SECURITY');
        DB::statement('REVOKE ALL ON TABLE portal_auth_attempt_buckets FROM PUBLIC');
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            throw new RuntimeException('Portal auth throttle rollback requires PostgreSQL.');
        }
        Schema::dropIfExists('portal_auth_attempt_buckets');
    }
};
