<?php

declare(strict_types=1);

/**
 * F1-390: offline, non-financial contract tests for Asaas installation
 * settlement vs Portal versioned contract, plus Ed25519 issuer integration.
 * No Asaas account, API token, network call, webhook, subscription, charge
 * creation, customer name, CNPJ, CPF or real payment is used.
 */
if (PHP_SAPI!=='cli' || getenv('GITHUB_ACTIONS')!=='true' ||
    getenv('PORTAL_F390_ASAAS_CI')!=='1') {
    fwrite(STDERR,"F1-390 tests require an explicit disposable CI runner.\n");
    exit(2);
}
$dir=dirname(__DIR__).'/apps/portal/app/Domain/SitesIntegration/';
spl_autoload_register(static function(string $class)use($dir):void {
    $prefix='App\\Domain\\SitesIntegration\\';
    if (!str_starts_with($class,$prefix))return;
    $name=substr($class,strlen($prefix));
    if (preg_match('/\A[A-Za-z][A-Za-z0-9]*\z/D',$name)!==1) {
        throw new RuntimeException('Class name denied.');
    }
    require $dir.$name.'.php';
});

use App\Domain\SitesIntegration\AsaasTrustedPaymentBinding;
use App\Domain\SitesIntegration\AsaasTrustedPaymentBindingSource;
use App\Domain\SitesIntegration\AsaasOfficialPaymentReader;
use App\Domain\SitesIntegration\AsaasVerifiedSettlementSource;
use App\Domain\SitesIntegration\AsaasReadOnlyPaymentClient;
use App\Domain\SitesIntegration\PortalEd25519EntitlementIssuer;
use App\Domain\SitesIntegration\PortalCurrentRevocationEpochSource;
use App\Domain\SitesIntegration\PortalSiteContractSource;
use App\Domain\SitesIntegration\PortalSettlementEvidence;
use App\Domain\SitesIntegration\SiteContractSnapshot;

function testOk(bool $ok,string $why):void {
    global $checks;
    if (!$ok) throw new RuntimeException('F1-390: '.$why);
    ++$checks;
}
function denied(callable $action):bool {
    try{$action();}catch(DomainException|InvalidArgumentException){return true;}
    return false;
}

$checks=0;
$order='order-asaas-fictional';
$bind=new AsaasTrustedPaymentBinding(
    'tenant-asaas-ci',$order,'contract-asaas-ci',2,'onepage',
    'pay_123456789000','cus_123456789000','IMAGINE-CI-ORDER-001',
    6990,'PIX',
);
final class F390LocalBinding implements AsaasTrustedPaymentBindingSource
{
    public ?AsaasTrustedPaymentBinding $binding=null;
    public bool $fail=false;
    public int $reads=0;
    public function findInstallationPayment(string $orderRef): ?AsaasTrustedPaymentBinding
    {
        ++$this->reads;
        if ($this->fail)throw new RuntimeException('synthetic DB unavailable');
        return $this->binding;
    }
}
final class F390OfficialReader implements AsaasOfficialPaymentReader
{
    public ?array $payment=null;
    public bool $fail=false;
    public int $reads=0;
    public ?string $lastId=null;
    public function fetchPayment(string $paymentId):?array
    {
        ++$this->reads;
        $this->lastId=$paymentId;
        if ($this->fail)throw new RuntimeException('fictional API unavailable');
        return $this->payment;
    }
}
$local=new F390LocalBinding();
$reader=new F390OfficialReader();
$source=new AsaasVerifiedSettlementSource($local,$reader);
testOk((new AsaasVerifiedSettlementSource())->findCurrentSettledOrder($order)===null,
    'unbound production default must deny');
testOk($source->findCurrentSettledOrder($order)===null,
    'no local contract/payment mapping, deny');
testOk($reader->reads===0,'do not contact PSP when no trusted mapping');

