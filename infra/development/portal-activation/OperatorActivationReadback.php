<?php
declare(strict_types=1);

namespace Imagine\Development;

use PDO;
use Throwable;
use SensitiveParameter;

/**
 * Read-only post-activation inspection for ONE private operator.
 * A status observation never authenticates a session, permits business use,
 * validates backup safety, or authorizes any public serving.
 */
final class OperatorActivationReadback
{
    public function __construct(
        private PDO $db,
        #[SensitiveParameter] private string $proof
    ) {}

    public function inspect(string $email):array
    {
        $blocked=self::result('NO_GO');
        if($email!=='rafhael@imaginecomunicacao.com.br'
            ||preg_match('/\A[a-f0-9]{64}\z/D',$this->proof)!==1
            ||$this->db->inTransaction()) {
            return $blocked;
        }

        try {
            // Consistent snapshot; never a write. No exception diagnostics leave Core.
            $this->db->exec('BEGIN TRANSACTION ISOLATION LEVEL REPEATABLE READ READ ONLY');
            $this->db->exec("SET LOCAL lock_timeout='2s'");
            $this->db->exec("SET LOCAL statement_timeout='5s'");
            $identity=$this->db->query(
                "SELECT current_database() AS db, current_user AS actor,
                        session_user AS login,
                        current_setting('server_version_num')::int/10000 AS version,
                        host(inet_client_addr()) AS address"
            )->fetch(PDO::FETCH_ASSOC);
            if(!is_array($identity)
                ||$identity['db']!=='portal_identity_dev'
                ||$identity['actor']!=='postgres'
                ||$identity['login']!=='postgres'
                ||(int)$identity['version']!==18
                ||$identity['address']!=='127.0.0.1') {
                return $blocked;
            }
            $proof=$this->db->prepare('SELECT secret=? FROM portal_browser_private.proof_key');
            $proof->execute([$this->proof]);
            if(!self::yes($proof->fetchColumn()))return $blocked;
            $marker=$this->db->query(
                'SELECT marker FROM public.portal_development_installation WHERE singleton AND version=1'
            )->fetchColumn();
            if(!is_string($marker)
                ||preg_match('/\A[a-f0-9]{64}\z/D',$marker)!==1) {
                return $blocked;
            }

            foreach(['portal_identities','portal_organizations','portal_memberships'] as $table){
                // Fixed table-name allowlist in source; never interpolate user input.
                if((int)$this->db->query('SELECT count(*) FROM public.'.$table)->fetchColumn()!==1)
                    return $blocked;
            }
            foreach(['portal_membership_grants','portal_service_resource_claims'] as $table){
                if((int)$this->db->query('SELECT count(*) FROM public.'.$table)->fetchColumn()!==0)
                    return $blocked;
            }
            $unsafeSessions=(int)$this->db->query(
                "SELECT count(*) FROM public.portal_auth_sessions WHERE status IS DISTINCT FROM 'revoked'"
            )->fetchColumn();
            if($unsafeSessions!==0)return $blocked;
            $outstanding=(int)$this->db->query(
                "SELECT count(*) FROM public.portal_development_email_challenges
                  WHERE status IN ('reserved','delivered')"
            )->fetchColumn();
            if($outstanding!==0)return $blocked;
            $rls=(int)$this->db->query(
                "SELECT count(*) FROM pg_class c
                   JOIN pg_namespace n ON n.oid=c.relnamespace
                  WHERE n.nspname='public'
                    AND c.relname IN (
                      'portal_identities','portal_memberships','portal_organizations',
                      'portal_auth_sessions','portal_membership_grants',
                      'portal_service_resource_claims','portal_development_email_challenges'
                    )
                    AND c.relrowsecurity AND c.relforcerowsecurity"
            )->fetchColumn();
            if($rls!==7)return $blocked;
            $privileges=(int)$this->db->query(
                "SELECT count(*) FROM
                   unnest(ARRAY['portal_browser_runtime','portal_browser_envelope_runtime']) r(role_name)
                   CROSS JOIN
                   unnest(ARRAY[
                     'public.portal_identities','public.portal_memberships','public.portal_organizations',
                     'public.portal_auth_sessions','public.portal_membership_grants',
                     'public.portal_service_resource_claims','public.portal_development_email_challenges'
                   ]) t(table_name)
                 WHERE has_table_privilege(r.role_name,t.table_name,'SELECT,INSERT,UPDATE,DELETE,TRUNCATE')"
            )->fetchColumn();
            if($privileges!==0)return $blocked;

            $stmt=$this->db->prepare(
                "SELECT i.status AS identity_status,m.status AS membership_status,
                        o.status AS organization_status,m.role,
                        (i.email_verified_at IS NOT NULL
                         AND i.email_verified_at<=clock_timestamp()) AS email_ready,
                        (i.mfa_confirmed_at IS NOT NULL
                         AND i.mfa_confirmed_at<=clock_timestamp()) AS mfa_enrolled,
                        (i.password_hash LIKE '$argon2id$%') AS password_enrolled
                   FROM public.portal_identities i
                   JOIN public.portal_memberships m ON m.identity_id=i.id
                   JOIN public.portal_organizations o ON o.id=m.organization_id
                  WHERE i.email_normalized=?"
            );
            $stmt->execute([$email]);
            $rows=$stmt->fetchAll(PDO::FETCH_ASSOC);
            if(count($rows)!==1)return $blocked;
            $row=$rows[0];
            if($row['organization_status']!=='active'
                ||$row['role']!=='owner'
                ||!self::yes($row['email_ready'])
                ||!self::yes($row['mfa_enrolled'])
                ||!self::yes($row['password_enrolled'])) {
                return $blocked;
            }
            if($row['identity_status']==='invited'&&$row['membership_status']==='invited') {
                return self::result('INVITED_VERIFIED_PRIVATE_ONLY');
            }
            if($row['identity_status']==='active'&&$row['membership_status']==='active') {
                return self::result('ACTIVE_PRIVATE_STATUS_ONLY');
            }
            return $blocked;
        }catch(Throwable){
            return $blocked;
        }finally{
            if($this->db->inTransaction()){
                try{$this->db->rollBack();}catch(Throwable){}
            }
        }
    }

    private static function yes(mixed $value):bool
    {
        return in_array($value,[true,'t','1',1],true);
    }

    private static function result(string $code):array
    {
        return [
            'schema'=>'imagine.portal.private-activation-readback.v1',
            'status'=>$code,
            'loginPasswordMfaProbeVerified'=>false,
            'businessPermissionGranted'=>false,
            'publicLoginEnabled'=>false,
            'restoreAntiRollbackResolved'=>false,
            'canDeploy'=>false,
        ];
    }
}
