<?php

declare(strict_types=1);

/**
 * 4-project disposable exercise AFTER real Astro clicks -> Portal purchase ->
 * Sites PG18 outbox ACK: only artificial CRM bindings/consent/e-mail are used.
 * This is not a visitor form, real consent or CRM API.
 */
if(PHP_SAPI!=='cli'
 || getenv('GITHUB_ACTIONS')!=='true'
 || getenv('PORTAL_P0_FOUR_PROJECT_CONSENT_LAB')!=='1'
 || getenv('DB_HOST')!=='127.0.0.1'
 || getenv('CRM_LAB_DB')!=='crm_p0_consent_lab'
 || getenv('SITES_F360_PG_LAB')!=='1') {
 fwrite(STDERR,"Refusing non-disposable four-project lead rehearsal.\n");
 exit(2);
}
$opt=[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,
      PDO::ATTR_EMULATE_PREPARES=>false,
      PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,
      PDO::ATTR_TIMEOUT=>6];
$dsn='pgsql:host=127.0.0.1;port=5432;connect_timeout=5;dbname=';
$sites=new PDO($dsn.'sites_f360_lab','postgres','ci-fake-admin-intake-only',$opt);
$crm=new PDO($dsn.'crm_p0_consent_lab','crm_p0_intake_ci','ci-fake-intake-sql-only',$opt);
$crmAdmin=new PDO($dsn.'crm_p0_consent_lab','postgres','ci-fake-admin-intake-only',$opt);
function fourCheck(bool $ok,string $why): void {
 if(!$ok) throw new RuntimeException('P0 four-project CI: '.$why);
}
fourCheck($sites->query('SELECT current_database()')->fetchColumn()==='sites_f360_lab'
  && $crm->query('SELECT session_user')->fetchColumn()==='crm_p0_intake_ci',
 'must inspect separate Site and CRM PostgreSQL18 databases with correct identities');
fourCheck((int)$sites->query('SHOW server_version_num')->fetchColumn()>=180000,
 'must run PostgreSQL18');
