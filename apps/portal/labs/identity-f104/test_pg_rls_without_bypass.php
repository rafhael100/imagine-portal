<?php

declare(strict_types=1);

require_once dirname(__DIR__).'/identity-f100/PortalF100OfflinePermissionProbe.php';
require_once dirname(__DIR__).'/identity-f101/PortalF101OfflineResourceProbe.php';

if (PHP_SAPI !== 'cli'
    || getenv('APP_ENV') !== 'testing'
    || getenv('PORTAL_F104_PG_LAB') !== '1'
    || getenv('PORTAL_F100_PG_LAB') !== '1'
    || getenv('PORTAL_F101_PG_LAB') !== '1'
    || getenv('PGHOST') !== '127.0.0.1'
    || getenv('PGPORT') !== '5432'
    || getenv('PGDATABASE') !== 'portal_identity_lab') {
    throw new RuntimeException('F104 only runs in disposable PostgreSQL 18 CI.');
}
$adminPassword=getenv('F66_PG_ADMIN_PASSWORD');
$f76Password=getenv('F76_PG_READER_PASSWORD');
$f101Password=getenv('F101_PG_READER_PASSWORD');
if (!is_string($adminPassword) || strlen($adminPassword)<12
    || !is_string($f76Password) || strlen($f76Password)<12
    || !is_string($f101Password) || strlen($f101Password)<12) {
    throw new RuntimeException('F104 requires ephemeral CI-only credentials.');
}
$connect=static fn(string $role,string $password):PDO => new PDO(
    'pgsql:host=127.0.0.1;port=5432;dbname=portal_identity_lab;connect_timeout=3',
    $role,$password,[
        PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES=>false,
    ]
);
$db=$connect('postgres',$adminPassword);
$reader76=$connect('portal_f76_reader',$f76Password);
$reader101=$connect('portal_f101_reader',$f101Password);
$f76=new PortalF100OfflinePermissionProbe($reader76);
$f101=new PortalF101OfflineResourceProbe($reader101);
$count=0;
$ok=static function(bool $valid,string $label)use(&$count):void{
    if(!$valid) throw new RuntimeException('F104 FAILED: '.$label);
    ++$count;
};
$yes=static fn(mixed $v):bool=>in_array($v,[true,1,'1','t'],true);
$no=static fn(mixed $v):bool=>in_array($v,[false,0,'0','f'],true);

$role=$db->query(
    "SELECT rolcanlogin,rolsuper,rolbypassrls,rolcreaterole,rolcreatedb,rolinherit
       FROM pg_roles WHERE rolname='portal_f102_security_owner'"
)->fetch();
$ok(is_array($role)
    && $no($role['rolcanlogin']) && $no($role['rolsuper'])
    && $no($role['rolbypassrls']) && $no($role['rolcreaterole'])
    && $no($role['rolcreatedb']) && $no($role['rolinherit']),
    'definer role NOLOGIN NOSUPERUSER NOBYPASSRLS and no admin privileges');

$tables=[
    'portal_identities',
    'portal_organizations',
    'portal_memberships',
    'portal_membership_grants',
    'portal_auth_sessions',
    'portal_f101_resource_fixtures',
];
$policies=$db->query(
    "SELECT tablename,policyname,cmd,roles,qual,with_check
       FROM pg_policies
      WHERE schemaname='public' AND tablename LIKE 'portal_%'
      ORDER BY tablename"
)->fetchAll();
$ok(count($policies)===count($tables),'exactly six closed definer policies exist');
$found=[];
foreach($policies as $policy) {
    $found[]=$policy['tablename'];
    $ok(
        $policy['policyname']==='portal_f104_definer_read'
        && $policy['cmd']==='SELECT'
        && $policy['roles']==='{portal_f102_security_owner}'
        && trim((string)$policy['qual'],'() ')==='true'
        && $policy['with_check']===null,
        'SELECT policy scoped to NOLOGIN role, not PUBLIC or readers: '.$policy['tablename']
    );
}
sort($found);
$expected=$tables;
sort($expected);
$ok($found===$expected,'policies cover exactly the six intended tables');

