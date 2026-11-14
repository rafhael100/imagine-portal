<?php

declare(strict_types=1);

/**
 * F1-392 PHP8.3/8.4 and real disposable PG18: two independently constrained
 * roles (ingress vs worker), lease/retry/ACK, official settlement re-read.
 * Test money, IDs, credentials and provider responses are all synthetic.
 */
if (PHP_SAPI!=='cli' || getenv('GITHUB_ACTIONS')!=='true' ||
    getenv('PORTAL_F392_ASAAS_WORKER_CI')!=='1' ||
    getenv('DB_HOST')!=='127.0.0.1' ||
    getenv('DB_DATABASE')!=='portal_asaas_ci') {
    fwrite(STDERR,"F1-392 must run in disposable GitHub PG18.\n");
    exit(2);
}

$source=dirname(__DIR__).'/apps/portal/app/Domain/SitesIntegration/';
spl_autoload_register(static function(string $class)use($source):void {
    $prefix='App\\Domain\\SitesIntegration\\';
    if(!str_starts_with($class,$prefix))return;
    $name=substr($class,strlen($prefix));
    if(preg_match('/\A[A-Za-z][A-Za-z0-9]*\z/D',$name)!==1)
        throw new RuntimeException('Invalid test class name');
    require $source.$name.'.php';
});

use App\Domain\SitesIntegration\{
    AsaasWebhookPgInbox,AsaasWebhookReconciliationWorker,
    AsaasTrustedPaymentBinding,AsaasTrustedEventPaymentSource,
    AsaasTrustedPaymentBindingSource,AsaasOfficialPaymentReader,
    AsaasVerifiedSettlementSource,
};

function conn(string $name,string $password):PDO {
    return new PDO(
        'pgsql:host=127.0.0.1;port=5432;dbname=portal_asaas_ci;connect_timeout=5',
        $name,$password,
        [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,
         PDO::ATTR_EMULATE_PREPARES=>false],
    );
}
$checks=0;
function assertF392(bool $condition,string $reason):void {
    global $checks;
    if(!$condition)throw new RuntimeException('F1-392 FAIL: '.$reason);
    ++$checks;
}
function deniedF392(callable $operation):bool {
    try{$operation();}catch(Throwable){return true;}
    return false;
}
$account='1fcc491f-96d9-4efe-8ec5-b8b09de750dd';
$token=str_repeat('T',48);
$admin=conn('postgres','CI-ASaaS-ADMIN-SYNTHETIC');
$ingress=conn('asaas_webhook_runtime','CI-ONLY-NOT-A-REAL-KEY-1234567890');
$workerDb=conn('asaas_reconcile_worker','CI-ONLY-F392-WORKER-IS-NOT-REAL');
$otherWorkerDb=conn('asaas_reconcile_worker','CI-ONLY-F392-WORKER-IS-NOT-REAL');
assertF392((int)$admin->query('SHOW server_version_num')->fetchColumn()>=180000,
    'Require real PG18 server');
$inbox=new AsaasWebhookPgInbox($ingress,$account,$token);
assertF392(deniedF392(static fn()=>new AsaasWebhookReconciliationWorker($admin,$account)),
    'DB superuser cannot impersonate a restricted worker');
assertF392(deniedF392(static fn()=>new AsaasWebhookReconciliationWorker($ingress,$account)),
    'Webhook-only role must never claim or acknowledge events');

final class F392Bindings implements AsaasTrustedEventPaymentSource,AsaasTrustedPaymentBindingSource
{
    /** @var array<string,AsaasTrustedPaymentBinding> */
    public array $byId=[];
    public string $account='1fcc491f-96d9-4efe-8ec5-b8b09de750dd';
    public int $lookups=0;

    public function findInstallationByAccountAndPaymentId(
        string $accountRef,string $paymentId
    ):?AsaasTrustedPaymentBinding {
        ++$this->lookups;
        return $accountRef===$this->account ? $this->byId[$paymentId]??null : null;
    }
    public function findInstallationPayment(string $orderRef):?AsaasTrustedPaymentBinding {
        foreach($this->byId as $b) {
            if($b->orderRef===$orderRef)return $b;
        }
        return null;
    }
}
final class F392OfficialReader implements AsaasOfficialPaymentReader
{
    /** @var array<string,array<string,mixed>> */
    public array $records=[];
    public bool $outage=false;
    public int $calls=0;

