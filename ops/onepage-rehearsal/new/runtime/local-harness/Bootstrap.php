<?php
declare(strict_types=1);
namespace PrivateOnepageOneShot;
use App\Domain\Identity\Security\PortalPasswordCredential;
use App\Domain\Identity\Security\PortalTotpSecretCipher;
use App\Domain\SitesIntegration\OnepageEntryPaymentTerms as Terms;
use App\Homologation\Commerce\ApprovedOfferCatalog;
use App\Infrastructure\Commerce\PostgresOnepageOrders;
use App\Infrastructure\Commerce\PostgresOnepageEntryPayments;
use App\Infrastructure\Identity\PostgresPortalBrowserAuthentication;
use Illuminate\Support\Facades\Artisan;
use PDO;
use PrivateD0IntegratedLab\PdoFinancialPort;
use PrivateSharedIngress\{CaptureHarness,SharedCatalog};
final class Bootstrap {
 private const ROOT='/opt/onepage/runtime';
 private const DUAL=self::ROOT.'/fixtures/dual';
 private const ORIGINAL=self::DUAL;
 private const FIXED=self::ROOT.'/fixtures/finance/finance.sql';
 private const SERVICE=self::ROOT.'/fixtures/finance/service-authority.sql';
 private const A='1fcc491f-96d9-4efe-8ec5-b8b09de750dd';
 private const N='native-entry-sandbox';
 private const P='pay_ConfigFixture1';
 private const E='evt_oneshot_fixture1';
 private static function read(string $path,string $hash):string{
  $b=file_get_contents($path);LocalGate::need(is_string($b)&&hash('sha256',$b)===$hash);return $b;
 }
 private static function ddl(LocalGate $g,PDO $pdo,string $sql):void{$g->before($pdo);LocalGate::need($pdo->exec($sql)!==false);}
 private static function q(PDO $db,string $sql,array $args=[]):mixed{$q=$db->prepare($sql);$q->execute($args);return $q->columnCount()>0?$q->fetchColumn():null;}
 public static function setup(LocalGate $g):array{
  $root=$g->control();$native=$g->nativeName();$inbox=$g->inboxName();
  self::ddl($g,$root,'CREATE ROLE portal_native_test_bootstrap LOGIN SUPERUSER');
  foreach([$native,$inbox] as $db)self::ddl($g,$root,'CREATE DATABASE "'.$db.'" OWNER portal_native_test_bootstrap');
  $p=$g->open('native',$native,LocalGate::INSTALLER);
  $i=$g->open('inbox_admin',$inbox,LocalGate::INSTALLER);
  $g->empty($p);$g->empty($i);
  // Same native migrations and policies as the #979/982 lab; independent local gate.
  foreach([
   '2026_11_09_000002_create_portal_identity_foundation.php','2026_11_09_000003_add_mfa_replay_counter_to_portal_identities.php',
   '2026_11_10_000004_add_org_revision_to_portal_sessions.php','2026_11_10_000005_create_portal_auth_throttle_buckets.php',
   '2026_11_10_000008_add_session_purpose_to_portal_auth_sessions.php','2026_11_11_000012_fence_portal_membership_revision.php',
   '2026_11_11_000013_fence_portal_organization_revision.php','2026_11_11_000014_guard_portal_session_issuance.php',
   '2026_11_12_000016_fence_portal_identity_revision.php'
  ] as $file){$g->before($p);LocalGate::need(Artisan::call('migrate',['--path'=>'database/migrations/'.$file,'--force'=>true,'--no-interaction'=>true])===0);}
  foreach(['portal_browser_auth.sql','portal_browser_principal_candidate.sql','portal_onepage_orders.sql',
   'portal_onepage_entry_payments.sql','portal_onepage_d0_candidate.sql','portal_onepage_d0_account_epoch.sql'] as $file){
   $b=file_get_contents(database_path('security/'.$file));LocalGate::need(is_string($b)&&$b!=='');self::ddl($g,$p,$b);
  }
  foreach(['portal_browser_runtime','portal_onepage_runtime','portal_onepage_publisher','portal_onepage_psp_registrar','portal_onepage_d0_verifier'] as $role)
   self::ddl($g,$p,'ALTER ROLE '.$role.' LOGIN');
  LocalGate::need(self::q($p,'SELECT portal_onepage.d0_account_gate()')===null);
  self::ddl($g,$p,"CREATE OR REPLACE FUNCTION portal_onepage.d0_account_gate() RETURNS text LANGUAGE sql IMMUTABLE AS \$\$ SELECT 'native-entry-sandbox'::text \$\$");
  self::ddl($g,$p,self::read(self::FIXED,'da176ef9c16e5d32059456bccedbf344cfdc7dc5a6c6aad60c597feaab6e4c91'));
  self::ddl($g,$p,'ALTER ROLE onepage_finance_lab_verifier LOGIN');
  self::ddl($g,$p,self::read(self::SERVICE,'45aa23f7712603945b71c27b28f802991e14d01c82acd45dfdd021bfcdde2436'));
  self::ddl($g,$i,'CREATE ROLE postgres NOLOGIN NOSUPERUSER NOINHERIT NOCREATEDB NOCREATEROLE NOREPLICATION NOBYPASSRLS');
  foreach(['inbox.sql'=>'292b23f7416e34e9b6f6b8ebb9dff6d056426f4b','lease.sql'=>'03da8af90eca8733fb1842498fd4ac6b50fe8d7d'] as $file=>$hash){
   $b=file_get_contents(self::ORIGINAL.'/'.$file);
   LocalGate::need(is_string($b)&&sha1('blob '.strlen($b)."\0".$b)===$hash&&substr_count($b,"\\set ON_ERROR_STOP on\n")===1);
   self::ddl($g,$i,str_replace("\\set ON_ERROR_STOP on\n",'',$b));
  }
  foreach(['Ingress','SharedInboxPort','SharedCatalog','CaptureHarness'] as $cls)require_once self::DUAL.'/'.$cls.'.php';
  $parts=[];
  foreach(['pre.sql','functions.sql','post.sql'] as $f)$parts[$f]=file_get_contents(self::DUAL.'/shared-'.$f);
  $expected=SharedCatalog::expected($parts);
  $g->before($i);$i->beginTransaction();
  try{
   foreach($parts as $sql)$i->exec($sql);
   SharedCatalog::matches($expected,json_decode($i->query(file_get_contents(self::DUAL.'/delta.sql'))->fetchColumn(),true,64,JSON_THROW_ON_ERROR));
   LocalGate::need($i->commit());
  }catch(\Throwable $e){if($i->inTransaction())$i->rollBack();throw $e;}
  return self::seed($g,$p,$i);
 }
 private static function seed(LocalGate $g,PDO $p,PDO $i):array{
  $native=$g->nativeName();$inbox=$g->inboxName();
  $proof=bin2hex(random_bytes(32));$key=random_bytes(32);$pepper=bin2hex(random_bytes(32));
  self::q($p,'INSERT INTO portal_browser_private.proof_key(secret) VALUES(?)',[$proof]);
  $company=self::q($p,'SELECT gen_random_uuid()');$buyer=self::q($p,'SELECT gen_random_uuid()');
  self::q($p,"INSERT INTO public.portal_organizations(id,reference,display_name,status,security_revision) VALUES(?,'native-order-company','Synthetic OneShot','active',1)",[$company]);
  self::q($p,"INSERT INTO public.portal_identities(id,email_normalized,password_hash,status,mfa_secret_ciphertext,email_verified_at,mfa_confirmed_at,security_revision) VALUES(?,'onepage-own@example.invalid',?,'active',?,clock_timestamp()-interval '1 minute',clock_timestamp()-interval '1 minute',1)",
   [$buyer,PortalPasswordCredential::makeHash('native-order-fixture-password'),PortalTotpSecretCipher::seal('GEZDGNBVGY3TQOJQGEZDGNBVGY3TQOJQ',$key)]);
  self::q($p,"INSERT INTO public.portal_memberships(id,identity_id,organization_id,role,status,membership_revision) VALUES(gen_random_uuid(),?,?,'member','active',1)",[$buyer,$company]);
  $auth=new PostgresPortalBrowserAuthentication($g->open('auth',$native,'portal_browser_runtime'),$native,$proof,$key,$pepper);
  $pending=$auth->beginPassword('onepage-own@example.invalid','native-order-fixture-password',$company,'192.0.2.10');LocalGate::need(is_string($pending));
  $counter=(int)self::q($p,'SELECT floor(extract(epoch FROM clock_timestamp())/30)::bigint');
  $h=hash_hmac('sha1',pack('N2',intdiv($counter,4294967296),$counter%4294967296),'12345678901234567890',true);
  $offset=ord($h[19])&15;$code=str_pad((string)((unpack('N',substr($h,$offset,4))[1]&0x7fffffff)%1000000),6,'0',STR_PAD_LEFT);
  $session=$auth->completeMfa($pending,$code,'192.0.2.10');LocalGate::need(is_string($session)&&$auth->isActiveBusinessSession($session));
  $runtime=$g->open('order_runtime',$native,'portal_onepage_runtime');
  $publisher=$g->open('publisher',$native,'portal_onepage_publisher');
  $store=new PostgresOnepageOrders($runtime,$native);
  $draft=$store->createDraft($session,'oneshot-native-fixture',['site'=>'onepage','channels'=>[],'tier'=>'minimum']);
  LocalGate::need($draft['status']==='draft');
  $o=(new ApprovedOfferCatalog())->quote('onepage');
  $terms=['scope'=>'onepage','payment_policy'=>'onepage-d0-d90-v1','onepage_payment_policy'=>['version'=>'onepage-d0-d90-v1','currency'=>'BRL','setup_total_cents'=>$o['setup_total_cents'],'setup_charges'=>$o['setup_charges'],'monthly'=>$o['monthly'],'entry_installment_key'=>'setup-1','briefing_after_entry_confirmation'=>true,'publication_requires_separate_acceptance'=>true]];
  LocalGate::need(self::q($publisher,'SELECT portal_onepage.publish_contract(?,?,?,?::jsonb,?::jsonb,?)',['publication-onepage-v1','onepage-agreement',1,json_encode($draft['quote'],JSON_THROW_ON_ERROR),json_encode($terms,JSON_THROW_ON_ERROR),'Synthetic fixture only.'])===true);
  $review=$store->review($session,$draft['id']);LocalGate::need(is_array($review));
  $yes=['accepted'=>true]+array_intersect_key($review,array_flip(['quote_id','offer_digest','terms_digest','contract_ref','contract_revision','contract_digest']));
  $a=$store->accept($session,'oneshot-native-accept',$draft['id'],$yes);
  LocalGate::need($a['status']==='pending_payment'&&!$a['payment_requested']&&!$a['site_provisioned']);
  $order=$store->findOwnedAcceptedOrder($session,$a['id']);LocalGate::need($order!==null&&$order->buyerId===$buyer&&$order->companyId===$company);
  self::q($p,"INSERT INTO portal_onepage.payment_accounts(account_ref,environment,active,configuration_ref) VALUES(?,'sandbox',true,'cfg_reader_00001')",[self::N]);
  $reg=new PostgresOnepageEntryPayments($g->open('registrar',$native,'portal_onepage_psp_registrar'),$native,self::N);
  $cust='cus_ConfigFixture1';$ref=Terms::reference($order,self::N,$cust);
  $provider=['id'=>self::P,'customer'=>$cust,'externalReference'=>$ref,'billingType'=>'PIX','status'=>'RECEIVED','value'=>'330.00','currency'=>'BRL'];
  LocalGate::need($reg->registerCustomer($order->id,$cust)==='recorded');
  LocalGate::need($reg->registerEntry($order->id,self::P,$cust,$ref,33000,Terms::schedule($order)[0]['due_date'],'PIX')==='recorded');
  LocalGate::need($auth->revoke($session)&&!$auth->isActiveBusinessSession($session));
  $f=$g->open('finance',$native,'onepage_finance_lab_verifier');
  $cap=$g->open('capture',$inbox,'onepage_ingress_capture');
  $g->open('entry',$native,'onepage_d0_service_b');$g->open('inbox',$inbox,'asaas_reconcile_worker');
  $financial=new PdoFinancialPort($f,fn(PDO $x):bool=>$x===$f&&$g->current('finance'),$g->config('finance'));
  $capture=new CaptureHarness($i,$cap,fn(string $s,PDO $x):bool=>($s==='inbox_audit'&&$x===$i&&$g->current('inbox_admin'))||($s==='ingress_capture'&&$x===$cap&&$g->current('capture')),$financial);
  $capture->receive(self::A,self::N,self::E,self::P);
  $target=['account'=>self::A,'nativeAccount'=>self::N,'event'=>self::E,'payment'=>self::P,'tenant'=>$order->companyReference,'order'=>$order->id];
  return ['target'=>$target,'provider'=>$provider,'native'=>$p,'inbox'=>$i,'session'=>$session,'auth'=>$auth];
 }
}