$local->binding=$bind;
$paid=[
    'id'=>'pay_123456789000',
    'customer'=>'cus_123456789000',
    'externalReference'=>'IMAGINE-CI-ORDER-001',
    'status'=>'RECEIVED',
    'billingType'=>'PIX',
    'value'=>69.9,
    'deleted'=>false,
    'refundedDate'=>null,
    'refunds'=>[],
    'subscription'=>null,
];
$reader->payment=$paid;
$evidence=$source->findCurrentSettledOrder($order);
testOk($evidence instanceof PortalSettlementEvidence &&
    $evidence->tenantRef==='tenant-asaas-ci' &&
    $evidence->orderRef===$order &&
    $evidence->contractRevision===2 &&
    $evidence->productCode==='onepage' &&
    $evidence->settlementRef==='pay_123456789000' &&
    strlen($evidence->settlementSha256)===64,
    'fresh full official GET can approve only expected fictional installation');
testOk($reader->lastId===$bind->paymentId,'fetch PSP only by bound ID, not browser ID');
$hash=$evidence->settlementSha256;
$reader->payment['value']='69.90';
testOk($source->findCurrentSettledOrder($order)?->settlementSha256===$hash,
    'cents normalized deterministically across decimal JSON representations');

// Asaas 05/11/2026 public changelog: Sandbox payment identifiers can be
// pay_<digit>_<opaque>. Do not infer the identifier from positions or reject
// the underscore when binding to the complete official GET response.
$newPaymentId='pay_1_a1B2c3D4e5F6g7';
$local->binding=new AsaasTrustedPaymentBinding(
    'tenant-asaas-ci',$order,'contract-asaas-ci',2,'onepage',
    $newPaymentId,'cus_123456789000','IMAGINE-CI-ORDER-001',6990,'PIX'
);
$reader->payment=array_replace($paid,['id'=>$newPaymentId]);
testOk($source->findCurrentSettledOrder($order)?->settlementRef===$newPaymentId &&
    $reader->lastId===$newPaymentId,
    'official read and trusted contract accept new opaque underscore payment ID');
testOk(denied(static fn()=>new AsaasTrustedPaymentBinding(
    'tenant-asaas-ci',$order,'contract-asaas-ci',2,'onepage',
    'pay_1_a1B2c3D4e5F6g7/../admin','cus_123456789000',
    'IMAGINE-CI-ORDER-001',6990,'PIX'
)), 'unsafe payment IDs with path separators remain rejected');
$local->binding=$bind;
$reader->payment=$paid;


$reject=[
    ['status'=>'PENDING'],
    ['status'=>'CONFIRMED'],
    ['status'=>'OVERDUE'],
    ['status'=>'REFUNDED'],
    ['status'=>'RECEIVED_IN_CASH'],
    ['id'=>'pay_OTHER987654'],
    ['customer'=>'cus_OTHER987654'],
    ['externalReference'=>'IMAGINE-OTHER'],
    ['billingType'=>'CREDIT_CARD'],
    ['value'=>69.89],
    ['value'=>69.901],
    ['value'=>'69.901'],
    ['value'=>'6.99e1'],
    ['value'=>0],
    ['value'=>-69.90],
    ['value'=>null],
    ['deleted'=>true],
    ['refundedDate'=>'2026-11-14'],
    ['refunds'=>[['id'=>'refund-test']]],
    ['chargeback'=>['status'=>'OPEN']],
    ['currency'=>'USD'],
    ['subscription'=>'sub_123456789000'],
];
foreach ($reject as $case) {
    $reader->payment=array_replace($paid,$case);
    testOk($source->findCurrentSettledOrder($order)===null,
        'reject PSP mutated/refunded/foreign/subscription/mismatched payment');
}
$reader->payment=[];
testOk($source->findCurrentSettledOrder($order)===null,'empty PSP record denied');
$reader->payment=null;
testOk($source->findCurrentSettledOrder($order)===null,'PSP missing record denied');
$reader->fail=true;
testOk($source->findCurrentSettledOrder($order)===null,'PSP outage fails closed');
$reader->fail=false;
$local->fail=true;
testOk($source->findCurrentSettledOrder($order)===null,'trusted DB outage fails closed');
$local->fail=false;

