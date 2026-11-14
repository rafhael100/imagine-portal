<?php

declare(strict_types=1);

/**
 * P0 CI ONLY. Same real Chromium synthetic individual purchases drive Sites
 * admission/outbox, and then a separate fake-consent CRM PG18 receipt.
 *
 * This is a cross-repository contract test, NOT a client form/API, PSP
 * confirmation, legal opt-in or production Sites-to-CRM transport.
 */
if (PHP_SAPI !== 'cli'
    || getenv('GITHUB_ACTIONS') !== 'true'
    || getenv('P0_OWNED_TO_SITES_CI') !== '1'
    || getenv('P0_OWNED_TO_CRM_CI') !== '1'
    || getenv('CRM_P0_CONSENT_LAB') !== '1'
    || getenv('DB_HOST') !== '127.0.0.1'
    || getenv('DB_PORT') !== '5432'
    || getenv('DB_DATABASE') !== 'sites_f360_lab'
    || getenv('CRM_LAB_DB') !== 'crm_p0_consent_lab') {
    fwrite(STDERR, "Refusing non-disposable multi-project CRM integration.\n");
    exit(2);
}
$runner = getenv('RUNNER_TEMP');
if (!is_string($runner) || $runner === '' || !is_dir($runner) || is_link($runner)) {
    throw new RuntimeException('Missing disposable GitHub runner scratch.');
}
$dsn = 'pgsql:host=127.0.0.1;port=5432;connect_timeout=5;dbname=';
$opts = [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
         PDO::ATTR_EMULATE_PREPARES => false,
         PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
         PDO::ATTR_TIMEOUT => 6];
$sites = new PDO($dsn.'sites_f360_lab','postgres',
    'f360-fictional-admin-disposable-only',$opts);
$crmAdmin = new PDO($dsn.'crm_p0_consent_lab','postgres',
    'f360-fictional-admin-disposable-only',$opts);
$crm = new PDO($dsn.'crm_p0_consent_lab','crm_p0_intake_ci',
    'ci-fake-intake-sql-only',$opts);
function p0Assert(bool $ok,string $message): void {
    if (!$ok) throw new RuntimeException('Owned Portal→Sites→CRM: '.$message);
}
p0Assert($sites->query('SELECT current_database()')->fetchColumn()==='sites_f360_lab'
    && $crmAdmin->query('SELECT current_database()')->fetchColumn()==='crm_p0_consent_lab'
    && $crm->query('SELECT session_user')->fetchColumn()==='crm_p0_intake_ci'
    && (int)$sites->query('SHOW server_version_num')->fetchColumn()>=180000,
    'both disposable PostgreSQL18 databases and restricted CRM role required');

$rows=$sites->query(
    "SELECT a.operation_ref,a.site_ref,a.product_code,o.delivery_state,
            o.delivery_attempts
       FROM sites_f360.admissions a
       JOIN sites_f360.outbox o USING (tenant_ref,operation_ref)
      WHERE a.operation_ref LIKE 'idem-ownedp0-%'
      ORDER BY a.operation_ref"
)->fetchAll(PDO::FETCH_ASSOC);
p0Assert(count($rows)===2,'exactly two individually owned Site projects');
foreach($rows as $row) {
    p0Assert(str_starts_with($row['operation_ref'],'idem-ownedp0-')
        && str_starts_with($row['site_ref'],'site-ownedp0-')
        && in_array($row['product_code'],['onepage','landing_page'],true)
        && $row['delivery_state']==='delivered'
        && (int)$row['delivery_attempts']===1,
        'only delivered Site outbox from the individual checkout can be considered');
}

