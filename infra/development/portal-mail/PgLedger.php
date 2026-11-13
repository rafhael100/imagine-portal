<?php
declare(strict_types=1);
namespace Imagine\Development;
use PDO;
use RuntimeException;
use Throwable;

/** Administrative private development tool. Never registered in the web app. */
final class PgLedger
{
    public function __construct(private PDO $db, private string $key, private string $proof)
    {
        if(strlen($key)!==32||preg_match('/\A[a-f0-9]{64}\z/D',$proof)!==1||$db->inTransaction())throw new RuntimeException();
        $r=$db->query("SELECT current_database() db,current_user actor,session_user login,current_setting('server_version_num')::int/10000 version,host(inet_client_addr()) address")->fetch(PDO::FETCH_ASSOC);
        if($r['db']!=='portal_identity_dev'||$r['actor']!=='postgres'||$r['login']!=='postgres'||(int)$r['version']!==18||$r['address']!=='127.0.0.1')throw new RuntimeException();
        $q=$db->prepare('SELECT secret=? FROM portal_browser_private.proof_key');$q->execute([$proof]);
        if(!in_array($q->fetchColumn(),[true,1,'t','1'],true))throw new RuntimeException();
        $marker=$db->query('SELECT marker FROM public.portal_development_installation WHERE singleton AND version=1')->fetchColumn();
        if(!is_string($marker)||preg_match('/\A[a-f0-9]{64}\z/D',$marker)!==1)throw new RuntimeException();
        $this->marker=hash('sha256',$marker);
    }
    private string $marker;

