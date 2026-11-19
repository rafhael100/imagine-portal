<?php
declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Candidate-only hardening: SQL must not admit an opaque reference that the
 * Performance gate later refuses. No runtime grants or operational promotion.
 */
return new class extends Migration {
    public function up(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            throw new RuntimeException('PostgreSQL required for account mandate reference invariants');
        }
        DB::unprepared(<<<'SQL'
ALTER TABLE portal_performance_account_mandates
 ADD CONSTRAINT account_mandate_consent_reference_shape CHECK (
   octet_length(consent_reference) BETWEEN 8 AND 128
   AND (consent_reference COLLATE "C") ~ '^[A-Za-z0-9]'
   AND (consent_reference COLLATE "C") !~ '[^A-Za-z0-9_.:-]'
 ),
 ADD CONSTRAINT account_mandate_credential_reference_shape CHECK (
   octet_length(credential_ref) BETWEEN 8 AND 128
   AND (credential_ref COLLATE "C") ~ '^[A-Za-z0-9]'
   AND (credential_ref COLLATE "C") !~ '[^A-Za-z0-9_.:-]'
 );

-- One immutable generated audit row per accepted mandate revision.
-- This does not authenticate the actor or turn a synthetic row into consent.
ALTER TABLE portal_performance_account_mandate_events
 ADD CONSTRAINT account_mandate_event_revision_unique UNIQUE (mandate_id, revision);
SQL);
    }

    public function down(): void
    {
        throw new RuntimeException('Candidate mandate reference/audit invariants cannot be silently removed');
    }
};