$rls=$db->query(
    "SELECT c.relname,c.relrowsecurity,c.relforcerowsecurity
       FROM pg_class c JOIN pg_namespace n ON n.oid=c.relnamespace
      WHERE n.nspname='public' AND c.relname LIKE 'portal_%'
        AND c.relkind='r'"
)->fetchAll();
$ok(count($rls)===6,'exactly six identity/resource tables in isolated lab');
foreach($rls as $table) {
    $ok($yes($table['relrowsecurity']) && $yes($table['relforcerowsecurity']),
        'FORCE RLS remains enabled: '.$table['relname']);
}
$functions=$db->query(
    "SELECT proname,proconfig,prosecdef,pg_get_userbyid(proowner) AS owner
       FROM pg_proc WHERE oid IN (
        'public.portal_f76_permission_check(text,uuid,text)'::regprocedure,
        'public.portal_f101_resource_check(text,uuid,uuid,text)'::regprocedure
       ) ORDER BY proname"
)->fetchAll();
$ok(count($functions)===2,'both permission predicates present');
foreach($functions as $function) {
    $config=implode(' ',(array)$function['proconfig']);
    // PDO PostgreSQL represents PostgreSQL text[] as string, too.
    $config=is_string($function['proconfig'])
        ? $function['proconfig'] : $config;
    $ok($function['owner']==='portal_f102_security_owner'
        && $yes($function['prosecdef'])
        && str_contains($config,'search_path=pg_catalog, pg_temp')
        && str_contains($config,'row_security=on'),
        'fixed search_path, row_security and restricted definer: '.$function['proname']);
}
$membership=$db->query(
    "SELECT count(*) FROM pg_auth_members
      WHERE roleid='portal_f102_security_owner'::regrole
         OR member='portal_f102_security_owner'::regrole"
)->fetchColumn();
$ok((int)$membership===0,'role not inherited by application, readers or other roles');

$directCheck=$db->prepare(
    "SELECT has_table_privilege(:role,:table,'SELECT')"
);
foreach($tables as $table){
    $directCheck->execute(['role'=>'portal_f76_reader','table'=>'public.'.$table]);
    $ok($no($directCheck->fetchColumn()),'F76 reader cannot SELECT '.$table);
    $directCheck->execute(['role'=>'portal_f101_reader','table'=>'public.'.$table]);
    $ok($no($directCheck->fetchColumn()),'F101 reader cannot SELECT '.$table);
}

$identity='f1111111-1111-4111-8111-111111111111';
$org='f2222222-2222-4222-8222-222222222222';
$other='f3333333-3333-4333-8333-333333333333';
$member='f4444444-4444-4444-8444-444444444444';
$grant='f5555555-5555-4555-8555-555555555555';
$session='f6666666-6666-4666-8666-666666666666';
$resource='f7777777-7777-4777-8777-777777777777';
$bearer=rtrim(strtr(base64_encode(random_bytes(32)),'+/','-_'),'=');

