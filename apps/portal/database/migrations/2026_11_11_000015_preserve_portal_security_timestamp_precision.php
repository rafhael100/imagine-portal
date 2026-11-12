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
            DB::statement('LOCK TABLE public.portal_identities, public.portal_auth_sessions, public.portal_email_verification_challenges IN ACCESS EXCLUSIVE MODE');
            $policies = [];
            foreach ([
                'portal_identities' => 'portal_f115_identity_update',
                'portal_email_verification_challenges' => 'portal_f115_challenge_update',
            ] as $table => $name) {
                $policy = DB::selectOne(
                    "SELECT permissive,roles::text,cmd,qual,with_check FROM pg_policies
                     WHERE schemaname='public' AND tablename=? AND policyname=?",
                    [$table, $name]
                );
                if ($policy === null) {
                    continue;
                }
                if ($policy->permissive !== 'PERMISSIVE' || $policy->roles !== '{portal_f115_email_definer}'
                    || $policy->cmd !== 'UPDATE' || $policy->qual !== 'true'
                    || !is_string($policy->with_check) || $policy->with_check === '') {
                    throw new RuntimeException('Unexpected F115 policy shape; reviewed migration required.');
                }
                $policies[] = [$table, $name, $policy->with_check];
                DB::statement('DROP POLICY "' . $name . '" ON public."' . $table . '"');
            }
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
            foreach ($policies as [$table, $name, $check]) {
                DB::statement(
                    'CREATE POLICY "' . $name . '" ON public."' . $table
                    . '" AS PERMISSIVE FOR UPDATE TO portal_f115_email_definer USING (true) WITH CHECK ('
                    . $check . ')'
                );
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
