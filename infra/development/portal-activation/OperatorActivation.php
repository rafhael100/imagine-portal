<?php
declare(strict_types=1);
namespace Imagine\Development;
use App\Domain\Identity\Security\PortalTotpSecretCipher;
use PDO;
use RuntimeException;
use SensitiveParameter;
use Throwable;

/** One-operator private pilot only. No grants, sessions or application wiring. */
final class OperatorActivation
{
    public function __construct(private PDO $db, #[SensitiveParameter] private string $proof,
        #[SensitiveParameter] private string $totpKey){}

    private function scope(string $email):array
    {
        if(strlen($this->totpKey)!==32||preg_match('/\A[a-f0-9]{64}\z/D',$this->proof)!==1
            ||filter_var($email,FILTER_VALIDATE_EMAIL)===false||$email!==strtolower(trim($email)))throw new RuntimeException();
        $a=$this->db->query("SELECT current_database() db,current_user actor,session_user login,current_setting('server_version_num')::int/10000 version,host(inet_client_addr()) address")->fetch(PDO::FETCH_ASSOC);
        if($a['db']!=='portal_identity_dev'||$a['actor']!=='postgres'||$a['login']!=='postgres'||(int)$a['version']!==18||$a['address']!=='127.0.0.1')throw new RuntimeException();
        $q=$this->db->prepare('SELECT secret=? FROM portal_browser_private.proof_key');$q->execute([$this->proof]);
        if(!in_array($q->fetchColumn(),[true,1,'t','1'],true))throw new RuntimeException();
        $marker=$this->db->query('SELECT marker FROM public.portal_development_installation WHERE singleton AND version=1 FOR UPDATE')->fetchColumn();
        if(!is_string($marker)||preg_match('/\A[a-f0-9]{64}\z/D',$marker)!==1)throw new RuntimeException();
        $this->db->exec('LOCK TABLE public.portal_identities,public.portal_memberships,public.portal_organizations,public.portal_auth_sessions,public.portal_membership_grants,public.portal_service_resource_claims,public.portal_development_email_challenges IN SHARE ROW EXCLUSIVE MODE');
        foreach(['portal_identities','portal_memberships','portal_organizations']as$t)
            if((int)$this->db->query('SELECT count(*) FROM public.'.$t)->fetchColumn()!==1)throw new RuntimeException();
        foreach(['portal_auth_sessions','portal_membership_grants','portal_service_resource_claims']as$t)
            if((int)$this->db->query('SELECT count(*) FROM public.'.$t)->fetchColumn()!==0)throw new RuntimeException();
        $rls=(int)$this->db->query("SELECT count(*) FROM pg_class c JOIN pg_namespace n ON n.oid=c.relnamespace WHERE n.nspname='public' AND c.relname IN ('portal_identities','portal_memberships','portal_organizations','portal_auth_sessions','portal_membership_grants','portal_service_resource_claims','portal_development_email_challenges') AND c.relrowsecurity AND c.relforcerowsecurity")->fetchColumn();
        if($rls!==7)throw new RuntimeException();
        $roles=(int)$this->db->query("SELECT count(*) FROM pg_roles WHERE rolname IN ('portal_browser_runtime','portal_browser_envelope_runtime') AND rolcanlogin AND NOT(rolsuper OR rolinherit OR rolbypassrls OR rolcreatedb OR rolcreaterole)")->fetchColumn();
        if($roles!==2||(int)$this->db->query("SELECT count(*) FROM pg_auth_members a JOIN pg_roles r ON r.oid=a.member WHERE r.rolname IN ('portal_browser_runtime','portal_browser_envelope_runtime')")->fetchColumn()!==0)throw new RuntimeException();
        $grants=(int)$this->db->query("SELECT count(*) FROM unnest(ARRAY['portal_browser_runtime','portal_browser_envelope_runtime']) r(role_name) CROSS JOIN unnest(ARRAY['public.portal_identities','public.portal_memberships','public.portal_organizations','public.portal_auth_sessions','public.portal_membership_grants','public.portal_service_resource_claims','public.portal_development_email_challenges']) t(table_name) WHERE has_table_privilege(r.role_name,t.table_name,'SELECT,INSERT,UPDATE,DELETE,TRUNCATE')")->fetchColumn();
        if($grants!==0)throw new RuntimeException();
        $q=$this->db->prepare("SELECT i.id identity_id,m.id membership_id,m.organization_id,i.password_hash,i.mfa_secret_ciphertext,i.mfa_last_used_counter,i.security_revision identity_revision,m.membership_revision,o.security_revision organization_revision
            FROM public.portal_identities i JOIN public.portal_memberships m ON m.identity_id=i.id JOIN public.portal_organizations o ON o.id=m.organization_id
            WHERE i.email_normalized=? AND i.status='invited' AND m.status='invited' AND m.role='owner' AND o.status='active'
              AND i.email_verified_at<=clock_timestamp() AND i.mfa_confirmed_at<=clock_timestamp()
            FOR UPDATE OF i,m,o");$q->execute([$email]);$rows=$q->fetchAll(PDO::FETCH_ASSOC);if(count($rows)!==1)throw new RuntimeException();$s=$rows[0];
        $info=password_get_info($s['password_hash']??'');$options=$info['options']??[];
        if(($info['algoName']??'')!=='argon2id'||($options['memory_cost']??0)<19456||($options['memory_cost']??0)>262144||($options['time_cost']??0)<2||($options['time_cost']??0)>8||($options['threads']??0)<1||($options['threads']??0)>4)throw new RuntimeException();
        $secret=PortalTotpSecretCipher::open($s['mfa_secret_ciphertext']??'',$this->totpKey);
        if($secret===null||$s['mfa_last_used_counter']===null)throw new RuntimeException();
        if(function_exists('sodium_memzero'))sodium_memzero($secret);
        $q=$this->db->prepare("SELECT count(*) FROM public.portal_development_email_challenges c JOIN public.portal_identities i ON i.id=c.identity_id
            WHERE c.identity_id=? AND c.organization_id=? AND c.membership_id=? AND c.email_normalized=?
              AND c.identity_revision=? AND c.membership_revision=? AND c.organization_revision=? AND c.marker=?
              AND c.status='consumed' AND c.consumed_at>=c.issued_at AND c.consumed_at<c.expires_at AND c.attempts<5
              AND c.consumed_at<=i.email_verified_at AND i.email_verified_at<=clock_timestamp()");
        $q->execute([$s['identity_id'],$s['organization_id'],$s['membership_id'],$email,$s['identity_revision'],$s['membership_revision'],$s['organization_revision'],hash('sha256',$marker)]);
        if((int)$q->fetchColumn()!==1||(int)$this->db->query("SELECT count(*) FROM public.portal_development_email_challenges WHERE status IN ('reserved','delivered')")->fetchColumn()!==0)throw new RuntimeException();
        return $s;
    }
    private function run(string $email,bool $activate):array
    {
        if($this->db->inTransaction())throw new RuntimeException('Private activation refused.');
        try{
            $this->db->beginTransaction();$this->db->exec("SET LOCAL lock_timeout='2s'; SET LOCAL statement_timeout='5s'");
            $s=$this->scope($email);
            if($activate){
                $q=$this->db->prepare("UPDATE public.portal_identities SET status='active',updated_at=clock_timestamp() WHERE id=? AND status='invited' AND security_revision=?");$q->execute([$s['identity_id'],$s['identity_revision']]);if($q->rowCount()!==1)throw new RuntimeException();
                $q=$this->db->prepare("UPDATE public.portal_memberships SET status='active',updated_at=clock_timestamp() WHERE id=? AND status='invited' AND membership_revision=?");$q->execute([$s['membership_id'],$s['membership_revision']]);if($q->rowCount()!==1)throw new RuntimeException();
            }
            $this->db->commit();
            return ['organization'=>$s['organization_id'],'activation_applied'=>$activate,'grants_created'=>false,'session_created'=>false];
        }catch(Throwable){if($this->db->inTransaction())$this->db->rollBack();throw new RuntimeException('Private activation refused.');}
    }
    public function inspect(string $email):array{return $this->run($email,false);}
    public function activate(string $email):array{return $this->run($email,true);}
}
