<?php
declare(strict_types=1);
// Incremental hook, not a startup/fixture runner. Requires fresh, active #923 fixtures.
require_once __DIR__.'/../onepage-buyer/Guard.php';
require_once __DIR__.'/AtomicReader.php';
use App\Labs\OnepageBuyer\Guard;
use App\Labs\OnepageAtomicRead\AtomicReader;
use Illuminate\Database\Connection;
use Illuminate\Http\Request;
/** All requests are server-side native-issued fixtures, never supplied by HTTP.
 * $expired is a deliberately expired negative fixture of the same buyer/company. */
function checkAtomicRead(Connection $db,PDO $runtime,string $cluster,string $control,
 Request $own,Request $sameTenantOther,Request $otherTenant,Request $expired,string $order):int {
 if(getenv('ONEPAGE_PENDING_ORDER_LAB')!=='SYNTHETIC_ONLY')throw new RuntimeException('LAB_REQUIRED');
 Guard::offline();
 $barrier=function()use($db,$cluster,$control):void {
  Guard::configuration(app()->environment(),config('database.default'),config('database.connections.pgsql'));
  Guard::configuration(app()->environment(),config('database.default'),$db->getConfig());
  Guard::identity($db->getPdo(),$cluster,$control);
 };
 $barrier();$bootstrap=$db->getPdo();$identity=Guard::identity($bootstrap,$cluster,$control);
 $digest=serialize($db->getConfig());
 $pinned=function()use($barrier,$db,$bootstrap,$identity,$digest,$cluster,$control):void {
  $barrier();if($db->getPdo()!==$bootstrap||serialize($db->getConfig())!==$digest
   ||Guard::identity($bootstrap,$cluster,$control)!==$identity)throw new RuntimeException('IDENTITY_DRIFT');
 };
 $db->setReconnector(static function(){throw new RuntimeException('NO_RECONNECT');});
 $db->beforeExecuting(static function()use($pinned){$pinned();});
 Guard::runtime($runtime,$bootstrap,$identity,'onepage_buyer_runtime');
 $db->unprepared(file_get_contents(__DIR__.'/read.sql')); // Guard precedes the sole new capability.
 $reader=AtomicReader::guarded($runtime,$bootstrap,$identity,$pinned);
 $checks=0;$ok=function(bool $b,string $name)use(&$checks){if(!$b)throw new RuntimeException('FAILED '.$name);$checks++;echo "PASS ATOMIC_SQL_CASE: ".$name.PHP_EOL;};
 $snapshot=fn()=>json_encode($db->select('SELECT to_jsonb(t)::text row FROM onepage_buyer_lab.pending_orders t ORDER BY id'),JSON_THROW_ON_ERROR);
 $before=$snapshot();$first=$reader->read($own,$order);
 $ok($first['status']==='pending_payment'&&!$first['settled']&&!$first['preview_admitted']&&$first['binding']===null,'own pending only');
 $row=$first['snapshot'];
 $sqlRead=function(Request $request,string $id)use($runtime,$bootstrap,$identity,$pinned):mixed {
  $pinned();Guard::runtime($runtime,$bootstrap,$identity,'onepage_buyer_runtime');
  $q=$runtime->prepare('SELECT onepage_buyer_lab.read_pending_order(?,?::uuid)');
  if(!$q->execute([hash('sha256',$request->session()->get('portal.browser_auth')['reference']),$id]))throw new RuntimeException('SQL_READ_FAILED');
  $value=$q->fetchColumn();$pinned();return $value===null?null:json_decode($value,true,512,JSON_THROW_ON_ERROR);
 };
 $expected=json_decode($db->selectOne("SELECT ((to_jsonb(t)-'request_hash') || jsonb_build_object('accepted_at',to_char(accepted_at AT TIME ZONE 'UTC','YYYY-MM-DD\"T\"HH24:MI:SS.US\"Z\"')))::text snapshot FROM onepage_buyer_lab.pending_orders t WHERE id=?",[$order])->snapshot,true,512,JSON_THROW_ON_ERROR);
 $canonical=static function(array $a):array{ksort($a);return $a;};
 $ok($canonical($row)===$canonical($expected)&&$canonical($sqlRead($own,$order))===$canonical($expected),'complete persisted snapshot positive');
 require __DIR__.'/reserve-checks.php';
 $checkRevokedReservation=checkChargeReservation($db,$runtime,$identity,$pinned,$own,$sameTenantOther,$otherTenant,$first);
 $acl=$db->selectOne("SELECT pg_get_userbyid(p.proowner) owner,p.prosecdef definer,
 has_function_privilege('onepage_buyer_runtime',p.oid,'EXECUTE') runtime_execute,
 EXISTS(SELECT 1 FROM aclexplode(COALESCE(p.proacl,acldefault('f',p.proowner))) a WHERE a.grantee=0 AND a.privilege_type='EXECUTE') public_execute,
 has_table_privilege('onepage_buyer_runtime','onepage_buyer_lab.pending_orders','SELECT') direct_select,
 has_function_privilege('onepage_buyer_runtime','onepage_buyer_lab.principal(text)','EXECUTE') direct_principal,
 EXISTS(SELECT 1 FROM aclexplode(p.proacl) a WHERE a.grantee NOT IN(p.proowner,(SELECT oid FROM pg_roles WHERE rolname='onepage_buyer_runtime'))) extra_grantee
 FROM pg_proc p WHERE p.oid='onepage_buyer_lab.read_pending_order(text,uuid)'::regprocedure");
 $truth=static fn($v)=>in_array($v,[true,'t',1,'1'],true);
 $ok($acl!==null&&$acl->owner==='onepage_buyer_owner'&&$truth($acl->definer)&&$truth($acl->runtime_execute)
  &&!$truth($acl->public_execute)&&!$truth($acl->direct_select)&&!$truth($acl->direct_principal)&&!$truth($acl->extra_grantee),'catalog owner and exact ACL');
 $hash=fn(Request $r)=>hash('sha256',$r->session()->get('portal.browser_auth')['reference']);
 $principal=function(Request $r)use($db,$hash){return $db->selectOne('SELECT * FROM onepage_buyer_lab.principal(?)',[$hash($r)]);};
 $other=$principal($sameTenantOther);
 $ok($other!==null&&$other->organization_id===$row['company']&&$other->identity_id!==$row['buyer'],'same tenant different active buyer control');
 $foreign=$principal($otherTenant);
 $ok($foreign!==null&&$foreign->organization_id!==$row['company'],'different tenant active control');
 $ok($sqlRead($otherTenant,$order)===null,'different tenant SQL null');
 $deny=['status'=>'denied','settled'=>false,'preview_admitted'=>false,'binding'=>null];
 $ok($sqlRead($sameTenantOther,$order)===null&&$reader->read($sameTenantOther,$order)===$deny,'other buyer denied');
 $ok($sqlRead($own,'00000000-0000-0000-0000-000000000000')===null&&$reader->read($own,'00000000-0000-0000-0000-000000000000')===$deny,'missing generic denial');
 $ok($reader->read($own,$order)===$first&&$snapshot()===$before,'idempotent read no writes');
 foreach(['SELECT * FROM onepage_buyer_lab.pending_orders','SELECT * FROM onepage_buyer_lab.principal(\''.str_repeat('0',64).'\')'] as $sql){
  $pinned();Guard::runtime($runtime,$bootstrap,$identity,'onepage_buyer_runtime');$state=null;
  try{$runtime->query($sql);}catch(PDOException $e){$state=$e->errorInfo[0]??null;}
  $ok($state==='42501','direct access specifically denied');
 }
 $e=$db->selectOne('SELECT identity_id,organization_id,expires_at<=clock_timestamp() expired FROM portal_auth_sessions WHERE session_sha256=?',[$hash($expired)]);
 $ok($e!==null&&$e->identity_id===$row['buyer']&&$e->organization_id===$row['company']&&in_array($e->expired,[true,'t',1,'1'],true),'expired same buyer fixture control');
 $ok($sqlRead($expired,$order)===null&&$reader->read($expired,$order)===$deny,'expired native authority denied');
 $ok($principal($own)!==null&&$reader->read($own,$order)===$first,'membership revocation active control');
 // Negative fixture mutation only, never inside reader; no restoration of revoked authority.
 $db->update("UPDATE portal_memberships SET status='suspended',membership_revision=membership_revision+1 WHERE identity_id=? AND organization_id=?",[$row['buyer'],$row['company']]);
 $m=$db->selectOne('SELECT status FROM portal_memberships WHERE identity_id=? AND organization_id=?',[$row['buyer'],$row['company']]);
 $ok($m->status==='suspended'&&$principal($own)===null,'membership revocation persisted');
 $ok($sqlRead($own,$order)===null&&$reader->read($own,$order)===$deny&&$snapshot()===$before,'revoked no order writes');
 $checkRevokedReservation();
 return $checks;
}