    public function fetchPayment(string $paymentId):?array {
        ++$this->calls;
        if($this->outage)throw new RuntimeException('fictional API outage');
        return $this->records[$paymentId]??null;
    }
}
$bindings=new F392Bindings();
$reader=new F392OfficialReader();
$bind=new AsaasTrustedPaymentBinding(
    'tenant-f392','order-f392','contract-f392',2,
    'onepage','pay_123456789000','cus_123456789000',
    'CI-F392-INSTALLMENT-001',6990,'PIX'
);
$bindings->byId[$bind->paymentId]=$bind;
$reader->records[$bind->paymentId]=[
    'id'=>$bind->paymentId,'customer'=>$bind->customerId,
    'externalReference'=>$bind->externalReference,'billingType'=>'PIX',
    'status'=>'RECEIVED','value'=>69.90,'deleted'=>false,
    'refundedDate'=>null,'refunds'=>[],'subscription'=>null,
];
$settlements=new AsaasVerifiedSettlementSource($bindings,$reader);
$worker=new AsaasWebhookReconciliationWorker($workerDb,$account,$bindings,$settlements);
$worker2=new AsaasWebhookReconciliationWorker($otherWorkerDb,$account,$bindings,$settlements);
$unboundWorker=new AsaasWebhookReconciliationWorker($otherWorkerDb,$account);

function emitF392(AsaasWebhookPgInbox $inbox,string $token,string $account,
                  string $id,string $type,string $paymentId):void {
    $body=json_encode([
      'id'=>$id,'event'=>$type,'dateCreated'=>'2026-11-14 08:15:00',
      'account'=>['id'=>$account,'ownerId'=>null],
      'payment'=>['id'=>$paymentId,'status'=>'RECEIVED',
                  'customer'=>'cus_123456789000','value'=>69.90],
    ],JSON_THROW_ON_ERROR);
    $done=$inbox->capture('POST','application/json',$token,$body);
    assertF392($done['received']===true && $done['duplicate']===false,'fresh delivery committed');
}
emitF392($inbox,$token,$account,'evt_0000000000000001','PAYMENT_RECEIVED',$bind->paymentId);
emitF392($inbox,$token,$account,'evt_0000000000000002','PAYMENT_CONFIRMED',$bind->paymentId);
emitF392($inbox,$token,$account,'evt_0000000000000003','PAYMENT_REFUNDED',$bind->paymentId);
emitF392($inbox,$token,$account,'evt_0000000000000004','PAYMENT_RECEIVED','pay_123456789999');

$result=$worker->processOne();
assertF392($result===['processed'=>true,'outcome'=>'observed_psp_received'],
    'only actual bound positive official read yields audit-only snapshot');
assertF392($reader->calls===1 && $bindings->lookups===1,
    'worker must get current official payment with trusted payment ID mapping');
$status=$admin->query(
    "SELECT state,attempts,lease_token IS NULL AS no_lease
       FROM portal_psp.asaas_inbox
      WHERE event_id='evt_0000000000000001'"
)->fetch(PDO::FETCH_ASSOC);
assertF392($status['state']==='observed_psp_received' &&
    (int)$status['attempts']===1 &&
    in_array($status['no_lease'],[true,'t','1'],true),
    'ACK clears lease only after verification; no entitlement or publishability');
assertF392($unboundWorker->processOne()===['processed'=>true,'outcome'=>'retry_wait'],
    'default worker without binding and PSP source cannot claim payment');
assertF392($worker->processOne()===['processed'=>true,'outcome'=>'manual_review'],
    'refund event always escalates; no reliance on a preceding received event');
assertF392($worker->processOne()===['processed'=>true,'outcome'=>'retry_wait'],
    'unknown account/payment link never becomes verified');
assertF392($worker2->processOne()===['processed'=>false,'outcome'=>'empty'],
    'no duplicate claim of terminal/temporarily deferred events');

$rows=$admin->query(
    "SELECT state, count(*) as total FROM portal_psp.asaas_inbox GROUP BY state"
)->fetchAll(PDO::FETCH_KEY_PAIR);
assertF392((int)($rows['observed_psp_received']??0)===1 &&
    (int)($rows['manual_review']??0)===1 &&
    (int)($rows['retry_wait']??0)===2,
    'observations, dangerous events and retries remain separate');
assertF392((int)$admin->query(
    "SELECT count(*) FROM portal_psp.asaas_inbox WHERE state='reconciled'"
)->fetchColumn()===0,
    'no webhook or worker can mark a contract reconciled for release');

// Force one retry due and one expired stale lease. Both are admin-controlled
// time-travel IN DISPOSABLE CI ONLY; a production worker cannot mutate them.
$admin->exec(
    "UPDATE portal_psp.asaas_inbox
        SET retry_after=clock_timestamp()-INTERVAL '2 seconds',
            attempts=7
      WHERE event_id='evt_0000000000000002'"
);
assertF392($worker->processOne()===['processed'=>true,'outcome'=>'manual_review'],
    'after eight failed reads an event must stay blocked, not spin forever');
