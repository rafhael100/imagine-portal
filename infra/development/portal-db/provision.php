<?php

declare(strict_types=1);

// Private development bootstrap, never a web route or customer account issuer.
try {
    require __DIR__.'/vendor/autoload.php';
    $mode=$argv[1]??'';
    if (!in_array($mode,['install','verify'],true)) throw new RuntimeException();
    $config=\App\Infrastructure\Identity\PortalBrowserSecretFile::load(
        '/run/secrets/runtime.json','https://portal-dev.imaginecomunica.com.br'
    );
    if ($config->value('host')!=='127.0.0.1'||$config->value('port')!==5432
        ||$config->value('database')!=='portal_identity_dev') throw new RuntimeException();
    $read=static function(string $path):string {
        clearstatcache(true,$path);
        $s=lstat($path);
        if (!is_array($s)||($s['mode']&0170000)!==0100000||($s['mode']&0077)!==0
            ||$s['uid']!==posix_geteuid()||$s['nlink']!==1||$s['size']<1||$s['size']>256)
            throw new RuntimeException();
        $h=fopen($path,'rb');
        if($h===false)throw new RuntimeException();
        try {
            $opened=fstat($h);
            if($opened['ino']!==$s['ino']||$opened['dev']!==$s['dev'])throw new RuntimeException();
            $value=stream_get_contents($h,257);
        } finally {fclose($h);}
        if(!is_string($value)||strlen($value)>256)throw new RuntimeException();
        return $value;
    };
    $admin=$read('/run/secrets/database-admin');
    $key=$read('/run/secrets/app-key');
    if(preg_match('/\A[a-f0-9]{64}\z/D',$admin)!==1
        ||!str_starts_with($key,'base64:')||strlen(base64_decode(substr($key,7),true)?:'')!==32)
        throw new RuntimeException();
    $dsn="pgsql:host=127.0.0.1;port=5432;dbname=portal_identity_dev;connect_timeout=3;sslmode=disable;options='--statement_timeout=30000 --lock_timeout=3000'";
    $options=[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_EMULATE_PREPARES=>false,PDO::ATTR_PERSISTENT=>false];
    $db=new PDO($dsn,'postgres',$admin,$options);
    $identity=$db->query("SELECT current_database() db,current_user actor,session_user login,current_setting('server_version_num')::int/10000 version")->fetch(PDO::FETCH_ASSOC);
    if($identity['db']!=='portal_identity_dev'||$identity['actor']!=='postgres'||$identity['login']!=='postgres'
        ||(int)$identity['version']!==18)throw new RuntimeException();
    $app=require __DIR__.'/bootstrap/app.php';
    $app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();
    $app['config']->set('app.key',$key);
    $app['config']->set('logging.default','null');
    $app['config']->set('database.default','pgsql');
    $app['config']->set('database.connections.pgsql',[
        'driver'=>'pgsql','host'=>'127.0.0.1','port'=>5432,'database'=>'portal_identity_dev',
        'username'=>'postgres','password'=>$admin,'charset'=>'utf8','prefix'=>'',
        'search_path'=>'public','sslmode'=>'disable',
    ]);
    \Illuminate\Support\Facades\DB::purge('pgsql');
    if($mode==='install'){
        // Never run migrations into a populated or partially installed database.
        $tables=(int)$db->query("SELECT count(*) FROM pg_tables WHERE schemaname NOT IN ('pg_catalog','information_schema')")->fetchColumn();
        $roles=(int)$db->query("SELECT count(*) FROM pg_roles WHERE rolname LIKE 'portal_browser_%'")->fetchColumn();
        if($tables!==0||$roles!==0)throw new RuntimeException();
        $migrations=[
            '2026_11_09_000002_create_portal_identity_foundation.php',
            '2026_11_09_000003_add_mfa_replay_counter_to_portal_identities.php',
            '2026_11_10_000004_add_org_revision_to_portal_sessions.php',
            '2026_11_10_000005_create_portal_auth_throttle_buckets.php',
            '2026_11_10_000008_add_session_purpose_to_portal_auth_sessions.php',
            '2026_11_11_000012_fence_portal_membership_revision.php',
            '2026_11_11_000013_fence_portal_organization_revision.php',
            '2026_11_11_000014_guard_portal_session_issuance.php',
            '2026_11_12_000016_fence_portal_identity_revision.php',
            '2026_11_12_000017_create_portal_service_resource_claims.php',
        ];
        foreach($migrations as$migration){
            if(\Illuminate\Support\Facades\Artisan::call('migrate',[
                '--path'=>'database/migrations/'.$migration,'--force'=>true,'--no-interaction'=>true,
            ])!==0)throw new RuntimeException();
        }
        foreach(['portal_browser_auth.sql','portal_browser_envelopes.sql']as$file){
            $sql=file_get_contents(__DIR__.'/database/security/'.$file);
            if(!is_string($sql))throw new RuntimeException();
            $db->exec($sql);
        }
        $db->beginTransaction();
        foreach(['portal_browser_runtime'=>'identity_password','portal_browser_envelope_runtime'=>'envelope_password']as$role=>$field)
            $db->exec('ALTER ROLE '.$role.' LOGIN PASSWORD '.$db->quote($config->value($field)));
        $q=$db->prepare('INSERT INTO portal_browser_private.proof_key(secret) VALUES(?)');
        $q->execute([$config->value('proof_key')]);
        $db->exec('REVOKE CONNECT ON DATABASE portal_identity_dev FROM PUBLIC');
        $db->exec('GRANT CONNECT ON DATABASE portal_identity_dev TO portal_browser_runtime,portal_browser_envelope_runtime');
        $db->exec('REVOKE CREATE ON SCHEMA public FROM PUBLIC');
        $db->exec("CREATE TABLE public.portal_development_installation (singleton boolean PRIMARY KEY CHECK(singleton), version integer NOT NULL, marker text NOT NULL)");
        $q=$db->prepare('INSERT INTO public.portal_development_installation VALUES(true,1,?)');
        $q->execute([bin2hex(random_bytes(32))]);
        $db->exec('REVOKE ALL ON public.portal_development_installation FROM PUBLIC');
        $db->commit();
    }
    $marker=$db->query('SELECT marker FROM public.portal_development_installation WHERE singleton AND version=1')->fetchColumn();
    if(!is_string($marker)||strlen($marker)!==64)throw new RuntimeException();
    // Foundation verification is intentionally before real identity enrollment.
    foreach(['portal_identities','portal_auth_sessions','portal_memberships','portal_organizations']as$table)
        if((int)$db->query('SELECT count(*) FROM public.'.$table)->fetchColumn()!==0)throw new RuntimeException();
    $q=$db->prepare('SELECT secret=? FROM portal_browser_private.proof_key');
    $q->execute([$config->value('proof_key')]);
    if(!in_array($q->fetchColumn(),[true,1,'t','1'],true))throw new RuntimeException();
    $roles=$db->query("SELECT count(*) FROM pg_roles WHERE rolname IN ('portal_browser_owner','portal_browser_runtime','portal_browser_envelope_owner','portal_browser_envelope_runtime') AND NOT (rolsuper OR rolcreatedb OR rolcreaterole OR rolinherit OR rolbypassrls) AND ((rolname IN ('portal_browser_owner','portal_browser_envelope_owner') AND NOT rolcanlogin) OR (rolname IN ('portal_browser_runtime','portal_browser_envelope_runtime') AND rolcanlogin))")->fetchColumn();
    if((int)$roles!==4)throw new RuntimeException();
    // F330: role attributes alone cannot prove that the runtime roles
    // cannot read or write a protected business/auth table. Check the
    // effective grants (including PUBLIC) for *both* runtime principals.
    $unexpectedTablePrivileges=(int)$db->query(<<<'SQL'
SELECT count(*)
FROM unnest(ARRAY['portal_browser_runtime','portal_browser_envelope_runtime']) AS roles(role_name)
CROSS JOIN unnest(ARRAY[
    'public.portal_identities',
    'public.portal_auth_sessions',
    'public.portal_memberships',
    'public.portal_membership_grants',
    'public.portal_organizations',
    'public.portal_auth_attempt_buckets',
    'public.portal_browser_envelopes',
    'public.portal_service_resource_claims',
    'portal_browser_private.proof_key',
    'public.portal_development_installation'
]) AS protected_tables(table_name)
WHERE has_table_privilege(roles.role_name,protected_tables.table_name,'SELECT')
   OR has_table_privilege(roles.role_name,protected_tables.table_name,'INSERT')
   OR has_table_privilege(roles.role_name,protected_tables.table_name,'UPDATE')
   OR has_table_privilege(roles.role_name,protected_tables.table_name,'DELETE')
   OR has_table_privilege(roles.role_name,protected_tables.table_name,'TRUNCATE')
SQL)->fetchColumn();
    if($unexpectedTablePrivileges!==0)throw new RuntimeException();

    // The two function surfaces must remain mutually exclusive. Do not
    // accept a role if an accidental grant opens the other role's API.
    $crossRoleExecute=(int)$db->query(<<<'SQL'
SELECT count(*)
FROM (VALUES
    ('portal_browser_runtime','public.portal_browser_envelope_read(text)'),
    ('portal_browser_runtime','public.portal_browser_envelope_write(text,text)'),
    ('portal_browser_runtime','public.portal_browser_envelope_destroy(text)'),
    ('portal_browser_runtime','public.portal_browser_envelope_gc()'),
    ('portal_browser_envelope_runtime','public.portal_browser_begin(text,uuid,text,text,text)'),
    ('portal_browser_envelope_runtime','public.portal_browser_complete(text,text,bigint,text,text)'),
    ('portal_browser_envelope_runtime','public.portal_browser_active(text)'),
    ('portal_browser_envelope_runtime','public.portal_browser_permission_preflight(text,uuid,text)')
) AS forbidden(role_name,function_signature)
WHERE has_function_privilege(role_name,function_signature,'EXECUTE')
SQL)->fetchColumn();
    if($crossRoleExecute!==0)throw new RuntimeException();

    // F331: roles with NOINHERIT may nevertheless have SET ROLE privilege
    // when a future administrator accidentally grants membership. Refuse
    // *any* membership, and all DDL/USAGE escape hatches independently of
    // table grants, not merely the role attribute flags.
    $runtimeMemberOfRole=(int)$db->query(<<<'SQL'
SELECT count(*) FROM pg_auth_members a
JOIN pg_roles r ON r.oid=a.member
WHERE r.rolname IN ('portal_browser_runtime','portal_browser_envelope_runtime')
SQL)->fetchColumn();
    if($runtimeMemberOfRole!==0)throw new RuntimeException();

    $dangerousSchemaOrDatabaseGrants=(int)$db->query(<<<'SQL'
SELECT count(*)
FROM unnest(ARRAY['portal_browser_runtime','portal_browser_envelope_runtime']) AS roles(role_name)
WHERE has_schema_privilege(roles.role_name,'public','CREATE')
   OR has_schema_privilege(roles.role_name,'portal_browser_private','USAGE')
   OR has_schema_privilege(roles.role_name,'portal_browser_private','CREATE')
   OR has_database_privilege(roles.role_name,'portal_identity_dev','CREATE')
SQL)->fetchColumn();
    if($dangerousSchemaOrDatabaseGrants!==0)throw new RuntimeException();

    // F332: ALTER DEFAULT PRIVILEGES affects objects created *later*.
    // Today's table/function ACL checks (F330) cannot discover a
    // pre-authorized grant on a future object. Reject any explicit
    // default ACL entry targeting the application runtime principals.
    $dangerousFutureGrants=(int)$db->query(<<<'SQL'
SELECT count(*)
FROM pg_default_acl defaults
CROSS JOIN LATERAL aclexplode(defaults.defaclacl) AS privileges
JOIN pg_roles recipient ON recipient.oid=privileges.grantee
WHERE recipient.rolname IN ('portal_browser_runtime','portal_browser_envelope_runtime')
SQL)->fetchColumn();
    if($dangerousFutureGrants!==0)throw new RuntimeException();

    // F333: PostgreSQL grants CONNECT to PUBLIC by default. A one-time
    // REVOKE during bootstrap is insufficient if a later administrator
    // restores it; explicitly check the *effective* database ACL on every
    // verify, including the null-ACL/default case.
    $unexpectedDatabaseConnect=(int)$db->query(<<<'SQL'
SELECT count(*)
FROM pg_database db
CROSS JOIN LATERAL aclexplode(COALESCE(db.datacl,acldefault('d',db.datdba))) AS access
WHERE db.datname='portal_identity_dev'
  AND access.privilege_type='CONNECT'
  AND (
    access.grantee=0
    OR access.grantee NOT IN (
      SELECT oid FROM pg_roles WHERE rolname IN (
        'postgres','portal_browser_runtime','portal_browser_envelope_runtime'
      )
    )
  )
SQL)->fetchColumn();
    if($unexpectedDatabaseConnect!==0)throw new RuntimeException();

    // F334: FORCE RLS is a structural invariant, not an installation-time
    // checkbox. A subsequent migration/maintenance ALTER TABLE could
    // disable it while leaving the SQL role flags and ACLs unchanged.
    // Include the private proof-key table in addition to business tables.
    $rlsProtectedTables=(int)$db->query(<<<'SQL'
SELECT count(*)
FROM pg_class relations
JOIN pg_namespace schemas ON schemas.oid=relations.relnamespace
WHERE schemas.nspname||'.'||relations.relname IN (
    'public.portal_identities',
    'public.portal_auth_sessions',
    'public.portal_memberships',
    'public.portal_membership_grants',
    'public.portal_organizations',
    'public.portal_auth_attempt_buckets',
    'public.portal_browser_envelopes',
    'public.portal_service_resource_claims',
    'portal_browser_private.proof_key'
)
AND relations.relkind='r'
AND relations.relrowsecurity
AND relations.relforcerowsecurity
SQL)->fetchColumn();
    if($rlsProtectedTables!==9)throw new RuntimeException();
    // F329: provisioning verifies the same restricted composition that the
    // browser candidate uses, not a separately assembled connection pair.
    // No HTTP route, session manager or application service is registered.
    $runtime=new \App\Infrastructure\Identity\PortalBrowserPrivateRuntime($config);
    $auth=$runtime->authentication();
    if($auth->isActiveBusinessSession(bin2hex(random_bytes(32))))throw new RuntimeException();
    // F329: this also validates the AES session key is independent from
    // TOTP/HMAC/pepper and both PostgreSQL role passwords (F326/F327).
    $creator=$runtime->sessionCreator(
        new \Illuminate\Encryption\Encrypter(base64_decode(substr($key,7),true),'AES-256-CBC')
    );
    $handler=$creator();
    $id=bin2hex(random_bytes(20));
    try{
        if(!$handler->write($id,'private-development-storage-probe')||$handler->read($id)!=='private-development-storage-probe')
            throw new RuntimeException();
        $handler->close();
        if(!$handler->destroy($id))throw new RuntimeException();
    }finally{$handler->close();}
    // Only a random non-secret installation marker fingerprint is emitted.
    echo 'PASS private Portal database v1 fingerprint='.hash('sha256',$marker)."\n";
} catch (Throwable) {
    fwrite(STDERR,"Private Portal database provisioning failed; no credentials emitted.\n");
    exit(1);
}
