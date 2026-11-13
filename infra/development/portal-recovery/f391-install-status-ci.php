<?php

declare(strict_types=1);

/**
 * F391 disposable-only SQL candidate installation.
 * No Laravel migration, deployment or production schema change.
 */
if(PHP_SAPI!=='cli'||$argc!==1
    ||getenv('GITHUB_ACTIONS')!=='true'
    ||getenv('RUNNER_ENVIRONMENT')!=='github-hosted'
    ||getenv('PORTAL_F391_CI')!=='DISPOSABLE_ONLY')exit(64);

try{
    $password=trim((string)file_get_contents('/run/secrets/database-admin'));
    $options=[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_EMULATE_PREPARES=>false];
    $dsn='pgsql:host=127.0.0.1;port=5432;dbname=portal_identity_dev';
    $db=new PDO($dsn,'postgres',$password,$options);
    unset($password);
    if($db->query("SELECT current_database()")->fetchColumn()!=='portal_identity_dev'){
        throw new RuntimeException();
    }
    $name='/opt/f391-status-candidate.sql';
    $sql=file_get_contents($name);
    if(!is_string($sql)||strlen($sql)<500||strlen($sql)>12000)throw new RuntimeException();
    $db->exec($sql);
    $signature='public.portal_browser_revoke_status_candidate(text,uuid,text)';
    $q=$db->prepare(
        "SELECT p.prosecdef,r.rolname AS owner,
          has_function_privilege('portal_browser_runtime',p.oid,'EXECUTE') AS enabled,
          has_function_privilege('portal_browser_envelope_runtime',p.oid,'EXECUTE') AS unintended,
          c.relrowsecurity AS rls,c.relforcerowsecurity AS force_rls
        FROM pg_proc p
        JOIN pg_roles r ON r.oid=p.proowner
        JOIN pg_class c ON c.oid='public.portal_auth_sessions'::regclass
        WHERE p.oid=to_regprocedure(?)"
    );
    $q->execute([$signature]);
    $result=$q->fetch(PDO::FETCH_ASSOC);
    $true=static fn($v):bool=>in_array($v,[true,'t','1',1],true);
    if(!is_array($result)
        ||$result['owner']!=='portal_browser_owner'
        ||!$true($result['prosecdef'])
        ||!$true($result['enabled'])
        ||$true($result['unintended'])
        ||!$true($result['rls'])||!$true($result['force_rls'])){
        throw new RuntimeException();
    }
    $passwordConfig=json_decode(
        (string)file_get_contents('/run/secrets/runtime.json'),
        true,8,JSON_THROW_ON_ERROR
    );
    $runtime=new PDO($dsn,'portal_browser_runtime',
        $passwordConfig['identity_password'],$options);
    $denied=$runtime->query(
        "SELECT public.portal_browser_revoke_status_candidate('"
        .str_repeat('a',64)."','11111111-1111-4111-8111-111111111111','"
        .str_repeat('0',64)."')"
    )->fetchColumn();
    if($denied!=='denied')throw new RuntimeException();

    echo "PASS F391 install: PG18 RLS/NOINHERIT restricted HMAC status function, no envelope-role grant; invalid proof denied.\n";
}catch(Throwable){
    fwrite(STDERR,"FAIL F391: disposable HMAC SQL status candidate refused\n");
    exit(1);
}
