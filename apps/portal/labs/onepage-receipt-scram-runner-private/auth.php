<?php
declare(strict_types=1);

namespace Imagine\Lab\OnepageReceiptScram;

use Closure;
use DateTimeImmutable;
use Illuminate\Database\Connection;
use PDO;
use RuntimeException;
use Imagine\Lab\OnepageBoundReceipt\FixtureLedger;
use function Imagine\Lab\OnepageBoundReceiptDurable\checkpoint;

require_once __DIR__.'/../onepage-bound-receipt-durable-private/checkpoint.php';

function secret(string $root,string $role):string
{
    if (!in_array($role,['onepage_lab_bootstrap','portal_browser_runtime','onepage_buyer_runtime',
          'onepage_fixture_receipt_observer','onepage_fixture_receipt_writer'],true)
        || !preg_match('~\A/tmp/onepage-scram-[a-z0-9_]{8}\z~D',$root)
        || realpath($root)!==$root || !function_exists('posix_geteuid') || fileowner($root)!==posix_geteuid()
        || (fileperms($root)&0777)!==0700) throw new RuntimeException('SCRAM_FIXTURE_ROOT_REQUIRED');
    $path=$root.'/auth-'.$role;
    if (realpath($path)!==$path||!is_file($path)||fileowner($path)!==posix_geteuid()
        ||(fileperms($path)&0777)!==0600||filesize($path)!==65) throw new RuntimeException('SCRAM_FILE_REQUIRED');
    $raw=file_get_contents($path);
    if (!is_string($raw)||!preg_match('/\A[a-f0-9]{64}\n\z/D',$raw)) throw new RuntimeException('SCRAM_SHAPE_REQUIRED');
    return substr($raw,0,-1);
}

function arm(PDO $bootstrap,string $root,string $role):void
{
    $password=$bootstrap->quote(secret($root,$role));
    if (!in_array($role,['portal_browser_runtime','onepage_buyer_runtime',
          'onepage_fixture_receipt_observer','onepage_fixture_receipt_writer'],true)
        ||!is_string($password)) throw new RuntimeException('SCRAM_ROLE_REQUIRED');
    $bootstrap->exec('ALTER ROLE '.$role.' LOGIN PASSWORD '.$password);
}

