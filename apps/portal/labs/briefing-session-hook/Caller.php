<?php
declare(strict_types=1);
namespace PrivateBriefingHook;
use PDO;
use RuntimeException;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Http\Request;
use App\Infrastructure\Commerce\PostgresOnepageOrders;
use PrivateSessionPipe\Channel;
/** Test-only caller boundary. Must run inside the already qualified #947 lifecycle, after COMMIT. */
final class Caller
{
    private static ?\WeakMap $used=null;
    public static function identityMatches(array $row,array $cluster):bool
    {
        foreach(['current_database','addr','port','started','major'] as $key)
            if(!isset($row[$key],$cluster[$key])||(string)$row[$key]!== (string)$cluster[$key])return false;
        return $row['current_database']==='portal_native_orders_ci'&&$row['addr']==='127.0.0.1'
            &&(int)$row['major']===18&&($row['actor']??null)==='portal_onepage_runtime'
            &&($row['login']??null)==='portal_onepage_runtime'&&($row['isolation']??null)==='read committed'
            &&in_array($row['unsafe']??null,[false,'f',0,'0'],true);
    }
    /** $cluster is the identity captured by CiGate before migrations, not request/producer input.
     * $fresh is opened by the caller AFTER commit; this method never opens or replaces a connection.
     * Request is passed untouched to the real kernel; no session is seeded here.
     */
    public static function observe(PDO $fresh,PDO $prior,array $cluster,Kernel $kernel,Request $request,
        Channel $channel,string $anchor):array
    {
        self::$used??=new \WeakMap();
        if($fresh===$prior||isset(self::$used[$fresh])||$fresh->inTransaction()
            ||$fresh->getAttribute(PDO::ATTR_DRIVER_NAME)!=='pgsql')throw new RuntimeException('HOOK_CALLER_REFUSED');
        self::$used[$fresh]=true;
        try {$row=$fresh->query("SELECT current_database(),host(inet_server_addr()) addr,inet_server_port() port,
            extract(epoch FROM pg_postmaster_start_time()) started,current_setting('server_version_num')::int/10000 major,
            current_user actor,session_user login,current_setting('transaction_isolation') isolation,
            rolsuper OR rolcreatedb OR rolcreaterole OR rolreplication OR rolbypassrls OR rolinherit
            OR EXISTS(SELECT 1 FROM pg_auth_members WHERE member=r.oid) unsafe FROM pg_roles r WHERE rolname=current_user")->fetch(PDO::FETCH_ASSOC);}
        catch(\Throwable){throw new RuntimeException('HOOK_CALLER_REFUSED');}
        if(!is_array($row)||!self::identityMatches($row,$cluster))throw new RuntimeException('HOOK_CALLER_REFUSED');
        $hook=new Hook($fresh,new PostgresOnepageOrders($fresh,'portal_native_orders_ci'),$anchor);
        try{$result=$hook->run($kernel,$request,$channel);}
        catch(\Throwable){throw new RuntimeException($hook->projectionWasConsulted()?'HOOK_PROJECTION_FAILED':'HOOK_BEFORE_PROJECTION_REFUSED');}
        return $result+['projection_consulted'=>$hook->projectionWasConsulted()];
    }
}
