<?php

declare(strict_types=1);

/**
 * P0 combined review: actual approved quote classes and Asaas sandbox
 * preview MUST NOT accidentally become authorized Sites settlement.
 *
 * This tests integration of code copied from two independent review lines
 * in ONE checkout. No Asaas network call, no customer, no real credentials.
 */
if (PHP_SAPI!=='cli' || getenv('GITHUB_ACTIONS')!=='true' ||
    getenv('PORTAL_P0_CHECKOUT_ASAAS_PARITY_CI')!=='1') {
    fwrite(STDERR,"P0 checkout/Asaas parity only on isolated CI runner.\n");
    exit(2);
}
$base=dirname(__DIR__).'/apps/portal/app/';
require $base.'Homologation/Commerce/ApprovedOfferCatalog.php';
require $base.'Homologation/Commerce/AsaasSandboxGateway.php';
spl_autoload_register(static function(string $class)use($base):void {
    $prefix='App\\Domain\\SitesIntegration\\';
    if(!str_starts_with($class,$prefix))return;
    $name=substr($class,strlen($prefix));
    if(!preg_match('/\A[A-Za-z][A-Za-z0-9]*\z/D',$name)) {
        throw new RuntimeException('Untrusted class name');
    }
    require $base.'Domain/SitesIntegration/'.$name.'.php';
});
use App\Homologation\Commerce\ApprovedOfferCatalog;
use App\Homologation\Commerce\AsaasSandboxGateway;
use App\Domain\SitesIntegration\{
    AsaasTrustedPaymentBinding,AsaasTrustedPaymentBindingSource,
    AsaasOfficialPaymentReader,AsaasVerifiedSettlementSource,
    PortalEd25519EntitlementIssuer,PortalSettlementEvidence,
};
$checks=0;
function verifyP0(bool $ok,string $msg):void {
    global $checks;
    if(!$ok)throw new RuntimeException('P0 quote/payment failure: '.$msg);
    ++$checks;
}
function rejectedP0(callable $fn):bool {
    try{$fn();}catch(DomainException|InvalidArgumentException|RuntimeException){
        return true;
    }
    return false;
}
$catalog=new ApprovedOfferCatalog();
foreach (['onepage'=>[99000,33000],'landing-pages'=>[129000,43000]] as $sku=>[$total,$first]) {
    $quote=$catalog->quote($sku);
    verifyP0($quote['gateway']==='asaas' && $quote['currency']==='BRL' &&
        $quote['real_charge_enabled']===false,
        'only approved static quote with NO live payments');
    verifyP0($quote['setup_total_cents']===$total &&
        $quote['amount_now_cents']===$first &&
        array_sum(array_column($quote['setup_charges'],'amount_cents'))===$total,
        'D0 due differs from three-installment setup total');
    verifyP0(array_column($quote['setup_charges'],'offset_days')===[0,30,60] &&
        $quote['monthly']['first_due_offset_days']===90 &&
        $quote['monthly']['amount_cents']===7990 &&
        $quote['monthly']['mandatory']===true,
        'first subscription charge is D90, never confused with D0 invoice');
}
$perf=$catalog->quote('performance',['google_ads'=>70000]);
verifyP0($perf['estimated_management_fee_cents']===14000 &&
    $perf['management_fee_basis_points']===2000 &&
    $perf['media_paid_directly_to_platforms']===true,
    'R700 media minimum yields 20% R140 management fee, not a Sites product');
verifyP0(rejectedP0(static fn()=>new AsaasTrustedPaymentBinding(
    'tenant-p0','order-p0','contract-p0',1,'performance',
    'pay_123456789000','cus_123456789000','FICTIONAL-P0',14000,'PIX'
)), 'Performance never enters Onepage/Landing Sites settlement binding');
verifyP0(rejectedP0(static fn()=>new AsaasTrustedPaymentBinding(
    'tenant-p0','order-p0','contract-p0',1,'onepage',
    'pay_123456789000','cus_123456789000','FICTIONAL-P0',7990,'PIX','monthly'
)), 'recurring D90 invoice cannot masquerade as installation');

final class P0CheckoutBinding implements AsaasTrustedPaymentBindingSource
{
    public ?AsaasTrustedPaymentBinding $binding=null;
    public function findInstallationPayment(string $orderRef):?AsaasTrustedPaymentBinding {
        return $this->binding && $this->binding->orderRef===$orderRef
          ? $this->binding : null;
    }
}
final class P0CheckoutReader implements AsaasOfficialPaymentReader
{
    public ?array $record=null;
    public int $reads=0;
    public function fetchPayment(string $id):?array {
        ++$this->reads;
        return ($this->record['id']??null)===$id ? $this->record : null;
    }
}
$order='order-P0-checkout';
$binding=new P0CheckoutBinding();
$binding->binding=new AsaasTrustedPaymentBinding(
    'tenant-P0',$order,'contract-P0',1,'onepage',
    'pay_123456789000','cus_123456789000','imagine:P0:setup:1',
    33000,'CREDIT_CARD'
);
$reader=new P0CheckoutReader();
$reader->record=[
    'id'=>'pay_123456789000','customer'=>'cus_123456789000',
    'externalReference'=>'imagine:P0:setup:1',
    'billingType'=>'CREDIT_CARD','status'=>'CONFIRMED',
    'value'=>330.00,'deleted'=>false,'refunds'=>[],
    'refundedDate'=>null,'subscription'=>null,
];
verifyP0(!AsaasSandboxGateway::settled($reader->record),
    'sandbox preview CONFIRMED card must remain pending before actual RECEIVED');
$source=new AsaasVerifiedSettlementSource($binding,$reader);
verifyP0($source->findCurrentSettledOrder($order)===null,
    'operational issuer must NOT accept CONFIRMED card before RECEIVED cash availability');
$reader->record['status']='RECEIVED';
verifyP0($source->findCurrentSettledOrder($order) instanceof PortalSettlementEvidence,
    'if official current PSP responds RECEIVED, only isolated payment evidence exists');
$key=sodium_crypto_sign_secretkey(sodium_crypto_sign_keypair());
$issuer=new PortalEd25519EntitlementIssuer();
verifyP0(rejectedP0(static fn()=>$issuer->issue(
    $order,'operation-P0','idem-P0',1800000000,$key
)), 'even positive PSP receipt cannot sign Sites without independent contract and revocation');
$reader->record['refunds']=[['id'=>'synthetic-refund']];
verifyP0($source->findCurrentSettledOrder($order)===null,
    'refund after RECEIVED invalidates current PSP evidence');
$reader->record['refunds']=[];
$reader->record['status']='CONFIRMED';
verifyP0($source->findCurrentSettledOrder($order)===null,
    'status rollback to confirmed blocks proof regardless of any earlier positive evidence');

echo "$checks P0 combined quote -> sandbox preview -> independent Asaas -> Sites issuer contract guards passed.\n";
echo "NO-GO: first D0 payment does not establish full setup release policy; no sandbox account, production charge, user or site activated.\n";
