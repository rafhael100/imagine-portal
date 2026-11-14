<?php
declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * F106: candidate identity-to-GSC binding ledger. No row creation endpoint.
 * Organization/identity UUIDs must be independently obtained from an
 * authenticated Portal principal with fresh membership/org revisions.
 *
 * This schema does not validate an authenticated session or Google access.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            throw new RuntimeException('GSC identity binding requires PostgreSQL');
        }

        Schema::create('portal_gsc_identity_scope_links', function (Blueprint $t):void {
            $t->uuid('portal_organization_id');
            $t->uuid('portal_identity_id');
            $t->string('tenant_ref',64);
            $t->string('actor_ref',64);
            $t->unsignedBigInteger('organization_revision');
            $t->unsignedBigInteger('membership_revision');
            $t->unsignedBigInteger('issued_at');
            $t->unsignedBigInteger('expires_at');
            $t->string('link_status',16);
            $t->unsignedBigInteger('revoked_at')->nullable();
            $t->primary(['tenant_ref','actor_ref'],'portal_gsc_scope_links_pk');
            $t->unique(['portal_organization_id','portal_identity_id'],
                'portal_gsc_scope_links_portal_identity_unique');
        });

        DB::statement("ALTER TABLE portal_gsc_identity_scope_links
            ADD CONSTRAINT portal_gsc_scope_links_refs
            CHECK (tenant_ref ~ '^[a-z0-9][a-z0-9_-]{2,63}$'
                AND actor_ref ~ '^[a-z0-9][a-z0-9_-]{2,63}$')");
        DB::statement("ALTER TABLE portal_gsc_identity_scope_links
            ADD CONSTRAINT portal_gsc_scope_links_revisions
            CHECK (organization_revision > 0 AND membership_revision > 0)");
        DB::statement("ALTER TABLE portal_gsc_identity_scope_links
            ADD CONSTRAINT portal_gsc_scope_links_lifetime
            CHECK (expires_at > issued_at AND expires_at - issued_at <= 86400
                AND (revoked_at IS NULL OR revoked_at >= issued_at))");
        DB::statement("ALTER TABLE portal_gsc_identity_scope_links
            ADD CONSTRAINT portal_gsc_scope_links_status
            CHECK ((link_status='active' AND revoked_at IS NULL)
                OR (link_status='revoked' AND revoked_at IS NOT NULL))");
        DB::statement('ALTER TABLE portal_gsc_identity_scope_links
            ENABLE ROW LEVEL SECURITY');
        DB::statement('ALTER TABLE portal_gsc_identity_scope_links
            FORCE ROW LEVEL SECURITY');
        DB::statement("CREATE POLICY portal_gsc_scope_links_isolated ON
            portal_gsc_identity_scope_links FOR ALL
            USING (tenant_ref = current_setting('app.tenant_ref',true))
            WITH CHECK (tenant_ref = current_setting('app.tenant_ref',true))");
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            throw new RuntimeException('GSC identity binding requires PostgreSQL');
        }
        Schema::dropIfExists('portal_gsc_identity_scope_links');
    }
};
