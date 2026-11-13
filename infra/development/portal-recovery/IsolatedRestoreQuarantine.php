<?php
declare(strict_types=1);

namespace Imagine\Development;

use PDO;
use Throwable;

/**
 * A fail-closed recovery *experiment*. Does not release a restored database.
 *
 * Only GitHub-hosted disposable PG18 target, never origin/Leaseweb/prod.
 * External witnessed sequence and complete restore/DR reconciliation are
 * deliberately unresolved; even success has canServe=false.
 */
final class IsolatedRestoreQuarantine
{
    public function __construct(private PDO $db) {}

    public function apply():array
    {
        $refused=self::result('NO_GO');
        if(getenv('GITHUB_ACTIONS')!=='true'
            ||getenv('RUNNER_ENVIRONMENT')!=='github-hosted'
            ||getenv('PORTAL_RESTORE_QUARANTINE_CI')!=='DISPOSABLE_ONLY'
            ||getenv('APP_ENV')!=='staging'
            ||$this->db->inTransaction()) {
            return $refused;
        }

        try {
            $this->db->beginTransaction();
            $this->db->exec("SET LOCAL lock_timeout='3s'");
            $this->db->exec("SET LOCAL statement_timeout='20s'");
            $meta=$this->db->query(
                "SELECT current_database() AS db,current_user AS actor,
                        session_user AS login,
                        current_setting('server_version_num')::int/10000 AS version,
                        host(inet_client_addr()) AS address"
            )->fetch(PDO::FETCH_ASSOC);
            if(!is_array($meta)
                ||$meta['db']!=='portal_identity_restore_f365_ci'
                ||$meta['actor']!=='postgres'||$meta['login']!=='postgres'
                ||(int)$meta['version']!==18
                ||$meta['address']!=='127.0.0.1'){
                return $refused;
            }
            // Even though app roles have no CONNECT, a second administrator
            // could be inspecting or mutating the isolated database. Refuse.
            // This is a moment-in-time check, NOT a substitute for a true
            // cluster-wide serving fence controlled by a separate authority.
            $otherClients=(int)$this->db->query(
                "SELECT count(*) FROM pg_catalog.pg_stat_activity
                  WHERE datname=current_database()
                    AND backend_type='client backend'
                    AND pid<>pg_backend_pid()"
            )->fetchColumn();
            if($otherClients!==0)return $refused;

            // Protect against concurrent new sessions, grants and identity writes.
            $this->db->exec(
                'LOCK TABLE public.portal_identities,public.portal_memberships,
                  public.portal_organizations,public.portal_auth_sessions,
                  public.portal_browser_envelopes,public.portal_membership_grants,
                  public.portal_service_resource_claims,
                  public.portal_development_email_challenges
                  IN ACCESS EXCLUSIVE MODE'
            );
            $roles=(int)$this->db->query(
                "SELECT count(*) FROM pg_roles
                  WHERE rolname IN ('portal_browser_runtime','portal_browser_envelope_runtime')
                    AND NOT (rolsuper OR rolbypassrls OR rolcreatedb OR rolcreaterole)"
            )->fetchColumn();
            if($roles!==2)return $refused;

            // An isolated target must never have app runtime DB access. Unlike
            // a PostgreSQL role flag, these ACLs apply only to the restored DB.
            foreach(['portal_browser_runtime','portal_browser_envelope_runtime'] as $role){
                $stmt=$this->db->prepare(
                    "SELECT has_database_privilege(?,current_database(),'CONNECT')"
                );
                $stmt->execute([$role]);
                if(self::yes($stmt->fetchColumn()))return $refused;
            }
            $acl=$this->db->query(
                "SELECT count(*) FROM pg_database d,
                        LATERAL aclexplode(
                          coalesce(d.datacl,acldefault('d',d.datdba))
                        ) acl
                  WHERE d.datname=current_database()
                    AND acl.privilege_type='CONNECT'
                    AND acl.grantee<>(SELECT oid FROM pg_roles WHERE rolname='postgres')"
            )->fetchColumn();
            if((int)$acl!==0)return $refused;

            $protected=[
                'portal_identities','portal_memberships','portal_organizations',
                'portal_auth_sessions','portal_browser_envelopes',
                'portal_membership_grants','portal_service_resource_claims',
                'portal_development_email_challenges'
            ];
            $rls=(int)$this->db->query(
                "SELECT count(*) FROM pg_class c
                   JOIN pg_namespace n ON n.oid=c.relnamespace
                  WHERE n.nspname='public'
                    AND c.relname IN (
                      'portal_identities','portal_memberships','portal_organizations',
                      'portal_auth_sessions','portal_browser_envelopes',
                      'portal_membership_grants','portal_service_resource_claims',
                      'portal_development_email_challenges'
                    ) AND c.relrowsecurity AND c.relforcerowsecurity"
            )->fetchColumn();
            if($rls!==count($protected))return $refused;

            // Pilot recovery path has no authority to reconcile business grants,
            // resource claims, queues or external service tokens. Refuse outright.
            foreach(['portal_membership_grants','portal_service_resource_claims'] as $table){
                if((int)$this->db->query('SELECT count(*) FROM public.'.$table)
                    ->fetchColumn()!==0) return $refused;
            }
            $marker=$this->db->query(
                "SELECT marker FROM public.portal_development_installation
                  WHERE singleton AND version=1"
            )->fetchColumn();
            if(!is_string($marker)
                ||preg_match('/\A[a-f0-9]{64}\z/D',$marker)!==1){
                return $refused;
            }
            // Finalize all restored sessions using existing terminal-state
            // triggers. No issuer/reader/role is granted by this operation.
            $this->db->exec(
                "UPDATE public.portal_auth_sessions
                    SET status='revoked',
                        revoked_at=GREATEST(clock_timestamp(),issued_at),
                        updated_at=clock_timestamp()
                  WHERE status IN ('active','pending_mfa')"
            );
            // No previous invitation, TOTP login, email challenge or business
            // permission may be treated as newly authorized after restore.
            $this->db->exec(
                "UPDATE public.portal_development_email_challenges
                    SET status='revoked'
                  WHERE status IN ('reserved','delivered')"
            );
            $this->db->exec('DELETE FROM public.portal_browser_envelopes');
            $this->db->exec(
                "UPDATE public.portal_memberships
                    SET status='suspended',updated_at=clock_timestamp()
                  WHERE status IN ('active','invited')"
            );
            $this->db->exec(
                "UPDATE public.portal_identities
                    SET status='suspended',updated_at=clock_timestamp()
                  WHERE status IN ('active','invited')"
            );
            $this->db->exec(
                "UPDATE public.portal_organizations
                    SET status='suspended',updated_at=clock_timestamp()
                  WHERE status='active'"
            );
            foreach([
                "SELECT count(*) FROM public.portal_auth_sessions WHERE status<>'revoked'",
                "SELECT count(*) FROM public.portal_development_email_challenges WHERE status IN ('reserved','delivered')",
                "SELECT count(*) FROM public.portal_browser_envelopes",
                "SELECT count(*) FROM public.portal_identities WHERE status IN ('active','invited')",
                "SELECT count(*) FROM public.portal_memberships WHERE status IN ('active','invited')",
                "SELECT count(*) FROM public.portal_organizations WHERE status='active'"
            ] as $query){
                if((int)$this->db->query($query)->fetchColumn()!==0)return $refused;
            }

            $this->db->commit();
            return self::result('OFFLINE_QUARANTINED_STILL_NO_SERVING');
        }catch(Throwable){
            return $refused;
        }finally{
            if($this->db->inTransaction()){
                try{$this->db->rollBack();}catch(Throwable){}
            }
        }
    }

    private static function yes(mixed $v):bool
    {
        return in_array($v,[true,'t','1',1],true);
    }

    private static function result(string $status):array
    {
        return [
            'schema'=>'imagine.portal.f365.offline-restore-quarantine.v1',
            'status'=>$status,
            'sessionReplayAuthorized'=>false,
            'businessPermissionsAuthorized'=>false,
            'canConnectApplicationRoles'=>false,
            'restoreWitnessVerified'=>false,
            'canServe'=>false,
            'canDeploy'=>false,
        ];
    }
}
