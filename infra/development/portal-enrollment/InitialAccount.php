<?php
declare(strict_types=1);
namespace Imagine\Development;
use App\Domain\Identity\Security\PortalPasswordCredential;
use App\Domain\Identity\Security\PortalTotpSecretCipher;
use App\Domain\Identity\Security\PortalTotpVerifier;
use PDO;
use RuntimeException;
use SensitiveParameter;
use Throwable;

/** Local first-account preparation only. No verification, login, grants or activation. */
final class InitialAccount
{
    public static function prepare(PDO $db,string $email,string $name,
        #[SensitiveParameter] string $password,#[SensitiveParameter] string $secret,
        #[SensitiveParameter] string $code,#[SensitiveParameter] string $key,
        #[SensitiveParameter] string $proof):array
    {
        $email=strtolower(trim($email));$name=trim($name);
        if(strlen($email)>254||filter_var($email,FILTER_VALIDATE_EMAIL)===false
            ||$name===''||strlen($name)>160||preg_match('//u',$name)!==1
            ||preg_match('/[\x00-\x1f\x7f]/',$name)||strlen($key)!==32
            ||preg_match('/\\A[a-f0-9]{64}\\z/D',$proof)!==1||$db->inTransaction())
            throw new RuntimeException('Initial account preparation refused.');
        $hash=PortalPasswordCredential::makeHash($password);
        $cipher=PortalTotpSecretCipher::seal($secret,$key);
        $counter=PortalTotpVerifier::matchCounter($secret,$code,time());
        if($hash===null||$cipher===null||$counter===null)
            throw new RuntimeException('Initial account preparation refused.');
        $actor=$db->query("SELECT current_database() db,current_user actor,session_user login,
            current_setting('server_version_num')::int/10000 version,inet_client_addr()::text address")->fetch(PDO::FETCH_ASSOC);
        if($actor['db']!=='portal_identity_dev'||$actor['actor']!=='postgres'
            ||$actor['login']!=='postgres'||(int)$actor['version']!==18||$actor['address']!=='127.0.0.1')
            throw new RuntimeException('Private development database required.');
        try{
            $db->beginTransaction();
            $db->exec("SET LOCAL lock_timeout='2s'; SET LOCAL statement_timeout='5s'");
            $marker=$db->query('SELECT marker FROM public.portal_development_installation WHERE singleton AND version=1 FOR UPDATE')->fetchColumn();
            if(!is_string($marker)||preg_match('/\\A[a-f0-9]{64}\\z/D',$marker)!==1)throw new RuntimeException();
            $q=$db->prepare('SELECT secret=? FROM portal_browser_private.proof_key');$q->execute([$proof]);
            if(!in_array($q->fetchColumn(),[true,1,'t','1'],true))throw new RuntimeException();
            $tables=['portal_identities','portal_organizations','portal_memberships','portal_membership_grants','portal_auth_sessions','portal_service_resource_claims'];
            foreach($tables as$table){
                $db->exec('LOCK TABLE public.'.$table.' IN SHARE ROW EXCLUSIVE MODE');
                if((int)$db->query('SELECT count(*) FROM public.'.$table)->fetchColumn()!==0)throw new RuntimeException();
            }
            $identity=self::uuid();$organization=self::uuid();$membership=self::uuid();
            $q=$db->prepare("INSERT INTO public.portal_organizations(id,reference,display_name,status,security_revision)
                VALUES(?,'imagine-development',?,'active',1)");$q->execute([$organization,$name]);
            $q=$db->prepare("INSERT INTO public.portal_identities
                (id,email_normalized,password_hash,status,email_verified_at,mfa_secret_ciphertext,mfa_confirmed_at,mfa_last_used_counter,security_revision)
                VALUES(?,?,?,'invited',NULL,?,clock_timestamp(),?,1)");
            $q->execute([$identity,$email,$hash,$cipher,$counter]);
            $q=$db->prepare("INSERT INTO public.portal_memberships
                (id,identity_id,organization_id,role,status,membership_revision) VALUES(?,?,?,'owner','invited',1)");
            $q->execute([$membership,$identity,$organization]);
            $db->commit();
            return ['identity'=>$identity,'organization'=>$organization,'status'=>'invited','email_verified'=>false,'business_authorized'=>false];
        }catch(Throwable){
            if($db->inTransaction())$db->rollBack();
            throw new RuntimeException('Initial account preparation refused.');
        }
    }
    private static function uuid():string
    {
        $v=random_bytes(16);$v[6]=chr((ord($v[6])&15)|64);$v[8]=chr((ord($v[8])&63)|128);
        $h=bin2hex($v);return substr($h,0,8).'-'.substr($h,8,4).'-'.substr($h,12,4).'-'.substr($h,16,4).'-'.substr($h,20);
    }
}
