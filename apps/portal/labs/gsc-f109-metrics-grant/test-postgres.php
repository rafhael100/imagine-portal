<?php
declare(strict_types=1);

/** F109 disposable PostgreSQL-only strict metrics-view grant review. */
$root=dirname(__DIR__,2).'/app/Domain/SeoReview/';
foreach([
 'GoogleSearchConsoleOAuthPreflight.php',
 'GoogleSearchConsolePropertyIdentifier.php',
 'GoogleSearchConsoleTenantScopedTransaction.php',
 'OfflineOpenSeoDailyPreview.php',
 'OfflinePortalGscMetricsGrantReview.php',
] as $f)require_once $root.$f;

use App\Domain\SeoReview\OfflinePortalGscMetricsGrantReview as Gate;
function requireF109(bool $ok):void {
    if(!$ok)throw new RuntimeException('F109 restricted view grant proof failed');
}
if(PHP_SAPI!=='cli'||$argc!==2||
   getenv('APP_ENV')!=='testing'||
   getenv('PORTAL_GSC_F108_ATOMIC_LAB')!=='1'||
   getenv('PORTAL_GSC_F109_VIEW_LAB')!=='1'||
   getenv('PORTAL_OPENSEO_DAILY_LAB')!=='1'||
   getenv('PORTAL_GSC_LEDGER_DSN')!==
     'pgsql:host=127.0.0.1;port=5432;dbname=imagine_gsc_ci_test'){
    fwrite(STDERR,"F109: private CI PostgreSQL only\n");exit(2);
}
$ref=bin2hex(random_bytes(32));
$org='c8a2d30a-82dd-48e7-8c51-f313d7469681';
$user='46fd090a-11fd-4b68-917b-c3ae903dac9c';
$member='3305d451-b6ce-4e2b-81a7-28285a5a6261';
$tenant='imagine-demo';$actor='review-operator';
$fp=hash('sha256','sc-domain:example.com');$site='sc-domain:example.com';
$connectGrant='e6e11fd5-b362-487e-968f-ce726d721b80';
$viewGrant='f97bade3-403b-45f7-9714-94bb997ef9c4';
$now=time();$denied=0;$owner=null;$reader=null;$revoker=null;
try{
 $bytes=file_get_contents($argv[1]);
 requireF109(is_string($bytes)&&strlen($bytes)<65536);
 $dto=json_decode($bytes,true,16,JSON_THROW_ON_ERROR);
 requireF109(is_array($dto));
 $dsn=getenv('PORTAL_GSC_LEDGER_DSN');
 $options=[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,
           PDO::ATTR_EMULATE_PREPARES=>false];
 $owner=new PDO($dsn,getenv('PORTAL_GSC_LEDGER_USER'),
   getenv('PORTAL_GSC_LEDGER_PASSWORD'),$options);
 $reader=new PDO($dsn,'portal_gsc_f108_reader',
   'f108_ci_synthetic_only',$options);
 $revoker=new PDO($dsn,getenv('PORTAL_GSC_LEDGER_USER'),
   getenv('PORTAL_GSC_LEDGER_PASSWORD'),$options);

 foreach(['portal_membership_grants','portal_memberships',
          'portal_auth_sessions','portal_gsc_property_grants']
      as $table){
   $q=$owner->prepare(
     "SELECT has_table_privilege('portal_gsc_f108_reader',:tab,'SELECT'),
             has_table_privilege('portal_gsc_f108_reader',:tab,'UPDATE')");
   $q->execute(['tab'=>'public.'.$table]);
   foreach($q->fetch(PDO::FETCH_NUM) as $v)
     requireF109(in_array($v,[false,'f',0,'0'],true));
 }
 $role=$owner->query("SELECT rolcanlogin,rolsuper,rolbypassrls,
   rolcreatedb,rolcreaterole,rolinherit FROM pg_roles
   WHERE rolname='portal_gsc_f109_owner'")->fetch(PDO::FETCH_ASSOC);
 requireF109(is_array($role));
 foreach($role as $v)requireF109(in_array($v,[false,'f',0,'0'],true));
 requireF109(!Gate::canAuthorizeCustomer()&&!Gate::canQueryGoogle()&&
   !Gate::canPublish());

 $owner->prepare("INSERT INTO portal_identities
  (id,status,security_revision,email_verified_at,mfa_confirmed_at)
  VALUES (?,'active',1,now()-interval '1 hour',now()-interval '1 hour')")
   ->execute([$user]);
 $owner->prepare("INSERT INTO portal_organizations
  (id,status,security_revision) VALUES (?,'active',5)")
   ->execute([$org]);
 $owner->prepare("INSERT INTO portal_memberships
  (id,identity_id,organization_id,status,membership_revision)
  VALUES (?,?,?,'active',7)")->execute([$member,$user,$org]);
 $owner->prepare("INSERT INTO portal_auth_sessions
  (session_sha256,identity_id,organization_id,status,session_purpose,
  issued_at,expires_at,mfa_verified_at,identity_revision,
  membership_revision,organization_revision)
  VALUES (?,?,?,'active','business',now()-interval '2 minutes',
  now()+interval '30 minutes',now()-interval '1 minute',1,7,5)")
   ->execute([hash('sha256',$ref),$user,$org]);
 $owner->prepare("INSERT INTO portal_gsc_identity_scope_links
  (portal_organization_id,portal_identity_id,tenant_ref,actor_ref,
   organization_revision,membership_revision,issued_at,expires_at,
   link_status,revoked_at)
  VALUES (?,?,?,?,5,7,?,?, 'active',NULL)")
   ->execute([$org,$user,$tenant,$actor,$now-100,$now+1000]);
 $owner->prepare("INSERT INTO portal_gsc_property_grants
  (tenant_ref,actor_ref,property_fingerprint,scope,grant_status,
   verified_at,expires_at)
  VALUES (?,?,?,'https://www.googleapis.com/auth/webmasters.readonly',
          'active',?,?)")
   ->execute([$tenant,$actor,$fp,$now-50,$now+600]);

 $add=$owner->prepare("INSERT INTO portal_membership_grants
    (id,membership_id,permission_code) VALUES (?,?,?)");
 $add->execute([$connectGrant,$member,'seo.gsc.connect']);
 $gate=new Gate($reader);
 $inspect=static fn(?Closure $locked=null,?array $response=null) =>
   $gate->inspect($ref,$org,$site,time(),$response??$dto,$locked);

 // A healthy GSC recorded grant plus seo.gsc.connect is NOT permission
 // to view metrics. No implicit role or membership inheritance.
 requireF109($inspect()===null);$denied++;
 $add->execute([$viewGrant,$member,'seo.gsc.view']);
 $result=$inspect();
 requireF109(is_array($result)&&
   $result['contractVersion']==='portal-gsc-f109-view-grant-review-lab-v1'&&
   $result['explicitSeoGscViewRowObserved']===true &&
   $result['connectOnlyGrantsInsufficient']===true &&
   $result['summary']['clicks']===$dto['summary']['clicks']);
 foreach(['sessionAuthenticatedForProduction',
   'seoViewPermissionAuthorizedForProduction','googleOAuthVerifiedNow',
   'sourceOriginVerified','customerDisplayAuthorized',
   'productionReadAuthorized','publicationAuthorized','networkPerformed'
 ] as $flag)requireF109($result[$flag]===false);
 $encoded=json_encode($result,JSON_THROW_ON_ERROR);
 foreach([$ref,$user,$org,$member,$site,$actor] as $secret)
   requireF109(!str_contains($encoded,$secret));

 // An independent revoker cannot delete the explicit permission between
 // the locked session check and the GSC read in a single transaction.
 $attempt=function()use($revoker,$viewGrant):bool{
   $revoker->beginTransaction();
   try{
     $revoker->exec("SET LOCAL lock_timeout='150ms'");
     $revoker->prepare("DELETE FROM portal_membership_grants WHERE id=?")
       ->execute([$viewGrant]);
     $revoker->rollBack();
     return false;
   }catch(PDOException $e){
     if($revoker->inTransaction())$revoker->rollBack();
     return $e->getCode()==='55P03';
   }
 };
 requireF109(is_array($inspect($attempt)));
 $revoker->prepare("DELETE FROM portal_membership_grants WHERE id=?")
   ->execute([$viewGrant]);
 requireF109($inspect()===null);$denied++;
 $add->execute([$viewGrant,$member,'seo.gsc.view']);
 requireF109(is_array($inspect()));

 foreach([
   array_merge($dto,['tenantRef'=>'other-tenant']),
   array_merge($dto,['customerDisplayAuthorized'=>true]),
   array_merge($dto,['summary'=>array_merge(
       $dto['summary'],['clicks'=>999])]),
   array_merge($dto,['propertyFingerprint'=>hash('sha256','sc-domain:example.net')]),
 ] as $mutation){
   requireF109($inspect(null,$mutation)===null);$denied++;
 }
 requireF109($gate->inspect(str_repeat('0',64),$org,$site,time(),$dto)===null);
 $denied++;
 requireF109($gate->inspect($ref,
   '197d34aa-5750-4efe-bb11-d35bd8d23122',$site,time(),$dto)===null);
 $denied++;
 $owner->prepare("UPDATE portal_auth_sessions
    SET session_purpose='invitation' WHERE session_sha256=?")
   ->execute([hash('sha256',$ref)]);
 requireF109($inspect()===null);$denied++;
 $owner->prepare("UPDATE portal_auth_sessions
    SET session_purpose='business' WHERE session_sha256=?")
   ->execute([hash('sha256',$ref)]);
 $owner->prepare("UPDATE portal_memberships SET membership_revision=8 WHERE id=?")
   ->execute([$member]);
 requireF109($inspect()===null);$denied++;
 $owner->prepare("UPDATE portal_memberships SET membership_revision=7 WHERE id=?")
   ->execute([$member]);
 $owner->prepare("UPDATE portal_gsc_property_grants
   SET grant_status='revoked',revoked_at=? WHERE tenant_ref=?")
   ->execute([$now,$tenant]);
 requireF109($inspect()===null);$denied++;
 $owner->prepare("UPDATE portal_gsc_property_grants
   SET grant_status='active',revoked_at=NULL WHERE tenant_ref=?")
   ->execute([$tenant]);
 requireF109(is_array($inspect()));
 putenv('PORTAL_GSC_F109_VIEW_LAB=0');
 requireF109($inspect()===null);$denied++;
 putenv('PORTAL_GSC_F109_VIEW_LAB=1');
 putenv('APP_ENV=production');
 requireF109($inspect()===null);$denied++;
 putenv('APP_ENV=testing');
 requireF109((new Gate($owner))->inspect($ref,$org,$site,time(),$dto)===null);
 $denied++;
 requireF109(!$reader->inTransaction()&&!$revoker->inTransaction());
 echo "F109: connect-only rejected; concurrent view-grant deletion ".
      "blocked by row lock; {$denied} adverse/revoked conditions denied; ".
      "no customer authority\n";
}catch(Throwable){
 fwrite(STDERR,"F109 explicit GSC view permission review failed\n");exit(1);
}finally{
 $reader=null;$revoker=null;
 if($owner instanceof PDO){
  try{
   $owner->prepare("DELETE FROM portal_membership_grants
     WHERE membership_id=?")->execute([$member]);
   $owner->prepare("DELETE FROM portal_auth_sessions
     WHERE session_sha256=?")->execute([hash('sha256',$ref)]);
   $owner->prepare("DELETE FROM portal_memberships
     WHERE id=?")->execute([$member]);
   $owner->prepare("DELETE FROM portal_identities
     WHERE id=?")->execute([$user]);
   $owner->prepare("DELETE FROM portal_organizations
     WHERE id=?")->execute([$org]);
   $owner->prepare("DELETE FROM portal_gsc_identity_scope_links
     WHERE tenant_ref=?")->execute([$tenant]);
   $owner->prepare("DELETE FROM portal_gsc_property_grants
     WHERE tenant_ref=?")->execute([$tenant]);
  }catch(Throwable){}
 }
}
