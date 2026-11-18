<?php
declare(strict_types=1);
namespace App\Infrastructure\Commerce;
use App\Domain\Commerce\D0\EntryStore;
use App\Domain\SitesIntegration\PortalSettlementEvidence;
use PDO;
use RuntimeException;
/** Explicitly supplied restricted connections; no credentials, role switching or network factory. */
final readonly class PostgresD0EntryStore implements EntryStore
{
 public function __construct(private PDO $reader,private PDO $writer,private string $database,private string $account){}
 private function ready(PDO $db,string $role):void {
  if($db->getAttribute(PDO::ATTR_DRIVER_NAME)!=='pgsql'||$db->inTransaction())throw new RuntimeException('D0_UNAVAILABLE');
  $r=$db->query("SELECT current_database() db,current_user actor,session_user login,current_setting('transaction_isolation') isolation,
   rolsuper OR rolbypassrls OR rolcreatedb OR rolcreaterole OR rolinherit OR rolreplication OR EXISTS(SELECT FROM pg_auth_members WHERE member=r.oid) unsafe
   FROM pg_roles r WHERE rolname=current_user")->fetch(PDO::FETCH_ASSOC);
  if(!$r||$r['db']!==$this->database||$r['actor']!==$role||$r['login']!==$role||$r['isolation']!=='read committed'||!in_array($r['unsafe'],[false,'f',0,'0'],true))throw new RuntimeException('D0_UNAVAILABLE');
 }
 public function inspect(string $session,string $order):?array {
  if(!preg_match('/\A[a-f0-9]{64}\z/D',$session))return null;
  $this->ready($this->reader,'portal_onepage_runtime');
  $q=$this->reader->prepare('SELECT portal_onepage.d0_inspect(?,?::uuid,?)');$q->execute([hash('sha256',$session),$order,$this->account]);$v=$q->fetchColumn();
  return is_string($v)?json_decode($v,true,32,JSON_THROW_ON_ERROR):null;
 }
 public function admit(string $session,string $order,array $expected,PortalSettlementEvidence $proof,string $observedAt):string {
  if(!preg_match('/\A[a-f0-9]{64}\z/D',$session)||$proof->orderRef!==$order||$proof->productCode!=='onepage')return 'denied';
  $this->ready($this->reader,'portal_onepage_runtime');$this->ready($this->writer,'portal_onepage_d0_verifier');
  if($this->reader===$this->writer)throw new RuntimeException('D0_UNAVAILABLE');
  $q=$this->writer->prepare('SELECT portal_onepage.d0_admit(?,?::uuid,?,?::jsonb,?,?,?::timestamptz)');
  $q->execute([hash('sha256',$session),$order,$this->account,json_encode($expected,JSON_THROW_ON_ERROR),$proof->settlementRef,$proof->settlementSha256,$observedAt]);
  $v=$q->fetchColumn();return in_array($v,['recorded','replay','denied'],true)?$v:'unavailable';
 }
 public function __debugInfo():array{return ['backend'=>'Restricted D0 candidate'];}
 public function __serialize():array{throw new RuntimeException('D0_UNAVAILABLE');}
}