$reader->payment=$paid;
testOk($source->findCurrentSettledOrder('order-other')===null,
    'locally bound order mismatch denied');
testOk($source->findCurrentSettledOrder('http://evil/redirect')===null,
    'untrusted reference denied without PSP request');

testOk(denied(static fn()=>new AsaasTrustedPaymentBinding(
    'tenant-asaas-ci',$order,'contract-asaas-ci',2,'onepage',
    'pay_123456789000','cus_123456789000','IMAGINE-CI-ORDER-001',
    6990,'PIX','monthly'
)), 'recurring subscription cycle cannot authorize Site installation');
testOk(denied(static fn()=>new AsaasReadOnlyPaymentClient(
    'production',str_repeat('A',40)
)), 'production PSP GET requires separate operator opt-in');
testOk(denied(static fn()=>new AsaasReadOnlyPaymentClient(
    'sandbox','wrong header with CRLF'."\r\n".'access_token'
)), 'unsafe header values rejected');
testOk(denied(static fn()=>new AsaasReadOnlyPaymentClient(
    'https://evil.example',str_repeat('A',40)
)), 'arbitrary origin never allowed');
testOk(denied(static fn()=>new AsaasReadOnlyPaymentClient(
    'sandbox','short'
)), 'missing official API credentials rejected');
// Client may be constructed in sandbox, but NEVER called in this test.
$noNetwork=new AsaasReadOnlyPaymentClient('sandbox',str_repeat('S',40));
testOk($noNetwork instanceof AsaasOfficialPaymentReader,
    'sandbox GET-only reader class present without network call');

final class F390Contracts implements PortalSiteContractSource
{
    public function findAuthorizedSiteOrder(string $orderRef):?SiteContractSnapshot
    {
        if($orderRef!=='order-asaas-fictional')return null;
        return new SiteContractSnapshot(
            'tenant-asaas-ci','order-asaas-fictional',
            'contract-asaas-ci',2,'onepage'
        );
    }
}
final class F390Revocations implements PortalCurrentRevocationEpochSource
{
    public function readCurrentActiveEpoch(
        string $tenantRef,string $orderRef,string $contractRef,int $contractRevision
    ):?int {
        return ($tenantRef==='tenant-asaas-ci' &&
            $orderRef==='order-asaas-fictional' &&
            $contractRef==='contract-asaas-ci' &&
            $contractRevision===2) ? 4 : null;
    }
}
$issuer=new PortalEd25519EntitlementIssuer(new F390Contracts(),$source,new F390Revocations());
$key=sodium_crypto_sign_secretkey(sodium_crypto_sign_keypair());
$wire=$issuer->issue($order,'operation-asaas-ci','idem-asaas-ci',1800000000,$key);
testOk($wire['settlementRef']==='pay_123456789000' &&
    $wire['settlementSha256']===$hash && $wire['revocationEpoch']===4 &&
    strlen($wire['signature'])===128,
    'existing Portal Ed25519 issuer consumes verified Asaas settlement evidence');
$reader->payment=$paid;
$reader->payment['status']='CONFIRMED';
testOk(denied(static fn()=>$issuer->issue(
    'order-asaas-fictional','operation-second','idem-second',1800000000,$key
)), 'an issuer cannot sign when PSP declines to prove RECEIVED payment');
$reader->payment=$paid;
$reader->payment['refunds']=[['id'=>'refund-fictional']];
testOk(denied(static fn()=>$issuer->issue(
    'order-asaas-fictional','operation-second','idem-second',1800000000,$key
)), 'issuer cannot sign a refunded installation even after earlier successful read');

echo "$checks F1-390 fake Asaas independent official GET verification/Ed25519 integration assertions passed.\n";
echo "NO-GO: all payments synthetic; NO official account, API call, route, webhook, customer, charge, subscription, key or deployment.\n";
