<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Additive upgrade for existing Portal schemas; never enables authentication.
 * Already rounded historical values cannot have their microseconds recovered.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            throw new RuntimeException('Portal timestamp precision requires PostgreSQL.');
        }

        DB::transaction(function (): void {
            DB::statement("SET LOCAL lock_timeout = '5s'");
            DB::statement("SET LOCAL statement_timeout = '30s'");
            foreach ([
                'portal_identities' => ['email_verified_at', 'mfa_confirmed_at'],
                'portal_auth_sessions' => ['mfa_verified_at', 'issued_at', 'expires_at', 'revoked_at'],
                'portal_email_verification_challenges' => ['issued_at', 'expires_at', 'consumed_at', 'revoked_at'],
            ] as $table => $columns) {
                foreach ($columns as $column) {
                    DB::statement(
                        'ALTER TABLE public."' . $table . '" ALTER COLUMN "' . $column
                        . '" TYPE timestamp(6) with time zone'
                    );
                }
            }
        });
    }

    public function down(): void
    {
        throw new RuntimeException(
            'Lossy timestamp precision rollback refused; retain microseconds and use a reviewed forward repair.'
        );
    }
};