$events=$sites->query("SELECT a.operation_ref,a.site_ref,a.product_code,
 o.delivery_state,o.delivery_attempts
 FROM sites_f360.admissions a JOIN sites_f360.outbox o
 USING(tenant_ref,operation_ref)
 WHERE a.operation_ref LIKE 'idem-p0-%' ORDER BY a.operation_ref")->fetchAll();
fourCheck(count($events)===2,'exactly two Portal-originated Site items');
foreach($events as $e) {
 fourCheck($e['delivery_state']==='delivered'
   && (int)$e['delivery_attempts']===1
   && in_array($e['product_code'],['onepage','landing_page'],true)
   && str_starts_with($e['site_ref'],'site-p0-'),
  'only previously ACKed real Site synthetic outbox rows can be inputs');
}

$org='11111111-1111-4111-8111-111111111111';
$fakeBindings=[
 ['binding'=>'22222222-2222-4222-8222-222222222222',
  'site'=>'44444444-4444-4444-8444-444444444444',
  'form'=>'55555555-5555-4555-8555-555555555555',
  'dest'=>'66666666-6666-4666-8666-666666666666'],
 ['binding'=>'23232323-2323-4232-8232-232323232323',
  'site'=>'45454545-4545-4454-8454-454545454545',
  'form'=>'56565656-5656-4565-8565-565656565656',
  'dest'=>'67676767-6767-4676-8676-676767676767']
];
$key='ci-fake-sites-crm-lead-intake-hmac-2026-11-only';
$statement=$crm->prepare(
 'SELECT crm_p0.ingest_fake_consented_lead('
 .'?::uuid,?::uuid,?::uuid,?::uuid,?::uuid,?::uuid,'
 .'?::integer,?::boolean,?::text,?::text,?::bigint,?::text)'
);
function fakeUuidFromSiteEvent(string $reference):string {
 $hex=substr(hash('sha256','synthetic-lead-only|'.$reference),0,32);
 $hex[12]='4';
 $hex[16]='8';
 return substr($hex,0,8).'-'.substr($hex,8,4).'-'.substr($hex,12,4)
   .'-'.substr($hex,16,4).'-'.substr($hex,20);
}
function fakeReceipt(PDOStatement $stmt,array $binding,string $org,
 string $submission,string $fakeEmail,int $expires,bool $consent=true,
 ?string $macOverride=null):string {
 global $key;
 $revision=1;
 $notice='ci-notice-1';
 $scope=implode('|',['v1',$org,$binding['binding'],$submission,
  $binding['site'],$binding['form'],$binding['dest'],(string)$revision,
  $consent?'consent-true':'consent-false',$notice,$fakeEmail,(string)$expires]);
 $mac=$macOverride??hash_hmac('sha256',$scope,$key);
 $stmt->execute([$org,$binding['binding'],$submission,$binding['site'],
  $binding['form'],$binding['dest'],$revision,$consent?'true':'false',
  $notice,$fakeEmail,$expires,$mac]);
 $status=$stmt->fetchColumn();
 $stmt->closeCursor();
 return (string)$status;
}
$expiry=time()+60;
$seen=[];
foreach($events as $index=>$event) {
 $binding=$fakeBindings[$index]; // CI-only positional mapping, NOT real tenant binding.
 $submission=fakeUuidFromSiteEvent($event['operation_ref']);
 $email='ci-four-project-'.($index+1).'@example.invalid';
 fourCheck(!isset($seen[$submission]),'synthetic source submissions must be distinct');
 $seen[$submission]=true;
 fourCheck(fakeReceipt($statement,$binding,$org,$submission,$email,$expiry)==='created',
  'Site event must yield one committed fictional CRM receipt only with fake consent');
 fourCheck(fakeReceipt($statement,$binding,$org,$submission,$email,$expiry)==='duplicate',
  'same source event cannot create an extra CRM lead');
}
$first=$events[0];
$binding=$fakeBindings[0];
$submission=fakeUuidFromSiteEvent($first['operation_ref']);
$fake='ci-four-project-1@example.invalid';
$failures=0;
function mustDeny(callable $callback,string $reason):void {
 global $failures;
 try{$callback();}
 catch(PDOException $e) {
  if(!in_array((string)$e->getCode(),['42501','23505'],true)) {
   throw new RuntimeException('Unexpected SQL denial code for '.$reason);
  }
  ++$failures;
  return;
 }
 throw new RuntimeException('Four-project CRM did not refuse '.$reason);
}
mustDeny(static fn()=>fakeReceipt($statement,$binding,$org,
  $submission,$fake,$expiry,false),'missing opt-in');
mustDeny(static fn()=>fakeReceipt($statement,$binding,
 '88888888-8888-4888-8888-888888888888',
 $submission,$fake,$expiry,true),'forged tenant');
mustDeny(static fn()=>fakeReceipt($statement,$binding,$org,
 $submission,'other-content@example.invalid',$expiry,true),
 'same source UUID, altered payload');
mustDeny(static fn()=>fakeReceipt($statement,$binding,$org,
 $submission,$fake,$expiry,true,str_repeat('f',64)),
 'unsigned/replaced MAC');
mustDeny(static fn()=>fakeReceipt($statement,$binding,$org,
 $submission,$fake,time()-60,true),'expired mocked receipt');
mustDeny(static fn()=>fakeReceipt($statement,$binding,$org,
 $submission,'real-contact@gmail.com',$expiry,true),
 'real email accidentally entering synthetic-only laboratory');
mustDeny(static fn()=> $crm->query('SELECT * FROM crm_p0.leads'),
 'shared CRM intake role bypassing stored function via raw SELECT');

$total=(int)$crmAdmin->query('SELECT count(*) FROM crm_p0.leads')->fetchColumn();
fourCheck($total===2,'exactly two fake CRM leads after two real Site outbox events');
$consented=(int)$crmAdmin->query("SELECT count(*) FROM crm_p0.leads
 WHERE notice_revision='ci-notice-1' AND consented_at IS NOT NULL
 AND email LIKE '%@example.invalid'")->fetchColumn();
fourCheck($consented===2,'all fake lead timestamps originate at PostgreSQL commit');
$readAgain=new PDO($dsn.'crm_p0_consent_lab','postgres',
  'ci-fake-admin-intake-only',$opt);
fourCheck((int)$readAgain->query('SELECT count(*) FROM crm_p0.leads')->fetchColumn()===2,
 'fake contact receipts must be durable across independent SQL connections');

if(($tmp=getenv('RUNNER_TEMP'))!==false && $tmp!=='' && is_dir($tmp)) {
 $claims=[
  'schema'=>'imagine.p0.four-project-consented-lead-fixture.v1',
  'commercial_astrobuilt_shas_pinned'=>true,
  'portal_purchases_persisted_fake'=>4,
  'sites_projects_admitted_fake'=>2,
  'sites_outbox_events_acknowledged_fake'=>2,
  'crm_leads_persisted_fake'=>2,
  'crm_consent_authority_real'=>false,
  'crm_service_auth_real'=>false,
  'crm_lgpd_purpose_evidence_real'=>false,
  'crm_visitor_payload_real'=>false,
  'crm_public_endpoint_enabled'=>false,
  'external_network_or_vps_used'=>false,
  'release_gate'=>'NO_GO',
 ];
 $serialized=json_encode($claims,JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES);
 $report=json_encode(['claims'=>$claims,'sha256_integrity_only'=>hash('sha256',$serialized)],
  JSON_PRETTY_PRINT|JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES);
 $file=$tmp.'/p0-four-project-fake-consent-evidence.json';
 if(file_exists($file)||is_link($file)
    ||file_put_contents($file,$report."\n",LOCK_EX)===false) {
   throw new RuntimeException('Four-project report cannot be safely written.');
 }
}
echo "PASS: 4 Astro→Portal fake purchases, 2 Sites ACK, 2 fake consented CRM PG18 records; {$failures} negative cases.\n";
echo "NO-GO: no real business authorization, payment, privacy consent, visitors, lead transport or deployment.\n";
