<?php
declare(strict_types=1);
if(getenv('GITHUB_ACTIONS')!=='true'||getenv('PORTAL_ENROLLMENT_CI')!=='DISPOSABLE_ONLY')exit(64);
require '/opt/portal-enrollment/test.php';
require __DIR__.'/PgLedger.php';
use Imagine\Development\PgLedger;
$email='owner@example.invalid';$emailKey=random_bytes(32);
$ledger=new PgLedger($db,$emailKey,$proof);$ledger->install();
$check((int)$db->query("SELECT count(*) FROM pg_class WHERE oid='public.portal_development_email_challenges'::regclass AND relrowsecurity AND relforcerowsecurity")->fetchColumn()===1);
$reset=static function()use($db,$prepare):void{
    $db->exec('TRUNCATE public.portal_development_email_challenges,public.portal_auth_sessions,public.portal_membership_grants,public.portal_service_resource_claims,public.portal_memberships,public.portal_identities,public.portal_organizations CASCADE');
    $prepare();
};
$issue=static function()use($ledger,$email):array{$token=bin2hex(random_bytes(32));$r=$ledger->reserve($email,$token,540);return [$r['reference'],$token];};
$state=static fn()=>$db->query('SELECT * FROM public.portal_development_email_challenges')->fetch(PDO::FETCH_ASSOC);
$unverified=static fn()=>$db->query('SELECT email_verified_at IS NULL FROM public.portal_identities')->fetchColumn();
$deny(static fn()=>$ledger->install());
[$ref,$token]=$issue();$row=$state();
$check($row['status']==='reserved'&&$row['digest']!==$token&&$row['email_normalized']===$email);
$check(!$ledger->consume($ref,$email,$token));
$deny(static fn()=>$issue());
$check($ledger->accepted($ref,$email));
$check(!$ledger->accepted($ref,$email));
for($i=1;$i<=5;$i++){$check(!$ledger->consume($ref,$email,str_repeat('0',64)));$check((int)$state()['attempts']===$i);}
$check($state()['status']==='blocked');$check(!$ledger->consume($ref,$email,$token));$check((bool)$unverified());
$deny(static fn()=>$db->exec("UPDATE public.portal_development_email_challenges SET status='delivered',attempts=0"));
$reset();[$ref,$token]=$issue();$ledger->accepted($ref,$email);$ledger->revoke($ref);
$check($state()['status']==='revoked');$check(!$ledger->consume($ref,$email,$token));$check((bool)$unverified());
$reset();[$ref,$token]=$issue();$ledger->accepted($ref,$email);
$otherKey=new PgLedger($db,random_bytes(32),$proof);$check(!$otherKey->consume($ref,$email,$token));$check((int)$state()['attempts']===1);
$reset();[$ref,$token]=$issue();$ledger->accepted($ref,$email);
$db->exec('UPDATE public.portal_identities SET security_revision=security_revision+1');
$check(!$ledger->consume($ref,$email,$token));$check((bool)$unverified());
$reset();[$ref,$token]=$issue();$ledger->accepted($ref,$email);
$db->exec('UPDATE public.portal_memberships SET membership_revision=membership_revision+1');
$check(!$ledger->consume($ref,$email,$token));
$reset();[$ref,$token]=$issue();$ledger->accepted($ref,$email);
$db->exec('UPDATE public.portal_organizations SET security_revision=security_revision+1');
$check(!$ledger->consume($ref,$email,$token));
$reset();[$ref,$token]=$issue();$ledger->accepted($ref,$email);
$deny(static fn()=>$db->exec("UPDATE public.portal_development_email_challenges SET expires_at=expires_at+interval '1 minute'"));
$deny(static fn()=>$db->exec("UPDATE public.portal_development_email_challenges SET digest=repeat('0',64)"));
// Seed only a synthetic expired copy; not an operational mutation path.
$row=$state();$db->exec('DELETE FROM public.portal_development_email_challenges');
$q=$db->prepare("INSERT INTO public.portal_development_email_challenges(reference,identity_id,organization_id,membership_id,email_normalized,identity_revision,membership_revision,organization_revision,marker,digest,status,issued_at,expires_at) VALUES(?,?,?,?,?,?,?,?,?,?,'delivered',statement_timestamp()-interval '10 minutes',statement_timestamp()-interval '1 minute')");
$q->execute(array_map(static fn($k)=>$row[$k],['reference','identity_id','organization_id','membership_id','email_normalized','identity_revision','membership_revision','organization_revision','marker','digest']));
$check(!$ledger->consume($ref,$email,$token));$check((bool)$unverified());
$reset();[$ref,$token]=$issue();$ledger->accepted($ref,$email);
// Force second write failure: consumed proof and email must roll back together.
$db->exec(<<<'SQL'
CREATE FUNCTION public.email_ci_failure() RETURNS trigger LANGUAGE plpgsql AS $$ BEGIN RAISE EXCEPTION 'Synthetic identity write failure'; END; $$;
CREATE TRIGGER email_ci_failure BEFORE UPDATE ON public.portal_identities FOR EACH ROW EXECUTE FUNCTION public.email_ci_failure();
SQL);
$deny(static fn()=>$ledger->consume($ref,$email,$token));$check($state()['status']==='delivered');$check((bool)$unverified());
$db->exec('DROP TRIGGER email_ci_failure ON public.portal_identities; DROP FUNCTION public.email_ci_failure()');
$db->exec("ALTER TABLE public.portal_development_email_challenges NO FORCE ROW LEVEL SECURITY");$deny(static fn()=>$ledger->consume($ref,$email,$token));
$db->exec("ALTER TABLE public.portal_development_email_challenges FORCE ROW LEVEL SECURITY; GRANT SELECT ON public.portal_development_email_challenges TO portal_browser_runtime");$deny(static fn()=>$ledger->consume($ref,$email,$token));
$db->exec("REVOKE SELECT ON public.portal_development_email_challenges FROM portal_browser_runtime");
$second=new PDO('pgsql:host=127.0.0.1;port=5432;dbname=portal_identity_dev','postgres',file_get_contents('/run/secrets/database-admin'),[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_EMULATE_PREPARES=>false]);
$otherLedger=new PgLedger($second,$emailKey,$proof);
$db->beginTransaction();$db->query('SELECT reference FROM public.portal_development_email_challenges FOR UPDATE')->fetchAll();
try{$deny(static fn()=>$otherLedger->consume($ref,$email,$token));}finally{$db->rollBack();}
$check($state()['status']==='delivered');$check((bool)$unverified());
$check($ledger->consume($ref,$email,$token));
$deny(static fn()=>$ledger->consume($ref,$email,$token));
$check(!(bool)$unverified());$check($state()['status']==='consumed');
$check($db->query('SELECT status FROM public.portal_identities')->fetchColumn()==='invited');
$check($db->query('SELECT status FROM public.portal_memberships')->fetchColumn()==='invited');
$check((int)$db->query('SELECT count(*) FROM public.portal_auth_sessions')->fetchColumn()===0);
$check((int)$db->query('SELECT count(*) FROM public.portal_membership_grants')->fetchColumn()===0);
echo 'PASS private email ledger: '.$checks." cumulative checks; no login activated\n";
