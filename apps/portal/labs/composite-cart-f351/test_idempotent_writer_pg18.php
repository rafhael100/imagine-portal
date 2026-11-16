<?php
declare(strict_types=1);
require __DIR__.'/SyntheticDraftWriter.php';

use App\Homologation\CompositeCartLab\SyntheticDraftWriter;

function assertF351(bool $condition,string $message): void {
    if(!$condition)throw new RuntimeException('F351 invariant: '.$message);
}
function mustReject(callable $fn,string $message): void {
    try {$fn();}catch(InvalidArgumentException){return;}
    throw new RuntimeException('F351 accepted forbidden input: '.$message);
}
if(getenv('PORTAL_F351_DISPOSABLE')!=='LOCAL_SYNTHETIC_WRITER_ONLY'
 || getenv('PGHOST')!=='127.0.0.1'
 || getenv('PGDATABASE')!=='portal_composite_f350_ci')
 throw new RuntimeException('F351 refuses non-disposable SQL lab');
$dsn='pgsql:host=127.0.0.1;port=5432;dbname=portal_composite_f350_ci;connect_timeout=3;sslmode=disable';
$options=[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,
 PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,
 PDO::ATTR_EMULATE_PREPARES=>false];
$admin=new PDO($dsn,'postgres','f350-disposable-admin-only',$options);
$writer=new SyntheticDraftWriter($admin);
$writer2=new SyntheticDraftWriter(new PDO(
 $dsn,'postgres','f350-disposable-admin-only',$options));
$a='f350_tenant_a_reader';
$b='f350_tenant_b_reader';
$selectionA=['site'=>'onepage','channels'=>['google_ads','meta_ads'],
 'tier'=>'recommended'];
$selectionB=['site'=>'landing-pages','channels'=>['meta_ads'],
 'tier'=>'minimum'];
$createdA=$writer->write($a,'f351-quote-001',$selectionA);
$replayA=$writer2->write($a,'f351-quote-001',$selectionA);
assertF351($createdA['replayed']===false,'first invocation creates');
assertF351($replayA['replayed']===true,'same tenant/request/selection replays');
assertF351($createdA['draftId']===$replayA['draftId'],'single immutable draft id');
assertF351($createdA['releaseStatus']==='HOLD_TENANT_AUTHN_PSP_AND_HUMAN_REVIEW',
 'always HOLD');
$createdB=$writer->write($b,'f351-quote-001',$selectionB);
assertF351($createdB['replayed']===false,'same request key isolated per role');
assertF351($createdB['draftId']!==$createdA['draftId'],'different tenant drafts');

$createdA2=$writer->write($a,'f351-quote-002',$selectionB);
assertF351($createdA2['replayed']===false,'second request gets distinct draft');
assertF351($createdA2['draftId']!==$createdA['draftId'],'separate request id');
mustReject(fn()=>$writer->write($a,'f351-quote-001',$selectionB),
 'same tenant/key cannot change priced payload');
mustReject(fn()=>$writer->write($a,'f351-quote-001',[
 'site'=>'onepage','channels'=>['google_ads'],'tier'=>'minimum'
]),'replay cannot silently remove media channels');
foreach ([
 [$a,'f351-quote-003',[...$selectionA,'customerId'=>'PRIVATE']],
 [$a,'f351-quote-003',[...$selectionA,'amountCents'=>1]],
 [$a,'f351-quote-003',['site'=>'none','channels'=>[],'tier'=>'minimum']],
 [$a,'f351-quote-003',['site'=>'none','channels'=>['chatgpt_ads'],'tier'=>'minimum']],
 ['f351_tenant_c_reader','f351-quote-003',$selectionA],
 [$a,'client-provided-unreviewed-key',$selectionA],
 [$a,'f351-quote-003',array_replace($selectionA,['tier'=>'vip'])]
] as [$role,$request,$selection])
 mustReject(fn()=>$writer->write($role,$request,$selection),
  'identity/price/client input forged');