$admin->exec(
    "UPDATE portal_psp.asaas_inbox
        SET state='claimed',lease_token='11111111-2222-4333-8444-555555555555',
            lease_expires_at=clock_timestamp()+INTERVAL '120 seconds',
            retry_after=NULL
      WHERE event_id='evt_0000000000000004'"
);
assertF392($worker->processOne()===['processed'=>false,'outcome'=>'empty'],
    'active claim lease must not be stolen by another worker');
$admin->exec(
    "UPDATE portal_psp.asaas_inbox
        SET lease_expires_at=clock_timestamp()-INTERVAL '2 seconds'
      WHERE event_id='evt_0000000000000004'"
);
assertF392($worker2->processOne()===['processed'=>true,'outcome'=>'retry_wait'],
    'abandoned expired lease can be reclaimed by another worker');

// Adversarial: source+reader misreporting payment state or errors must
// never create a false official observation.
emitF392($inbox,$token,$account,'evt_0000000000000005','PAYMENT_RECEIVED',$bind->paymentId);
$reader->records[$bind->paymentId]['status']='REFUNDED';
assertF392($worker->processOne()===['processed'=>true,'outcome'=>'retry_wait'],
    'PSP refund after earlier success must block new positive settlement');
emitF392($inbox,$token,$account,'evt_0000000000000006','PAYMENT_RECEIVED',$bind->paymentId);
$reader->outage=true;
assertF392($worker->processOne()===['processed'=>true,'outcome'=>'retry_wait'],
    'official PSP outage fails closed without acknowledging payment received');
$reader->outage=false;

// If the eighth attempted reconciliation loses its ACK or worker dies,
// expiry MUST trigger terminal manual review, never a permanently orphaned
// row with attempts=8 and an expired lease.
emitF392($inbox,$token,$account,'evt_0000000000000007','PAYMENT_RECEIVED',$bind->paymentId);
$admin->exec(
    "UPDATE portal_psp.asaas_inbox
        SET state='claimed', attempts=8,
            lease_token='aaaa1111-bbbb-4222-8333-cccc44444444',
            lease_expires_at=clock_timestamp()-INTERVAL '2 seconds',
            retry_after=NULL
      WHERE event_id='evt_0000000000000007'"
);
assertF392($worker->processOne()===['processed'=>false,'outcome'=>'empty'],
    'reap expired final lease without issuing another PSP read');
$state7=$admin->query(
    "SELECT state, lease_token IS NULL AS cleared
       FROM portal_psp.asaas_inbox
      WHERE event_id='evt_0000000000000007'"
)->fetch(PDO::FETCH_ASSOC);
assertF392($state7['state']==='manual_review' &&
    in_array($state7['cleared'],[true,'t','1'],true),
    'crashed eighth attempt is durably escalated with no stale lease');

foreach ([
    "UPDATE portal_psp.asaas_inbox SET payment_id='pay_77777777777'",
    'DELETE FROM portal_psp.asaas_inbox',
    'SET ROLE postgres',
] as $sql) {
    assertF392(deniedF392(static fn()=>$workerDb->exec($sql)),
        'restricted worker cannot alter identity, delete records or elevate role');
}
assertF392(deniedF392(static fn()=>new AsaasWebhookReconciliationWorker(
    $workerDb,'2fcc491f-96d9-4efe-8ec5-b8b09de750dd'
))===false,
    'worker constructor accepts scoped format (DB still denies foreign account)');
// The constructor validates role; account scope is enforced by SQL policy,
// so a worker configured for another account must see ZERO events.
$foreignWorker=new AsaasWebhookReconciliationWorker(
    $otherWorkerDb,'2fcc491f-96d9-4efe-8ec5-b8b09de750dd',
    $bindings,$settlements
);
assertF392($foreignWorker->processOne()===['processed'=>false,'outcome'=>'empty'],
    'FORCE RLS must not expose another PSP account event to the worker');
assertF392((int)$admin->query(
    "SELECT count(*) FROM portal_psp.asaas_inbox
      WHERE state='observed_psp_received'"
)->fetchColumn()===1,
    'refunds/outages/missing bindings cannot create a second positive observation');

echo "$checks F1-392 synthetic Asaas PSP reconciliation + PG18 durable lease tests passed.\n";
echo "NO-GO: observed PSP snapshot is audit-only, never triggers contract, payment, entitlement, Site publishing or VPS.\n";