// All IDs below are *independent test-authority data*, not real customer
// bindings. The mapping is deliberately positional and MUST NOT be promoted.
$fixtures=[
    [
      'binding'=>'22222222-2222-4222-8222-222222222222',
      'site'=>'44444444-4444-4444-8444-444444444444',
      'form'=>'55555555-5555-4555-8555-555555555555',
      'destination'=>'66666666-6666-4666-8666-666666666666',
    ],
    [
      'binding'=>'23232323-2323-4232-8232-232323232323',
      'site'=>'45454545-4545-4454-8454-454545454545',
      'form'=>'56565656-5656-4565-8565-565656565656',
      'destination'=>'67676767-6767-4676-8676-676767676767',
    ],
];
$organization='11111111-1111-4111-8111-111111111111';
$secret='ci-fake-sites-crm-lead-intake-hmac-2026-11-only';
$statement=$crm->prepare(
    'SELECT crm_p0.ingest_fake_consented_lead('
    .'?::uuid,?::uuid,?::uuid,?::uuid,?::uuid,?::uuid,'
    .'?::integer,?::boolean,?::text,?::text,?::bigint,?::text)'
);
function syntheticSubmission(string $operation): string {
    $bytes=substr(hash('sha256','owned-customer-ci-only|'.$operation),0,32);
    $bytes[12]='4'; $bytes[16]='8';
    return substr($bytes,0,8).'-'.substr($bytes,8,4).'-'.substr($bytes,12,4)
        .'-'.substr($bytes,16,4).'-'.substr($bytes,20);
}
function receipt(
    PDOStatement $q,array $binding,string $org,string $submission,
    string $fakeEmail,int $expiry,bool $consent=true,?string $overrideMac=null
): string {
    global $secret;
    $parts=['v1',$org,$binding['binding'],$submission,$binding['site'],
        $binding['form'],$binding['destination'],'1',
        $consent?'consent-true':'consent-false','ci-notice-1',$fakeEmail,
        (string)$expiry];
    $mac=$overrideMac??hash_hmac('sha256',implode('|',$parts),$secret);
    // PDO false can bind as an empty string, so use explicit pg boolean text.
    $q->execute([$org,$binding['binding'],$submission,$binding['site'],
        $binding['form'],$binding['destination'],1,$consent?'true':'false',
        'ci-notice-1',$fakeEmail,$expiry,$mac]);
    $status=$q->fetchColumn();
    $q->closeCursor();
    return (string)$status;
}
function refusal(callable $action,string $case): void {
    try {$action();}
    catch (PDOException $e) {
        if (in_array((string)$e->getCode(),['42501','23505'],true)) return;
        throw new RuntimeException('Incorrect SQL refusal: '.$case);
    }
    throw new RuntimeException('Unsafe CRM admission: '.$case);
}
$expiry=time()+60;
$subscriptions=[];
foreach($rows as $i=>$row) {
    $submission=syntheticSubmission($row['operation_ref']);
    $fixture=$fixtures[$i];
    $mail='synthetic-owned-p0-'.($i+1).'@example.invalid';
    $subscriptions[]=[$fixture,$submission,$mail];
    p0Assert(receipt($statement,$fixture,$organization,$submission,$mail,$expiry)==='created',
        'first fake consented lead must commit');
    p0Assert(receipt($statement,$fixture,$organization,$submission,$mail,$expiry)==='duplicate',
        'retry of same customer-originated source must be idempotent');
}
[$first,$firstSubmission,$firstMail]=$subscriptions[0];
refusal(static fn()=>receipt($statement,$first,$organization,
    $firstSubmission,$firstMail,$expiry,false),'no explicit fake consent');
refusal(static fn()=>receipt($statement,$first,$organization,
    $firstSubmission,$firstMail,$expiry,true,str_repeat('0',64)),'forged HMAC');
refusal(static fn()=>receipt($statement,$first,$organization,
    $firstSubmission,'different@example.invalid',$expiry),'payload collision');
refusal(static fn()=>receipt($statement,$first,
    '88888888-8888-4888-8888-888888888888',
    $firstSubmission,$firstMail,$expiry),'wrong organization');
refusal(static fn()=>receipt($statement,$first,$organization,
    $firstSubmission,$firstMail,time()-1),'expired capability');
refusal(static fn()=>receipt($statement,$first,$organization,
    $firstSubmission,'not-fictional@gmail.com',$expiry),'real address refused');
refusal(static fn()=>$crm->query('SELECT * FROM crm_p0.leads'),
    'no shared-role direct SQL read access');

$leads=(int)$crmAdmin->query('SELECT count(*) FROM crm_p0.leads')->fetchColumn();
p0Assert($leads===2,'exactly two synthetic leads persisted without denied mutation');
p0Assert((int)$crmAdmin->query(
    "SELECT count(*) FROM crm_p0.leads WHERE email LIKE '%@example.invalid'
      AND notice_revision='ci-notice-1' AND consented_at IS NOT NULL"
)->fetchColumn()===2,'two fake consent evidence records persisted');
$reopened=new PDO($dsn.'crm_p0_consent_lab','postgres',
    'f360-fictional-admin-disposable-only',$opts);
p0Assert((int)$reopened->query('SELECT count(*) FROM crm_p0.leads')->fetchColumn()===2,
    'two durable CRM records after SQL connection restart');

$claims=[
  'schema'=>'imagine.p0.individual-portal-sites-crm.pg18.v1',
  'commercial_cta_individual_customer_orders_fake'=>4,
  'sites_projects_acknowledged_fake'=>2,
  'crm_receipts_created_fake'=>2,
  'crm_repeated_receipts_idempotent'=>2,
  'crm_raw_sql_customer_data_access_allowed'=>false,
  'crm_real_customer_identity_verified'=>false,
  'crm_real_explicit_consent_verified'=>false,
  'crm_real_s2s_authentication'=>false,
  'sites_or_crm_public_endpoint_enabled'=>false,
  'real_psp_or_contract_verified'=>false,
  'vps_changed'=>false,
  'production_released'=>false,
  'release_gate'=>'NO_GO',
];
$canonical=json_encode($claims,JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR);
$report=json_encode(['claims'=>$claims,'sha256_integrity_only'=>hash('sha256',$canonical)],
    JSON_UNESCAPED_SLASHES|JSON_PRETTY_PRINT|JSON_THROW_ON_ERROR);
$target=$runner.'/p0-individual-portal-sites-crm-evidence.json';
if (file_exists($target)||is_link($target)
    ||file_put_contents($target,$report."\n",LOCK_EX)===false) {
    throw new RuntimeException('Refusing to overwrite synthetic CI evidence.');
}
echo "PASS: four individual customer purchases → two Site ACKs → two fake consented CRM PG18 receipts (2 idempotent retries).\n";
echo "NO-GO: synthetic signing keys, customer bindings and consent; nothing public.\n";