    public function install():void
    {
        // Separate migration: never rerun the empty-foundation installer.
        $this->tx(function():void{
            $this->db->exec('LOCK TABLE public.portal_development_installation IN EXCLUSIVE MODE');
            if($this->db->query("SELECT to_regclass('public.portal_development_email_challenges')")->fetchColumn()!==null)throw new RuntimeException();
            $this->db->exec(<<<'SQL'
CREATE TABLE public.portal_development_email_challenges (
 reference char(32) PRIMARY KEY CHECK(reference ~ '^[a-f0-9]{32}$'),
 identity_id uuid NOT NULL REFERENCES public.portal_identities(id) ON DELETE RESTRICT,
 organization_id uuid NOT NULL,
 membership_id uuid NOT NULL REFERENCES public.portal_memberships(id) ON DELETE RESTRICT,
 email_normalized varchar(254) NOT NULL,
 identity_revision bigint NOT NULL CHECK(identity_revision>0),
 membership_revision bigint NOT NULL CHECK(membership_revision>0),
 organization_revision bigint NOT NULL CHECK(organization_revision>0),
 marker char(64) NOT NULL CHECK(marker ~ '^[a-f0-9]{64}$'),
 digest char(64) NOT NULL CHECK(digest ~ '^[a-f0-9]{64}$'),
 status varchar(12) NOT NULL CHECK(status IN ('reserved','delivered','consumed','revoked','blocked')),
 attempts smallint NOT NULL DEFAULT 0 CHECK(attempts BETWEEN 0 AND 5),
 issued_at timestamptz NOT NULL DEFAULT clock_timestamp(),
 expires_at timestamptz NOT NULL,
 consumed_at timestamptz,
 FOREIGN KEY(identity_id,organization_id) REFERENCES public.portal_memberships(identity_id,organization_id) ON DELETE RESTRICT,
 CONSTRAINT portal_dev_email_ttl_ck CHECK(expires_at>issued_at AND expires_at<=issued_at+interval '15 minutes'),
 CHECK((status='consumed' AND consumed_at IS NOT NULL AND consumed_at>=issued_at AND consumed_at<expires_at AND attempts<5)
    OR (status<>'consumed' AND consumed_at IS NULL)),
 CHECK((status='blocked' AND attempts=5) OR (status<>'blocked' AND attempts<5))
);
CREATE UNIQUE INDEX portal_dev_email_one_pending ON public.portal_development_email_challenges(identity_id) WHERE status IN ('reserved','delivered');
ALTER TABLE public.portal_development_email_challenges ENABLE ROW LEVEL SECURITY;
ALTER TABLE public.portal_development_email_challenges FORCE ROW LEVEL SECURITY;
REVOKE ALL ON public.portal_development_email_challenges FROM PUBLIC;
CREATE FUNCTION public.portal_dev_email_guard() RETURNS trigger LANGUAGE plpgsql
SET search_path=pg_catalog,pg_temp AS $guard$
BEGIN
 IF ROW(NEW.reference,NEW.identity_id,NEW.organization_id,NEW.membership_id,NEW.email_normalized,NEW.identity_revision,NEW.membership_revision,NEW.organization_revision,NEW.marker,NEW.digest,NEW.issued_at,NEW.expires_at)
 IS DISTINCT FROM ROW(OLD.reference,OLD.identity_id,OLD.organization_id,OLD.membership_id,OLD.email_normalized,OLD.identity_revision,OLD.membership_revision,OLD.organization_revision,OLD.marker,OLD.digest,OLD.issued_at,OLD.expires_at)
 THEN RAISE EXCEPTION 'Immutable email challenge scope'; END IF;
 IF OLD.status NOT IN ('reserved','delivered') THEN RAISE EXCEPTION 'Finalized email challenge'; END IF;
 IF NEW.attempts<OLD.attempts OR NEW.attempts>OLD.attempts+1 THEN RAISE EXCEPTION 'Monotonic attempts required'; END IF;
 IF NEW.status='revoked' AND NEW.attempts=OLD.attempts AND NEW.consumed_at IS NULL THEN RETURN NEW; END IF;
 IF OLD.status='reserved' AND NEW.status='delivered' AND NEW.attempts=0 AND NEW.consumed_at IS NULL AND OLD.expires_at>clock_timestamp() THEN RETURN NEW; END IF;
 IF OLD.status='delivered' AND NEW.status IN ('delivered','blocked') AND NEW.attempts=OLD.attempts+1 AND NEW.consumed_at IS NULL THEN RETURN NEW; END IF;
 IF OLD.status='delivered' AND NEW.status='consumed' AND NEW.attempts=OLD.attempts AND NEW.consumed_at IS NOT NULL AND OLD.expires_at>clock_timestamp() THEN NEW.consumed_at:=clock_timestamp(); RETURN NEW; END IF;
 RAISE EXCEPTION 'Email challenge transition refused';
END;
$guard$;
REVOKE ALL ON FUNCTION public.portal_dev_email_guard() FROM PUBLIC;
CREATE TRIGGER portal_dev_email_guard BEFORE UPDATE ON public.portal_development_email_challenges FOR EACH ROW EXECUTE FUNCTION public.portal_dev_email_guard();
SQL);
        });
    }
    private function tx(callable $fn):mixed
    {
        try{$this->db->beginTransaction();$this->db->exec("SET LOCAL lock_timeout='2s'; SET LOCAL statement_timeout='5s'");$r=$fn();$this->db->commit();return $r;}
        catch(Throwable){if($this->db->inTransaction())$this->db->rollBack();throw new RuntimeException('Private email operation refused.');}
    }
    public function upgradeTtl():void
    {
        // Explicit operator-approved 15-minute policy. Existing deadlines and
        // terminal states are not rewritten or reactivated by this migration.
        $this->tx(function():void{
            $this->db->exec('LOCK TABLE public.portal_development_email_challenges IN ACCESS EXCLUSIVE MODE');
            $names=$this->db->query("SELECT conname FROM pg_constraint WHERE conrelid='public.portal_development_email_challenges'::regclass AND contype='c' AND cardinality(conkey)=2 AND conkey @> ARRAY[(SELECT attnum FROM pg_attribute WHERE attrelid='public.portal_development_email_challenges'::regclass AND attname='issued_at'),(SELECT attnum FROM pg_attribute WHERE attrelid='public.portal_development_email_challenges'::regclass AND attname='expires_at')]::smallint[]")->fetchAll(PDO::FETCH_COLUMN);
            if(count($names)!==1||preg_match('/\A[a-z0-9_]+\z/D',$names[0])!==1)throw new RuntimeException();
            $this->db->exec('ALTER TABLE public.portal_development_email_challenges DROP CONSTRAINT "'.$names[0].'", ADD CONSTRAINT portal_dev_email_ttl_ck CHECK(expires_at>issued_at AND expires_at<=issued_at+interval \'15 minutes\')');
        });
    }
    private function scope(string $email):array
    {
        $safe=(int)$this->db->query("SELECT count(*) FROM pg_class c JOIN pg_namespace n ON n.oid=c.relnamespace WHERE n.nspname='public' AND c.relname IN ('portal_identities','portal_memberships','portal_organizations','portal_development_email_challenges') AND c.relrowsecurity AND c.relforcerowsecurity")->fetchColumn();
        if($safe!==4)throw new RuntimeException();
        foreach(['portal_browser_runtime','portal_browser_envelope_runtime']as$role){
            $q=$this->db->prepare("SELECT has_table_privilege(?, 'public.portal_development_email_challenges','SELECT,INSERT,UPDATE,DELETE,TRUNCATE')");$q->execute([$role]);
            if(in_array($q->fetchColumn(),[true,1,'t','1'],true))throw new RuntimeException();
        }
        if(filter_var($email,FILTER_VALIDATE_EMAIL)===false||strtolower(trim($email))!==$email)throw new RuntimeException();
        // First-operator pilot only. Locks also fence membership/org revisions.
        $this->db->exec('LOCK TABLE public.portal_identities,public.portal_memberships,public.portal_organizations,public.portal_auth_sessions,public.portal_membership_grants,public.portal_service_resource_claims IN SHARE ROW EXCLUSIVE MODE');
        foreach(['portal_auth_sessions','portal_membership_grants','portal_service_resource_claims']as$table)
            if((int)$this->db->query('SELECT count(*) FROM public.'.$table)->fetchColumn()!==0)throw new RuntimeException();
        foreach(['portal_identities','portal_organizations','portal_memberships']as$table)
            if((int)$this->db->query('SELECT count(*) FROM public.'.$table)->fetchColumn()!==1)throw new RuntimeException();
        $q=$this->db->prepare("SELECT i.id identity_id,m.organization_id,m.id membership_id,i.email_normalized,i.security_revision identity_revision,m.membership_revision,o.security_revision organization_revision
            FROM public.portal_identities i JOIN public.portal_memberships m ON m.identity_id=i.id JOIN public.portal_organizations o ON o.id=m.organization_id
            WHERE i.email_normalized=? AND i.status='invited' AND i.email_verified_at IS NULL AND i.password_hash IS NOT NULL
              AND i.mfa_secret_ciphertext IS NOT NULL AND i.mfa_confirmed_at IS NOT NULL AND m.role='owner' AND m.status='invited' AND o.status='active'
            FOR UPDATE OF i,m,o");$q->execute([$email]);$rows=$q->fetchAll(PDO::FETCH_ASSOC);
        if(count($rows)!==1)throw new RuntimeException();
        $r=$rows[0];$r['marker']=$this->marker;return $r;
    }
    private function digest(array $s,string $ref,string $token):string
    {
        $a=['imagine-private-email-v2-six-digits',$ref];
        foreach(['identity_id','organization_id','membership_id','email_normalized','identity_revision','membership_revision','organization_revision','marker']as$f)$a[]=(string)$s[$f];
        $a[]=$token;return hash_hmac('sha256',json_encode($a,JSON_THROW_ON_ERROR),$this->key);
    }
    private function row(string $ref):array|false
    {
        if(preg_match('/\A[a-f0-9]{32}\z/D',$ref)!==1)throw new RuntimeException();
        $q=$this->db->prepare('SELECT *,expires_at>clock_timestamp() fresh FROM public.portal_development_email_challenges WHERE reference=? FOR UPDATE');$q->execute([$ref]);return $q->fetch(PDO::FETCH_ASSOC);
    }
    private function bound(array $row,array $scope):bool
    {
        foreach($scope as$f=>$v)if((string)$row[$f]!== (string)$v)return false;
        return true;
    }
    private function live(array $r):bool{return in_array($r['fresh'],[true,1,'t','1'],true)&&in_array($r['status'],['reserved','delivered'],true)&&$r['marker']===$this->marker;}
    public function reserve(string $email,string $token,int $ttl):array
    {
        if($ttl!==900||preg_match('/\A[0-9]{6}\z/D',$token)!==1)throw new RuntimeException();
        return $this->tx(function()use($email,$token):array{
            $s=$this->scope($email);
            // Issuance cannot reset the guessing budget by unlimited resends.
            $q=$this->db->prepare("SELECT count(*) hour_count,count(*) FILTER(WHERE issued_at>clock_timestamp()-interval '60 seconds') recent_count FROM public.portal_development_email_challenges WHERE identity_id=? AND issued_at>clock_timestamp()-interval '1 hour'");$q->execute([$s['identity_id']]);$rate=$q->fetch(PDO::FETCH_ASSOC);
            if((int)$rate['hour_count']>=3||(int)$rate['recent_count']>0)throw new RuntimeException();
            $ref=bin2hex(random_bytes(16));
            $q=$this->db->prepare("INSERT INTO public.portal_development_email_challenges(reference,identity_id,organization_id,membership_id,email_normalized,identity_revision,membership_revision,organization_revision,marker,digest,status,issued_at,expires_at)
                VALUES(?,?,?,?,?,?,?,?,?,?,'reserved',statement_timestamp(),statement_timestamp()+interval '15 minutes')");
            $q->execute([$ref,$s['identity_id'],$s['organization_id'],$s['membership_id'],$email,$s['identity_revision'],$s['membership_revision'],$s['organization_revision'],$this->marker,$this->digest($s,$ref,$token)]);
            return ['reference'=>$ref,'recipient'=>$email];
        });
    }
    public function accepted(string $ref,string $email):bool
    {
        return $this->tx(function()use($ref,$email):bool{
            $s=$this->scope($email);$r=$this->row($ref);
            if(!$r||!$this->live($r)||$r['status']!=='reserved'||!$this->bound($r,$s))return false;
            $q=$this->db->prepare("UPDATE public.portal_development_email_challenges SET status='delivered' WHERE reference=? AND status='reserved' AND expires_at>clock_timestamp()");$q->execute([$ref]);return $q->rowCount()===1;
        });
    }
    public function revoke(string $ref):void
    {
        $this->tx(function()use($ref):void{
            $r=$this->row($ref);if(!$r||$r['marker']!==$this->marker)return;
            $q=$this->db->prepare("UPDATE public.portal_development_email_challenges SET status='revoked' WHERE reference=? AND status IN ('reserved','delivered')");$q->execute([$ref]);
        });
    }
    public function consume(string $ref,string $email,string $token):bool
    {
        if(preg_match('/\A[0-9]{6}\z/D',$token)!==1)return false;
        return $this->tx(function()use($ref,$email,$token):bool{
            $s=$this->scope($email);$r=$this->row($ref);
            if(!$r||!$this->live($r)||$r['status']!=='delivered'||!$this->bound($r,$s))return false;
            if(!hash_equals($r['digest'],$this->digest($s,$ref,$token))){
                $q=$this->db->prepare("UPDATE public.portal_development_email_challenges SET attempts=attempts+1,status=CASE WHEN attempts=4 THEN 'blocked' ELSE status END WHERE reference=? AND attempts<5");$q->execute([$ref]);return false;
            }
            $q=$this->db->prepare("UPDATE public.portal_development_email_challenges SET status='consumed',consumed_at=clock_timestamp() WHERE reference=? AND status='delivered' AND expires_at>clock_timestamp() AND attempts<5");$q->execute([$ref]);if($q->rowCount()!==1)throw new RuntimeException();
            $q=$this->db->prepare("UPDATE public.portal_identities SET email_verified_at=clock_timestamp(),updated_at=clock_timestamp() WHERE id=? AND email_verified_at IS NULL AND status='invited' AND security_revision=?");$q->execute([$s['identity_id'],$s['identity_revision']]);if($q->rowCount()!==1)throw new RuntimeException();
            return true;
        });
    }
}