function verifyHba(PDO $p): void
{
    // Compare every loaded file row, not just method names. The pre-start
    // runner pins the bytes. First connections and explicit negatives prove use.
    $rows=$p->query('SELECT type,database[1] db,user_name[1] role,address,netmask,auth_method,
          error IS NULL AND cardinality(database)=1 AND cardinality(user_name)=1
          AND coalesce(cardinality(options),0)=0 AS valid
          FROM pg_hba_file_rules ORDER BY rule_number')->fetchAll(PDO::FETCH_NUM);
    $expected=[['local','all','all',null,null,'reject'],['local','replication','all',null,null,'reject']];
    foreach ([['postgres','onepage_lab_bootstrap'],['portal_onepage_buyer_ci','onepage_lab_bootstrap'],
              ['portal_onepage_buyer_ci','portal_browser_runtime'],['portal_onepage_buyer_ci','onepage_buyer_runtime'],
              ['portal_onepage_buyer_ci','onepage_fixture_receipt_observer'],
              ['portal_onepage_buyer_ci','onepage_fixture_receipt_writer']] as [$db,$role])
        $expected[]=['host',$db,$role,'127.0.0.1','255.255.255.255','scram-sha-256'];
    $expected[]=['host','replication','all','all',null,'reject'];
    $expected[]=['host','all','all','all',null,'reject'];
    if (count($rows)!==count($expected)) throw new RuntimeException('EXACT_HBA_REQUIRED');
    foreach ($rows as $i=>$row) {
        if (array_slice($row,0,6)!==$expected[$i]||!in_array($row[6],[true,'t',1,'1'],true))
            throw new RuntimeException('EXACT_HBA_REQUIRED');
    }
    if ($p->query('SHOW password_encryption')->fetchColumn()!=='scram-sha-256')
        throw new RuntimeException('SCRAM_ENCRYPTION_REQUIRED');
}

/** Match the exact bigint epoch representation of the original Guard.
 * PDO may return the same bigint as a PHP int or canonical decimal string. */
function postmasterEpoch(mixed $input):?string
{
    if (is_int($input)) return $input>0?(string)$input:null;
    if (!is_string($input)||!preg_match('/\A[1-9][0-9]{0,18}\z/D',$input)
        || (strlen($input)===19 && strcmp($input,'9223372036854775807')>0)) return null;
    return $input;
}

function receipt(Connection $db,PDO $bootstrap,array $baseIdentity,Closure $pin,string $root,string $sql,
  string $order,?array $bound,DateTimeImmutable $at,FixtureLedger $received,FixtureLedger $pending,FixtureLedger $reversed):void
{
    $pin();verifyHba($bootstrap);
    $db->unprepared(file_get_contents($sql));
    $observerRole='onepage_fixture_receipt_observer';$writerRole='onepage_fixture_receipt_writer';
    foreach ([$observerRole,$writerRole] as $role) arm($bootstrap,$root,$role);
    $dsn='pgsql:host=127.0.0.1;port=55439;dbname=portal_onepage_buyer_ci;connect_timeout=2;sslmode=disable';
    $connection=static fn(string $role,string $pw,?string $target=null)=>new PDO($target??$dsn,$role,$pw,
        [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_EMULATE_PREPARES=>false]);
    $otherDb=str_replace('dbname=portal_onepage_buyer_ci','dbname=postgres',$dsn);
    $socket=str_replace('host=127.0.0.1','host='.$root.'/pg/run/onepage-pg-lab/socket',$dsn);
    foreach ([[$observerRole,secret($root,$writerRole),null],[$writerRole,secret($root,$observerRole),null],
              ['onepage_lab_bootstrap',secret($root,$writerRole),null],
              [$observerRole,secret($root,$observerRole),$otherDb],
              [$observerRole,secret($root,$observerRole),$socket]] as [$role,$pw,$target]) {
        $denied=false;
        try {$connection($role,$pw,$target);} catch (\PDOException $e) {
            $denied=in_array((string)$e->getCode(),['28P01','08006','7'],true);
        }
        if (!$denied) throw new RuntimeException('SCRAM_NEGATIVE_REQUIRED');
    }
    $observer=$connection($observerRole,secret($root,$observerRole));
    $writer=$connection($writerRole,secret($root,$writerRole));
    $other=$connection($writerRole,secret($root,$writerRole));
    $pid=static fn(PDO $p)=>(int)$p->query('SELECT pg_backend_pid()')->fetchColumn();
    if (count(array_unique([$pid($bootstrap),$pid($observer),$pid($writer),$pid($other)]))!==4)
        throw new RuntimeException('INDEPENDENT_BACKENDS_REQUIRED');
    $assert=static function(PDO $p,string $role)use($pin,$bootstrap,$baseIdentity):bool{
        $pin();
        $nonce='receipt-fixture-'.bin2hex(random_bytes(16));
        $q=$p->prepare("SELECT set_config('application_name',?,false)");$q->execute([$nonce]);
        $a=$p->query("SELECT pg_backend_pid() pid,current_database() db,current_user actor,session_user login,
          host(inet_server_addr()) host,inet_server_port() port,extract(epoch FROM pg_postmaster_start_time())::bigint started,
          rolsuper OR rolbypassrls OR rolcreatedb OR rolcreaterole OR rolinherit OR rolreplication
          OR EXISTS(SELECT 1 FROM pg_auth_members WHERE member=r.oid) unsafe FROM pg_roles r WHERE rolname=current_user")->fetch(PDO::FETCH_ASSOC);
        $b=$bootstrap->prepare("SELECT datname,usename,application_name,host(client_addr) client
          FROM pg_stat_activity WHERE pid=?");
        if (!is_array($a)) return false;
        $b->execute([$a['pid']]);$obs=$b->fetch(PDO::FETCH_ASSOC);
        if (!is_array($obs)) return false;
        $epoch=postmasterEpoch($a['started']??null);
        return !$p->inTransaction()&&$a['db']==='portal_onepage_buyer_ci'&&$a['actor']===$role&&$a['login']===$role
          &&$a['host']==='127.0.0.1'&&(int)$a['port']===55439&&$epoch!==null&&$epoch===postmasterEpoch($baseIdentity['started']??null)
          &&in_array($a['unsafe'],[false,'f',0,'0'],true)&&$obs['datname']===$a['db']&&$obs['usename']===$role
          &&$obs['application_name']===$nonce&&$obs['client']==='127.0.0.1';
    };
    $acl=$bootstrap->query("SELECT p.proname,p.prosecdef,
      pg_get_userbyid(p.proowner)='onepage_buyer_owner' AS owned,
      array_agg(pg_get_userbyid(a.grantee) ORDER BY pg_get_userbyid(a.grantee))
        FILTER (WHERE a.grantee<>p.proowner AND a.privilege_type='EXECUTE') AS granted,
      bool_or(a.is_grantable AND a.grantee<>p.proowner OR a.grantee=0) AS unsafe
      FROM pg_proc p CROSS JOIN LATERAL aclexplode(COALESCE(p.proacl,acldefault('f',p.proowner))) a
      WHERE p.oid IN ('onepage_buyer_lab.fixture_observe_receipt_input(uuid,text)'::regprocedure,
       'onepage_buyer_lab.fixture_append_bound_receipt_input(uuid,text,bigint,text)'::regprocedure)
      GROUP BY p.oid")->fetchAll(PDO::FETCH_ASSOC);
    $wanted=['fixture_observe_receipt_input'=>$observerRole,'fixture_append_bound_receipt_input'=>$writerRole];
    if (count($acl)!==2) throw new RuntimeException('EXACT_RECEIPT_ACL_REQUIRED');
    foreach ($acl as $record) {
        if (!isset($wanted[$record['proname']])||$record['granted']!=='{'.$wanted[$record['proname']].'}'
          ||!in_array($record['owned'],[true,'t',1,'1'],true)||!in_array($record['prosecdef'],[true,'t',1,'1'],true)
          ||!in_array($record['unsafe'],[false,'f',0,'0'],true)) throw new RuntimeException('EXACT_RECEIPT_ACL_REQUIRED');
    }
    foreach ([$observerRole,$writerRole] as $role) {
        $q=$bootstrap->prepare("SELECT count(*) FROM pg_class c
           WHERE c.oid IN ('onepage_buyer_lab.fixture_read_receipt_source'::regclass,
                           'onepage_buyer_lab.fixture_append_receipt_input'::regclass)
             AND c.relrowsecurity AND c.relforcerowsecurity
             AND NOT has_table_privilege(?,c.oid,'SELECT,INSERT,UPDATE,DELETE,TRUNCATE,REFERENCES,TRIGGER')");
        $q->execute([$role]);
        if ((int)$q->fetchColumn()!==2) throw new RuntimeException('NO_DIRECT_TABLE_ACL_REQUIRED');
    }
    foreach ([[$observer,'SELECT count(*) FROM onepage_buyer_lab.fixture_read_receipt_source'],
              [$observer,'SELECT onepage_buyer_lab.fixture_append_bound_receipt_input(NULL,NULL,NULL,NULL)'],
              [$writer,'SELECT onepage_buyer_lab.fixture_observe_receipt_input(NULL,NULL)'],
              [$writer,'INSERT INTO onepage_buyer_lab.fixture_append_receipt_input DEFAULT VALUES'],
              [$writer,'UPDATE onepage_buyer_lab.fixture_append_receipt_input SET fixture_only=true WHERE false'],
              [$writer,'DELETE FROM onepage_buyer_lab.fixture_append_receipt_input WHERE false'],
              [$writer,'SELECT count(*) FROM onepage_buyer_lab.fixture_append_receipt_input']] as [$pdo,$negative]) {
        $denied=false;
        try {$pdo->query($negative);} catch (\PDOException $e) {$denied=$e->getCode()==='42501';}
        if (!$denied) throw new RuntimeException('RECEIPT_ACL_REQUIRED');
    }
    $stage=static function(string $id,string $line,int $v,array $e)use($bootstrap,$pin):bool{
        $pin();
        $q=$bootstrap->prepare("INSERT INTO onepage_buyer_lab.fixture_read_receipt_source
          (order_id,line,source_version,evidence,observed_at) VALUES(CAST(? AS uuid),?,?,CAST(? AS jsonb),
            clock_timestamp()-CASE WHEN ?=0 THEN interval '2 minutes' ELSE interval '0 seconds' END)
          ON CONFLICT(order_id,line) DO UPDATE SET source_version=EXCLUDED.source_version,
            evidence=EXCLUDED.evidence,observed_at=EXCLUDED.observed_at");
        return $q->execute([$id,$line,$v,json_encode($e,JSON_THROW_ON_ERROR),$v===6?0:1])&&$q->rowCount()===1;
    };
    $state=static function()use($db):array{
        $r=$db->selectOne('SELECT count(*) n,min(xmin::text) tx,min(evidence_digest) d FROM onepage_buyer_lab.fixture_append_receipt_input');
        return ['count'=>(int)$r->n,'xmin'=>$r->tx,'digest'=>$r->d];
    };
    checkpoint($observer,$writer,$other,$assert,$stage,$state,$order,$bound,'pay_LABalpha',$at,$received,$pending,$reversed);
}
