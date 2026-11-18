<?php
declare(strict_types=1);
namespace NativeHttpBriefing;
use PDO;use PDOStatement;use RuntimeException;
/** Real PDO, not a double. Record the backend that actually prepares d0_inspect. No public IDs. */
final class TrackedReader extends PDO
{
 public readonly int $backend;public int $inspects=0;
 public function __construct(int $port,array $cluster){
  parent::__construct('pgsql:host=127.0.0.1;port='.$port.';dbname=portal_native_orders_ci;connect_timeout=3','portal_onepage_runtime','',[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_EMULATE_PREPARES=>false]);
  $r=parent::query("SELECT current_database() db,session_user,current_user,host(inet_server_addr()) addr,inet_server_port() port,extract(epoch FROM pg_postmaster_start_time()) started,pg_backend_pid() backend")->fetch(PDO::FETCH_ASSOC);
  if($r['db']!==$cluster['current_database']||$r['session_user']!=='portal_onepage_runtime'||$r['current_user']!==$r['session_user']||$r['addr']!=='127.0.0.1'||(int)$r['port']!==$port||$r['started']!==$cluster['started'])throw new RuntimeException('D0_READER_IDENTITY_REFUSED');
  $this->backend=(int)$r['backend'];
 }
 public function prepare(string $query,array $options=[]):PDOStatement|false {
  if($query==='SELECT portal_onepage.d0_inspect(?,?::uuid,?)'){
   if((int)parent::query('SELECT pg_backend_pid()')->fetchColumn()!==$this->backend)throw new RuntimeException('D0_READER_CHANGED');
   $this->inspects++;
  }
  return parent::prepare($query,$options);
 }
}
