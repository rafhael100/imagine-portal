<?php
declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\PortalAccess\Security\PortalAdminStagingSecurityPolicy;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Schema;
use Throwable;

/**
 * F137: non-destructive readiness check for the Portal admin STAGING path.
 * It never provisions a user, writes a key, deploys, enables routes or
 * claims that actual HTTPS/TLS/DBA/LGPD review passed.
 */
final class PortalAdminStagingPreflight extends Command
{
    protected $signature='portal-admin:staging-preflight';
    protected $description='Validate strict Portal admin staging configuration and required database schema';

    public function handle():int
    {
        $ci=getenv('CI')==='true' &&
            app()->environment('testing') &&
            getenv('PORTAL_ADMIN_F137_PG_CI')==='1';
        $staging=app()->environment('staging');
        if(!$ci&&!$staging){
            $this->error('Preflight indisponível neste ambiente.');
            return self::FAILURE;
        }

        $issues=PortalAdminStagingSecurityPolicy::issues(
            $ci?'staging':app()->environment()
        );

        try{
            if(!Schema::hasTable('portal_admin_users'))
                $issues[]='ADMIN_USERS_TABLE_REQUIRED';
            if(!Schema::hasTable('portal_admin_security_events'))
                $issues[]='ADMIN_SECURITY_AUDIT_REQUIRED';
            if(!Schema::hasTable('portal_admin_org_grants'))
                $issues[]='ADMIN_ORG_GRANTS_REQUIRED';
            if(!Schema::hasTable('sessions'))
                $issues[]='DATABASE_SESSIONS_TABLE_REQUIRED';
            if(!Schema::hasTable('cache') || !Schema::hasTable('cache_locks'))
                $issues[]='SHARED_DATABASE_RATE_LIMIT_TABLES_REQUIRED';
            if(!Schema::hasColumn('portal_admin_users','session_version'))
                $issues[]='ADMIN_SESSION_VERSION_REQUIRED';
        }catch(Throwable){
            $issues[]='DATABASE_SCHEMA_UNAVAILABLE';
        }

        if($issues!==[]){
            // Safe finite issue codes only. Do not reveal DB credentials,
            // application key, hostnames, tenant names or user identities.
            foreach(array_unique($issues) as $issue)
                $this->error('BLOCKED: '.$issue);
            return self::FAILURE;
        }
        $this->info('PASS: configurações e tabelas exigidas para homologação.');
        $this->warn('PENDENTE: TLS real, sessões entre processos, app server, permissões DBA, MFA admin, backups e revisão AppSec/LGPD.');
        return self::SUCCESS;
    }
}