$readerA=new PDO($dsn,$a,'f350-disposable-a-only',$options);
$readerB=new PDO($dsn,$b,'f350-disposable-b-only',$options);
$visible=fn(PDO $db)=>(int)$db->query(
 "SELECT count(*) FROM composite_cart_f350.draft_quote")->fetchColumn();
assertF351($visible($readerA)===2,
 'A sees its own two active F351 drafts only; revoked F350 hidden');
assertF351($visible($readerB)===1,
 'B sees its own F351 draft only; expired F350 hidden');
function columnDenied(PDO $db,string $query): void {
 try{$db->query($query);}
 catch(PDOException $e){
    assertF351((string)$e->getCode()==='42501',
     'denied column has no extra privilege');
    return;
 }
 throw new RuntimeException('F351 private hash columns leaked to reader');
}
columnDenied($readerA,'SELECT request_hash FROM composite_cart_f350.draft_quote');
columnDenied($readerB,'SELECT quote_digest FROM composite_cart_f350.draft_quote');
$count=$admin->prepare('SELECT count(*) FROM composite_cart_f350.draft_quote
 WHERE tenant_role=:role AND request_hash=:hash');
$count->execute([':role'=>$a,':hash'=>hash('sha256','f351-quote-001')]);
assertF351((int)$count->fetchColumn()===1,
 'one DB row per request hash guaranteed by the real unique index');
$data=$admin->prepare('SELECT id,server_quote,quote_digest FROM composite_cart_f350.draft_quote
 WHERE tenant_role=:role AND request_hash=:hash');
$data->execute([':role'=>$a,':hash'=>hash('sha256','f351-quote-001')]);
$record=$data->fetch();
assertF351($record!==false,'server-owned quote stored');
$payload=json_decode($record['server_quote'],true,512,JSON_THROW_ON_ERROR);
assertF351($payload['setup_activation_total_cents']===197000,
 'stored quote is PHP recomputed');
assertF351($payload['real_charge_enabled']===false
 &&$payload['public_checkout_authorized']===false,
 'stored quote has no money authority');
mustReject(fn()=>$writer->write($b,'f351-quote-003',[
 ...$selectionB,'tenantRole'=>$a
]),'no tenant impersonation inside quote');

$revoke=$admin->prepare('UPDATE composite_cart_f350.draft_quote
 SET revoked_at=now() WHERE id=:id');
$revoke->execute([':id'=>$createdA['draftId']]);
assertF351($visible($readerA)===1,'A revision fence hides revoked row');
mustReject(fn()=>$writer->write($a,'f351-quote-001',$selectionA),
 'revoked request cannot create fresh draft under same key');
$expire=$admin->prepare("UPDATE composite_cart_f350.draft_quote
 SET expires_at=now()-interval '1 second' WHERE id=:id");
$expire->execute([':id'=>$createdB['draftId']]);
assertF351($visible($readerB)===0,'B expiry effective immediately');
mustReject(fn()=>$writer->write($b,'f351-quote-001',$selectionB),
 'expired request cannot regenerate draft with same key');
$originalDb=getenv('PGDATABASE');
putenv('PGDATABASE=not-the-synthetic-database');
mustReject(fn()=>$writer->write($a,'f351-quote-004',$selectionA),
 'database guard refuses environment drift');
putenv('PGDATABASE='.$originalDb);

echo json_encode([
 'contractVersion'=>'portal-f351-idempotent-draft-pg18-lab-v1',
 'actualDbIdentityVerified'=>true,
 'oneRequestOneDraftPerTenant'=>true,
 'sameKeyCrossTenantIsolated'=>true,
 'replayDifferentPayloadDenied'=>true,
 'readerHashColumnsDenied'=>true,
 'revokedOrExpiredReplayDenied'=>true,
 'serverQuoteRecomputed'=>true,
 'privateCustomerDataAccepted'=>false,
 'publicCheckoutAuthorized'=>false,
 'realChargeEnabled'=>false,
 'releaseStatus'=>'HOLD_AUTHN_TRUSTED_WRITER_PSP_HUMAN_APPROVAL'
],JSON_THROW_ON_ERROR)."\n";
