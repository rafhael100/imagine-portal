<?php
declare(strict_types=1);

require_once dirname(__DIR__,2).'/app/Domain/SeoReview/GoogleSearchConsoleTenantScopedTransaction.php';
use App\Domain\SeoReview\GoogleSearchConsoleTenantScopedTransaction as ScopedTx;

/**
 * Executes AFTER Laravel migrations in a disposable PostgreSQL CI service.
 * Proves real default-deny RLS with a non-owner, non-BYPASSRLS DB role.
 */
if(PHP_SAPI!=='cli'||getenv('APP_ENV')!=='testing'||
   getenv('PORTAL_GSC_RLS_LAB')!=='1'||
   getenv('PORTAL_GSC_LEDGER_DSN')!==
       'pgsql:host=127.0.0.1;port=5432;dbname=imagine_gsc_ci_test'){
    fwrite(STDERR,"GSC Slice V: isolated RLS lab only\n");exit(2);
}
$check=static function(bool $ok):void{if(!$ok)throw new RuntimeException();};
$tables=[
    'portal_gsc_oauth_states',
    'portal_gsc_property_grants',
    'portal_gsc_security_events',
    'portal_gsc_oauth_token_candidates'
];
$role='gsc_rls_probe';
try{
    $dsn=getenv('PORTAL_GSC_LEDGER_DSN');
    $owner=new PDO($dsn,getenv('PORTAL_GSC_LEDGER_USER'),
        getenv('PORTAL_GSC_LEDGER_PASSWORD'),
        [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,
         PDO::ATTR_EMULATE_PREPARES=>false]);
    $owner->exec("CREATE ROLE gsc_rls_probe LOGIN PASSWORD 'rls_ci_local_only'
        NOSUPERUSER NOCREATEDB NOCREATEROLE NOINHERIT NOBYPASSRLS");
    $owner->exec('GRANT CONNECT ON DATABASE imagine_gsc_ci_test
        TO gsc_rls_probe');
    $owner->exec('GRANT USAGE ON SCHEMA public TO gsc_rls_probe');

    foreach($tables as $table) {
        $owner->exec("GRANT SELECT,INSERT,UPDATE,DELETE ON {$table}
            TO gsc_rls_probe");
        // Confirm the migration enabled and FORCED RLS on all four tables.
        $stmt=$owner->prepare("SELECT relrowsecurity,relforcerowsecurity
            FROM pg_class WHERE oid=to_regclass(:table_name)");
        $stmt->execute(['table_name'=>$table]);
        $flags=$stmt->fetch(PDO::FETCH_ASSOC);
        $check(is_array($flags) &&
            in_array($flags['relrowsecurity'],[true,'t','1',1],true) &&
            in_array($flags['relforcerowsecurity'],[true,'t','1',1],true));
    }
    $now=1790000000;
    $scope='https://www.googleapis.com/auth/webmasters.readonly';

    $add=static function(string $tenant,string $ch)
        use($owner,$now,$scope):void{
        $hash=str_repeat($ch,64);
        $eid=str_repeat($ch,32);
        $owner->prepare('INSERT INTO portal_gsc_oauth_states
            (state_hash,tenant_ref,actor_ref,sealed_verifier,issued_at,expires_at)
            VALUES (:hash,:tenant,:actor,:sealed,:issued,:expires)')
            ->execute([
                'hash'=>$hash,'tenant'=>$tenant,'actor'=>'operator-123',
                'sealed'=>'v1.synthetic-review-only','issued'=>$now,
                'expires'=>$now+600
            ]);
        $owner->prepare('INSERT INTO portal_gsc_property_grants
            (tenant_ref,actor_ref,property_fingerprint,scope,grant_status,
             verified_at,expires_at)
            VALUES (:tenant,:actor,:property,:scope,:status,:verified,:expires)')
            ->execute([
                'tenant'=>$tenant,'actor'=>'operator-123',
                'property'=>$hash,'scope'=>$scope,'status'=>'active',
                'verified'=>$now,'expires'=>$now+600
            ]);
        $owner->prepare('INSERT INTO portal_gsc_security_events
            (event_id,event_code,tenant_ref,actor_ref,property_fingerprint,event_at)
            VALUES (:event,:code,:tenant,:actor,:property,:at)')
            ->execute([
                'event'=>$eid,'code'=>'oauth_pkce_prepared',
                'tenant'=>$tenant,'actor'=>'operator-123',
                'property'=>$hash,'at'=>$now
            ]);
        $owner->prepare('INSERT INTO portal_gsc_oauth_token_candidates
            (tenant_ref,actor_ref,property_fingerprint,scope,sealed_tokens,
             issued_at,expires_at)
            VALUES (:tenant,:actor,:property,:scope,:sealed,:issued,:expires)')
            ->execute([
                'tenant'=>$tenant,'actor'=>'operator-123',
                'property'=>$hash,'scope'=>$scope,
                'sealed'=>'v1.synthetic-review-only','issued'=>$now,
                'expires'=>$now+300
            ]);
    };
    $add('tenant-one','a');
    $add('tenant-two','b');

    $probe=new PDO($dsn,$role,'rls_ci_local_only',[
        PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_EMULATE_PREPARES=>false
    ]);
    $count=static function(PDO $connection,string $table):int {
        return (int)$connection->query("SELECT COUNT(*) FROM {$table}")
            ->fetchColumn();
    };
    foreach($tables as $table)$check($count($probe,$table)===0);

    foreach(['tenant-one','tenant-two'] as $tenant) {
        $probe->beginTransaction();
        $probe->exec("SET LOCAL app.tenant_ref = '{$tenant}'");
        foreach($tables as $table) {
            $check($count($probe,$table)===1);
            $rows=$probe->query("SELECT tenant_ref FROM {$table}")
                ->fetchAll(PDO::FETCH_COLUMN);
            $check($rows===[$tenant]);
        }
        $check($probe->exec("DELETE FROM portal_gsc_oauth_states
            WHERE tenant_ref='different-tenant'")===0);
        $probe->commit();
        foreach($tables as $table)$check($count($probe,$table)===0);
    }

    // Runtime wrapper applies tenant scope only inside a verified transaction.
    $scoped=new ScopedTx($probe);
    $check(!ScopedTx::authorizesPortalMembership() &&
        !ScopedTx::authorizesGoogleHttp() &&
        !ScopedTx::authorizesCustomerDisplay());
    foreach(['tenant-one','tenant-two'] as $tenant) {
        $seen=$scoped->run([
            'tenantRef'=>$tenant,'actorRef'=>'operator-123'
        ],static function(PDO $db) use ($tables):array {
            $counts=[];
            foreach($tables as $table) {
                $counts[$table]=(int)$db->query(
                    "SELECT COUNT(*) FROM {$table}"
                )->fetchColumn();
            }
            return $counts;
        });
        $check(is_array($seen)&&count($seen)===4 &&
            !in_array(0,$seen,true));
        foreach($tables as $table)$check($count($probe,$table)===0);
    }
    foreach([
        ['tenantRef'=>'tenant-one','actorRef'=>'bad actor'],
        ['tenantRef'=>'tenant-one;SET local app.tenant_ref=tenant-two',
         'actorRef'=>'operator-123'],
        ['tenantRef'=>'tenant-one','actorRef'=>'operator-123',
         'untrusted'=>true],
        ['tenantRef'=>'tenant-two'],
    ] as $invalid){
        $check($scoped->run($invalid,static fn(PDO $db)=>true)===null);
    }
    $check($scoped->run(
        ['tenantRef'=>'tenant-one','actorRef'=>'operator-123'],
        static function(PDO $db):bool {
            throw new RuntimeException('synthetic failure');
        }
    )===null);
    $check(!$probe->inTransaction());
    $probe->beginTransaction();
    $check($scoped->run(
        ['tenantRef'=>'tenant-one','actorRef'=>'operator-123'],
        static fn(PDO $db)=>true
    )===null); // no nesting or leaked tenant scope
    $probe->rollBack();
    $check($scoped->run(
        ['tenantRef'=>'tenant-one','actorRef'=>'operator-123'],
        static function(PDO $db):bool {
            $db->exec("UPDATE portal_gsc_security_events
                SET tenant_ref='tenant-two' WHERE tenant_ref='tenant-one'");
            return true;
        }
    )===null);
    foreach($tables as $table)$check($count($probe,$table)===0);
    // Tests must reject an administrative DB identity.
    $check((new ScopedTx($owner))->run(
        ['tenantRef'=>'tenant-one','actorRef'=>'operator-123'],
        static fn(PDO $db)=>true
    )===null);

    // A malicious UPDATE cannot move a row into a different tenant.
    $probe->beginTransaction();
    $probe->exec("SET LOCAL app.tenant_ref = 'tenant-one'");
    try {
        $probe->exec("UPDATE portal_gsc_security_events
            SET tenant_ref='tenant-two' WHERE tenant_ref='tenant-one'");
        throw new RuntimeException();
    }catch(PDOException) {
        $probe->rollBack();
    }

    // A role with tenant-one context cannot INSERT tenant-two records.
    $probe->beginTransaction();
    $probe->exec("SET LOCAL app.tenant_ref = 'tenant-one'");
    try {
        $probe->exec("INSERT INTO portal_gsc_security_events
            (event_id,event_code,tenant_ref,actor_ref,property_fingerprint,event_at)
            VALUES ('cccccccccccccccccccccccccccccccc','oauth_state_consumed',
            'tenant-two','operator-123',NULL,1790000000)");
        throw new RuntimeException();
    }catch(PDOException) {
        $probe->rollBack();
    }
    foreach($tables as $table)$check($count($probe,$table)===0);
}catch(Throwable){
    fwrite(STDERR,"GSC Slice V: tenant RLS enforcement refused\n");
    exit(1);
}finally{
    if(isset($probe)&&$probe instanceof PDO)$probe=null;
    if(isset($owner)&&$owner instanceof PDO){
        try{
            $owner->exec('DROP OWNED BY gsc_rls_probe');
            $owner->exec('DROP ROLE IF EXISTS gsc_rls_probe');
        }catch(Throwable){}
    }
}
echo "GSC Slice V: default-deny tenant RLS verified with non-owner role\n";
