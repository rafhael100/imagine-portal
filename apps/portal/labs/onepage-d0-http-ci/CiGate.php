<?php
declare(strict_types=1);
namespace NativeBriefingFixture;
use PDO;
use RuntimeException;
final class CiGate
{
    public static function topology():void
    {
        $need=static function(bool $v):void {if(!$v)throw new RuntimeException('CI_TOPOLOGY_REFUSED');};
        $need(getenv('GITHUB_ACTIONS')==='true' && getenv('BRIEFING_CI_SCOPE')==='REVIEWED_SINGLE_OPENED_PR');
        $need(function_exists('posix_geteuid') && posix_geteuid()>0);
        $f=[];foreach(explode("\n",file_get_contents('/proc/self/status')) as $l){$v=explode(':',$l,2);if(count($v)===2)$f[$v[0]]=trim($v[1]);}
        foreach(['Uid'=>posix_geteuid(),'Gid'=>posix_getegid()] as $k=>$id)$need(preg_split('/\s+/',$f[$k])===array_fill(0,4,(string)$id));
        foreach(['CapInh','CapPrm','CapEff','CapBnd','CapAmb'] as $k)$need($f[$k]==='0000000000000000');
        $need($f['Groups']==='' && $f['NoNewPrivs']==='1');
        foreach(['net','mnt','pid'] as $k)$need(readlink('/proc/self/ns/'.$k)===getenv('BRIEFING_NS_'.strtoupper($k)));
        $need(array_values(array_diff(scandir('/sys/class/net'),['.','..']))===['lo']);
        foreach(['route','ipv6_route'] as $file){$ls=file('/proc/net/'.$file,FILE_IGNORE_NEW_LINES|FILE_SKIP_EMPTY_LINES);if($file==='route')array_shift($ls);foreach($ls as $l){$v=preg_split('/\s+/',trim($l));$need(($file==='route'?$v[0]:end($v))==='lo');}}
    }
    public static function configValid(array $cfg,string $database,string $port):bool
    {
        return in_array($database,['postgres','portal_native_orders_ci'],true)
            && ($cfg['driver']??null)==='pgsql' && ($cfg['host']??null)==='127.0.0.1'
            && ($cfg['database']??null)===$database && ($cfg['username']??null)==='portal_native_test_bootstrap'
            && ($cfg['password']??null)==='' && empty($cfg['url'])
            && (string)($cfg['port']??'')===$port && preg_match('/\A[0-9]{4,5}\z/D',$port)===1;
    }
    public static function identityValid(array $r,string $database,string $port,string $data,float $started):bool
    {
        return $r['session_user']==='portal_native_test_bootstrap' && $r['current_user']===$r['session_user']
            && $r['current_database']===$database && $r['addr']==='127.0.0.1' && (string)$r['port']===$port
            && (int)$r['major']===18 && $r['data']===$data && $r['listen']==='127.0.0.1' && $r['sockets']===''
            && (float)$r['age']>=0 && (float)$r['age']<300 && (float)$r['created_age']>=0 && (float)$r['created_age']<300
            && (float)$r['started']>=$started-1 && (float)$r['started']<=$started+30
            && preg_match('~\A/tmp/native-briefing-[a-zA-Z0-9_]+/data\z~D',$data)===1;
    }
    public static function beforeWrites(string $database):array
    {
        self::topology();$deny=static function(bool $ok):void {if(!$ok)throw new RuntimeException('CI_DESTINATION_REFUSED');};
        $deny(getenv('PORTAL_NATIVE_ORDER_TEST')==='DISPOSABLE_PG18');
        $cfg=config('database.connections.pgsql');$port=(string)getenv('PORTAL_NATIVE_ORDER_TEST_PORT');
        $deny(config('database.default')==='pgsql' && is_array($cfg) && self::configValid($cfg,$database,$port));
        $db=app('db')->connection('pgsql');
        foreach(['driver','host','port','database','username','password','url'] as $key)$deny(($db->getConfig($key)??null)===($cfg[$key]??null));
        $pdo=$db->getPdo();
        $identity=self::attestSql($pdo,$database);
        self::sameConnection($db->getPdo(),$pdo);
        return ['pdo'=>$pdo,'database'=>$database,'identity'=>$identity];
    }
    public static function sameConnection(PDO $actual,PDO $attested):void
    {
        if($actual!==$attested)throw new RuntimeException('CI_CONNECTION_CHANGED');
    }
    public static function adminBeforeWrites(PDO $admin,array $attestation):PDO
    {
        $identity=self::attestSql($admin,$attestation['database']);
        if($identity!==$attestation['identity'])throw new RuntimeException('CI_ADMIN_IDENTITY_REFUSED');
        return $admin; // Caller stores this exact object in a readonly PDO property.
    }
    public static function attestSql(PDO $pdo,string $database):array
    {
        $deny=static function(bool $ok):void {if(!$ok)throw new RuntimeException('CI_DESTINATION_REFUSED');};
        $deny(in_array($database,['postgres','portal_native_orders_ci'],true));
        $port=(string)getenv('PORTAL_NATIVE_ORDER_TEST_PORT');
        $deny(!$pdo->inTransaction());
        $r=$pdo->query("SELECT system_identifier::text cluster_id,session_user,current_user,current_database(),host(inet_server_addr()) addr,inet_server_port() port,
            current_setting('server_version_num')::int/10000 major,current_setting('data_directory') data,
            current_setting('listen_addresses') listen,current_setting('unix_socket_directories') sockets,
            extract(epoch FROM clock_timestamp()-pg_postmaster_start_time()) age,extract(epoch FROM pg_postmaster_start_time()) started,
            extract(epoch FROM clock_timestamp())-(system_identifier >> 32) created_age FROM pg_control_system()")->fetch(PDO::FETCH_ASSOC);
        $deny(self::identityValid($r,$database,$port,(string)getenv('PORTAL_NATIVE_ORDER_TEST_DATA'),(float)getenv('BRIEFING_START_EPOCH')));
        $deny((int)$pdo->query("SELECT count(*) FROM pg_roles WHERE rolname !~ '^pg_' AND rolname<>'portal_native_test_bootstrap'")->fetchColumn()===0);
        $deny((int)$pdo->query("SELECT count(*) FROM pg_tables WHERE schemaname NOT IN ('pg_catalog','information_schema')")->fetchColumn()===0);
        $allowed=$database==='postgres'?"'postgres','template0','template1'":"'postgres','template0','template1','portal_native_orders_ci'";
        $deny((int)$pdo->query("SELECT count(*) FROM pg_database WHERE datname NOT IN ($allowed)")->fetchColumn()===0);
        $deny(is_string($r['cluster_id']) && preg_match('/\A[0-9]+\z/D',$r['cluster_id'])===1);
        unset($r['age'],$r['created_age']);
        return $r;
    }
}
