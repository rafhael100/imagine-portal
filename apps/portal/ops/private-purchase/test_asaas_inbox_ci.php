<?php
declare(strict_types=1);
require dirname(__DIR__, 2).'/app/Homologation/AsaasWebhookInboxCandidate.php';
use App\Homologation\AsaasWebhookInboxCandidate;

if (getenv('GITHUB_ACTIONS')!=='true' || getenv('ASAAS_INBOX_CI')!=='1') {
    fwrite(STDERR, "NO-GO: webhook tests require synthetic GitHub CI\n");
    exit(2);
}
$db=new PDO('pgsql:host=127.0.0.1;port=5432;dbname=portal_asaas_inbox_ci',
    'postgres',getenv('PGPASSWORD'),[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
$db->exec(file_get_contents(__DIR__.'/asaas-inbox-ci.sql'));
$token=bin2hex(random_bytes(32));
$account='12345678-90ab-4abc-8123-1234567890ab';
$inbox=new AsaasWebhookInboxCandidate($db,$token,$account);
$payload=[
  'id'=>'evt_05b708f961d739ea7eba7e4db318f621&368604920',
  'event'=>'PAYMENT_RECEIVED',
  'account'=>['id'=>$account,'ownerId'=>null],
  'payment'=>['id'=>'pay_synthetic_12345','value'=>1.0,'status'=>'RECEIVED'],
  'newOptionalField'=>'Asaas allows additional fields'
];
$body=json_encode($payload,JSON_THROW_ON_ERROR);
function denied(callable $fn, string $what): void {
    try { $fn(); } catch (InvalidArgumentException|RuntimeException) { return; }
    throw new RuntimeException('Expected denial: '.$what);
}
denied(fn()=>new AsaasWebhookInboxCandidate($db,'short',$account),'weak auth token');
denied(fn()=>$inbox->receive(null,$body),'missing auth');
denied(fn()=>$inbox->receive(str_repeat('f',64),$body),'wrong auth');
denied(fn()=>$inbox->receive($token,'{broken'),'malformed JSON');
denied(fn()=>$inbox->receive($token,str_repeat('x',65537)),'oversized JSON');
$bad=$payload;unset($bad['id']);
denied(fn()=>$inbox->receive($token,json_encode($bad)),'no event id');
$bad=$payload;$bad['account']['id']='another-tenant';
denied(fn()=>$inbox->receive($token,json_encode($bad)),'other Asaas account');
$bad=$payload;unset($bad['payment']['id']);
denied(fn()=>$inbox->receive($token,json_encode($bad)),'payment has no id');

if ($inbox->receive($token,$body)!=='recorded') throw new RuntimeException('first event not recorded');
if ($inbox->receive($token,$body)!=='duplicate') throw new RuntimeException('same ID not deduplicated');
$bad=$payload;$bad['payment']['value']=2.0;
denied(fn()=>$inbox->receive($token,json_encode($bad)),'reused event ID with conflicting body');
if ((int)$db->query('SELECT count(*) FROM portal_asaas_ci.webhook_inbox')->fetchColumn()!==1)
    throw new RuntimeException('Duplicate or forged event persisted');
$other=[
  'id'=>'evt_other_synthetic',
  'event'=>'SUBSCRIPTION_CHANGED',
  'account'=>['id'=>$account],
  'subscription'=>['id'=>'sub_synthetic']
];
if ($inbox->receive($token,json_encode($other))!=='recorded')
    throw new RuntimeException('Safe unknown event not recorded');
$rows=$db->query('SELECT event_type,payment_id,payload_sha256,review_state FROM portal_asaas_ci.webhook_inbox ORDER BY event_id')->fetchAll(PDO::FETCH_ASSOC);
if (count($rows)!==2 ||
    count(array_filter($rows,fn($r)=>$r['review_state']==='pending_external_reconciliation'))!==2 ||
    count(array_filter($rows,fn($r)=>preg_match('/\A[a-f0-9]{64}\z/D',$r['payload_sha256'])))!==2 ||
    $inbox->approvesPayment() || $inbox->activatesService()) {
    throw new RuntimeException('Fail-closed webhook candidate violated');
}
echo "PASS Asaas token, account binding, durable PG18 ID deduplication, conflict denial and NO BILLING\n";