$db->prepare(
    "INSERT INTO portal_identities
      (id,email_normalized,status,email_verified_at,mfa_confirmed_at,
       security_revision,created_at,updated_at)
     VALUES(?,?,'active',now()-interval '1 day',now()-interval '1 day',1,now(),now())"
)->execute([$identity,'f104@example.invalid']);
$orgInsert=$db->prepare(
    "INSERT INTO portal_organizations
      (id,reference,display_name,status,security_revision,created_at,updated_at)
     VALUES(?,?,?,'active',1,now(),now())"
);
$orgInsert->execute([$org,'f104-home','F104 synthetic home']);
$orgInsert->execute([$other,'f104-other','F104 synthetic foreign']);
$db->prepare(
    "INSERT INTO portal_memberships
      (id,identity_id,organization_id,role,status,membership_revision,created_at,updated_at)
     VALUES(?,?,?,'member','active',1,now(),now())"
)->execute([$member,$identity,$org]);
$db->prepare(
    "INSERT INTO portal_membership_grants
      (id,membership_id,permission_code,created_at,updated_at)
     VALUES(?,?,'services.view',now(),now())"
)->execute([$grant,$member]);
$db->prepare(
    "INSERT INTO portal_auth_sessions
      (id,session_sha256,identity_id,organization_id,status,
       identity_revision,membership_revision,organization_revision,
       mfa_verified_at,issued_at,expires_at,created_at,updated_at)
     VALUES(?,?,?,?, 'active',1,1,1,now()-interval '2 minutes',
      now()-interval '3 minutes',now()+interval '20 minutes',now(),now())"
)->execute([$session,hash('sha256',$bearer),$identity,$org]);
$db->prepare(
    "INSERT INTO portal_f101_resource_fixtures(id,organization_id,status,display_ref)
     VALUES(?,?,'active','f104-fixture')"
)->execute([$resource,$org]);

$check76=static fn():bool=>$f76->check($bearer,$org,'services.view');
$check101=static fn():bool=>$f101->check($bearer,$org,$resource,'services.view');
$ok($check76(),'F76 SQL can still validate synthetic MFA grant without BYPASSRLS');
$ok($check101(),'F101 SQL can verify resource ownership without BYPASSRLS');
$ok(!$f101->check($bearer,$other,$resource,'services.view'),
    'foreign company denied despite definer SELECT policy');
$ok(!$f101->check($bearer,$org,$resource,'services.manage'),
    'a missing management grant cannot be inferred');
$ok(!$f76->check($bearer,$org,'*'),'wildcards still fail closed');

// Removing a single RLS allow policy must close access even with SELECT
// column grants and a valid synthetic session. The F76 function remains
// unaffected by denial of the separate resource fixture policy.
$db->exec(
    "ALTER POLICY portal_f104_definer_read
       ON public.portal_f101_resource_fixtures USING (false)"
);
$ok(!$check101(),'resource policy denied -> F101 fails closed');
$ok($check76(),'F76 still works when only resource policy becomes DENY');
$db->exec(
    "ALTER POLICY portal_f104_definer_read
       ON public.portal_f101_resource_fixtures USING (true)"
);
$ok($check101(),'restoring scoped resource policy restores lab-only F101');

$db->exec(
    "ALTER POLICY portal_f104_definer_read
       ON public.portal_auth_sessions USING (false)"
);
$ok(!$check76(),'session RLS policy denied -> F76 fails closed');
$ok(!$check101(),'session RLS policy denied -> F101 fails closed');
$db->exec(
    "ALTER POLICY portal_f104_definer_read
       ON public.portal_auth_sessions USING (true)"
);
$ok($check76() && $check101(),'restored scoped session policy restores lab-only gates');

$db->exec("UPDATE portal_auth_sessions SET status='revoked',revoked_at=now() WHERE id='$session'");
$ok(!$check76() && !$check101(),'committed session revocation denies both gates');
$db->exec("UPDATE portal_auth_sessions SET status='active',revoked_at=NULL WHERE id='$session'");
$db->exec("UPDATE portal_f101_resource_fixtures SET organization_id='$other' WHERE id='$resource'");
$ok(!$check101(),'resource ownership transfer denies without BYPASSRLS');

putenv('PORTAL_F101_PG_LAB=0');
$ok(!$check101(),'F101 disabled lab flag still denies');
putenv('PORTAL_F101_PG_LAB=1');
putenv('APP_ENV=production');
$ok(!$check76() && !$check101(),'production env cannot invoke lab-only clients');

echo "PASS PORTAL-F104 $count scoped RLS and no-BYPASSRLS lab assertions\n";
