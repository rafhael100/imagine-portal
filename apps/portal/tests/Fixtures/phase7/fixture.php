<?php
declare(strict_types=1);

// This file is mounted only into an explicit CI one-shot. It cannot bootstrap
// another database, reset an existing schema or accept caller-supplied SQL.
$stage='guard';
try {
    if(PHP_SAPI!=='cli'||getenv('GITHUB_ACTIONS')!=='true'||getenv('ADMIN_PHASE7_CI')!=='1')throw new RuntimeException('P7_CI_ONLY');
    $kind=$argv[1]??'';$action=$argv[2]??'';
    if(!in_array($kind,['portal','receiver'],true)||!in_array($action,['prepare','snapshot','revoke-grant','restore-grant','extra-privilege','remove-privilege','extra-column-privilege','remove-column-privilege','expire-mfa','revoke-session'],true))throw new RuntimeException('P7_ACTION_DENIED');
    $host=$kind==='portal'?'portal-db':'performance-db';
    $database=$kind==='portal'?'portal_p6_synthetic':'performance_p6_synthetic';
    $owner=$kind==='portal'?'portal_p6_migrator':'performance_p6_migrator';
    $ca=$kind==='portal'?'/run/pki/portal_db_ca.pem':'/run/private/db-ca.pem';
    $db=new PDO("pgsql:host=$host;port=5432;dbname=$database;sslmode=verify-full;sslrootcert=$ca",$owner,trim(file_get_contents('/run/phase7/owner-password')),[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
    $identity=$db->query("SELECT current_database() db,current_user actor,(SELECT ssl FROM pg_stat_ssl WHERE pid=pg_backend_pid()) tls,(SELECT system_identifier::text FROM pg_control_system()) cluster")->fetch(PDO::FETCH_ASSOC);
    if($identity['db']!==$database||$identity['actor']!==$owner||!$identity['tls']||!hash_equals(trim(file_get_contents('/run/phase7/cluster')),$identity['cluster']))throw new RuntimeException('P7_DATABASE_IDENTITY_DENIED');
    $company='aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa';
    if($action==='prepare') {
        $stage='empty-database';
        if((int)$db->query("SELECT count(*) FROM pg_class c JOIN pg_namespace n ON n.oid=c.relnamespace WHERE n.nspname NOT IN ('pg_catalog','information_schema') AND n.nspname NOT LIKE 'pg_toast%' AND c.relkind IN ('r','p')")->fetchColumn()!==0)throw new RuntimeException('P7_NONEMPTY_DATABASE_DENIED');
    }
    if($kind==='portal') {
        $stage='portal-bootstrap';
        require '/var/www/html/vendor/autoload.php';
        $app=require '/var/www/html/bootstrap/app.php';
        $app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
        if(config('database.connections.pgsql.sslmode')!=='verify-full'||config('database.connections.pgsql.sslrootcert')!=='/run/pki/portal_db_ca.pem'||config('database.connections.pgsql.username')!==$owner||config('database.connections.pgsql.database')!==$database||config('app.env')!=='staging')throw new RuntimeException('P7_CONFIGURATION_DENIED');
        if($action==='prepare') {
            $stage='portal-migrations';
            if(Illuminate\Support\Facades\Artisan::call('migrate',['--force'=>true,'--no-interaction'=>true])!==0)throw new RuntimeException('P7_MIGRATION_FAILED');
            $stage='portal-roles';
            foreach(['portal_p6_runtime'=>'portal_db_password','portal_p6_authority'=>'portal_authority_password'] as $role=>$file) {
                $runtimePassword=trim(file_get_contents('/run/secrets/'.$file));
                if(hash_equals(trim(file_get_contents('/run/phase7/owner-password')),$runtimePassword))throw new RuntimeException('P7_RUNTIME_OWNER_PASSWORD_REUSE_DENIED');
                $pw=$db->quote($runtimePassword);
                $db->exec("CREATE ROLE $role LOGIN PASSWORD $pw NOSUPERUSER NOBYPASSRLS NOCREATEDB NOCREATEROLE NOREPLICATION; GRANT CONNECT ON DATABASE portal_p6_synthetic TO $role; GRANT USAGE ON SCHEMA public TO $role");
            }
            // The same minimal grants as deploy/staging/grant-runtime.sql. No
            // ALL TABLES, migration owner, session_version or credential writes.
            $db->exec('GRANT SELECT ON portal_admin_users,portal_admin_org_grants,performance_admin_lab_memberships TO portal_p6_runtime;
                GRANT UPDATE(last_totp_counter,updated_at) ON portal_admin_users TO portal_p6_runtime;
                GRANT SELECT,INSERT,UPDATE,DELETE ON sessions,cache,cache_locks TO portal_p6_runtime;
                GRANT SELECT,UPDATE(payload,revision,updated_at) ON performance_admin_lab_states TO portal_p6_runtime;
                GRANT SELECT,INSERT ON performance_admin_lab_events TO portal_p6_runtime;
                GRANT USAGE,SELECT ON SEQUENCE performance_admin_lab_events_id_seq TO portal_p6_runtime;
                GRANT EXECUTE ON FUNCTION performance_admin_lab_lock_member(bigint,text) TO portal_p6_runtime;
                GRANT SELECT ON portal_performance_services,portal_performance_grants TO portal_p6_authority;
                GRANT INSERT ON portal_performance_access_audit TO portal_p6_authority');
            $stage='portal-synthetic-users';
            $vault=json_decode(file_get_contents('/run/phase7/browser-vault.json'),true,16,JSON_THROW_ON_ERROR);
            foreach($vault['users'] as $index=>$user) {
                $id=Illuminate\Support\Facades\DB::table('portal_admin_users')->insertGetId(['email'=>$user['email'],'password_hash'=>Illuminate\Support\Facades\Hash::make($user['password']),
                    'totp_secret_ciphertext'=>Illuminate\Support\Facades\Crypt::encryptString($user['secret']),'is_active'=>true,'last_totp_counter'=>-1,'session_version'=>1,'created_at'=>now(),'updated_at'=>now()]);
                Illuminate\Support\Facades\DB::table('portal_performance_services')->insert(['company_id'=>$user['company'],'contract_ref'=>(string)Illuminate\Support\Str::uuid(),'contract_version'=>1,'company_active'=>true,'status'=>'active','source_kind'=>'synthetic_persisted','expires_at'=>time()+1800]);
                Illuminate\Support\Facades\DB::table('portal_performance_grants')->insert(['id'=>(string)Illuminate\Support\Str::uuid(),'admin_user_id'=>$id,'company_id'=>$user['company'],'operation'=>'dashboard.read','role'=>$index===0?'analyst':'auditor','revision'=>1,'expires_at'=>time()+1800]);
            }
        } elseif($action==='snapshot') {
            $completed=$db->query("SELECT correlation_id::text FROM portal_performance_access_audit WHERE outcome='completed' ORDER BY correlation_id")->fetchAll(PDO::FETCH_COLUMN);
            $sessions=(int)$db->query('SELECT count(*) FROM sessions')->fetchColumn();
            // Plain user/session claims must not be present in storage.
            $encrypted=true;
            foreach($db->query('SELECT payload FROM sessions')->fetchAll(PDO::FETCH_COLUMN) as $payload) {
                $decoded=base64_decode($payload,true);
                if(!is_string($decoded)||str_contains($decoded,'portal_admin_mfa_id')||str_contains($decoded,'phase7-0@example.test'))$encrypted=false;
            }
            echo json_encode(['sessions'=>$sessions,'encrypted'=>$encrypted,'completed'=>$completed],JSON_THROW_ON_ERROR).PHP_EOL;exit(0);
        } elseif($action==='revoke-grant'||$action==='restore-grant') {
            $value=$action==='revoke-grant'?(string)time():'NULL';
            $db->exec("UPDATE portal_performance_grants SET revoked_at=$value,revision=revision+1 WHERE company_id='$company'");
        } elseif($action==='extra-privilege') {
            $db->exec('GRANT INSERT ON portal_performance_services TO portal_p6_authority');
        } elseif($action==='remove-privilege') {
            $db->exec('REVOKE INSERT ON portal_performance_services FROM portal_p6_authority');
        } elseif($action==='extra-column-privilege') {
            $db->exec('GRANT SELECT(outcome) ON portal_performance_access_audit TO portal_p6_authority');
        } elseif($action==='remove-column-privilege') {
            $db->exec('REVOKE SELECT(outcome) ON portal_performance_access_audit FROM portal_p6_authority');
        } elseif($action==='revoke-session') {
            $db->exec("UPDATE portal_admin_users SET session_version=session_version+1 WHERE email='phase7-0@example.test'");
        } elseif($action==='expire-mfa') {
            $id=(int)$db->query("SELECT id FROM portal_admin_users WHERE email='phase7-0@example.test'")->fetchColumn();$changed=0;
            foreach($db->query('SELECT id FROM sessions')->fetchAll(PDO::FETCH_COLUMN) as $sessionId) {
                $session=$app->make('session')->driver();$session->setId($sessionId);$session->start();
                if($session->get('portal_admin_mfa_id')===$id){$session->put('portal_admin_mfa_at',time()-901);$session->save();$changed++;}
                $app->make('session')->forgetDrivers();
            }
            if($changed!==1)throw new RuntimeException('P7_SESSION_NOT_FOUND');
        }
    } else {
        if($action==='prepare') {
            $stage='receiver-schema';
            foreach(['002-service-inbox.sql','003-service-inbox-native-rls.sql','004-private-service-delegations.sql'] as $name)$db->exec(file_get_contents('/run/phase7-contracts/'.$name));
            $stage='receiver-roles';
            $pw=$db->quote(trim(file_get_contents('/run/private/db-password')));
            $db->exec("CREATE ROLE performance_p6_reader LOGIN PASSWORD $pw NOSUPERUSER NOBYPASSRLS NOCREATEDB NOCREATEROLE NOREPLICATION;
                GRANT USAGE ON SCHEMA performance_admin_inbox TO performance_p6_reader;
                GRANT SELECT,INSERT ON performance_admin_inbox.receipts,performance_admin_inbox.nonces TO performance_p6_reader;
                GRANT INSERT ON performance_admin_inbox.request_audit TO performance_p6_reader;
                GRANT USAGE ON SEQUENCE performance_admin_inbox.request_audit_id_seq TO performance_p6_reader;
                GRANT EXECUTE ON FUNCTION performance_admin_inbox.can_scope(text,text),performance_admin_inbox.can_nonce(text),performance_admin_inbox.resolve_delegation(text,text,text,text,text) TO performance_p6_reader");
            $fingerprint=trim(file_get_contents('/run/phase7/certificate-sha256'));
            if(!preg_match('/\A[a-f0-9]{64}\z/D',$fingerprint))throw new RuntimeException('P7_CERTIFICATE_DENIED');
            foreach([$company,'bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbbb'] as $tenant) {
                $db->exec("INSERT INTO performance_admin_inbox.principals VALUES('performance_p6_reader','portal_service','$tenant',true,now()+interval '30 minutes',NULL);
                    INSERT INTO performance_admin_inbox.service_delegations VALUES('performance_p6_reader','portal_service','phase7_key_01','$fingerprint','$tenant','dashboard.read',1,true,now()+interval '30 minutes',NULL)");
            }
        } elseif($action==='snapshot') {
            $rows=$db->query('SELECT correlation_id FROM performance_admin_inbox.receipts ORDER BY correlation_id')->fetchAll(PDO::FETCH_COLUMN);
            $audit=$db->query("SELECT correlation_id FROM performance_admin_inbox.request_audit WHERE outcome='read_result' ORDER BY correlation_id")->fetchAll(PDO::FETCH_COLUMN);
            echo json_encode(['receipts'=>count($rows),'correlations'=>$rows,'results'=>$audit],JSON_THROW_ON_ERROR).PHP_EOL;exit(0);
        } elseif($action==='revoke-grant'||$action==='restore-grant') {
            $active=$action==='restore-grant'?'true':'false';
            $db->exec("UPDATE performance_admin_inbox.service_delegations SET active=$active,revision=revision+1 WHERE company_id='$company'");
        } elseif($action==='extra-privilege') {
            $db->exec('GRANT SELECT(active) ON performance_admin_inbox.service_delegations TO performance_p6_reader');
        } elseif($action==='remove-privilege') {
            $db->exec('REVOKE SELECT(active) ON performance_admin_inbox.service_delegations FROM performance_p6_reader');
        } else throw new RuntimeException('P7_ACTION_DENIED');
    }
    echo 'PHASE7_FIXTURE_OK'.PHP_EOL;
} catch(Throwable $e) {
    // Do not emit credentials, PDO error messages, SQL, request/session bodies.
    fwrite(STDERR,'PHASE7_FIXTURE_DENIED stage='.$stage.' type='.get_class($e).PHP_EOL);exit(1);
}

