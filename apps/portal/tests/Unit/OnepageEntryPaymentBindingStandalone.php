<?php

declare(strict_types=1);

// Pure integration of the actual native DTO, entry adapter, Asaas verifier and
// issuer. All source implementations below are explicitly test fixtures; no I/O.
spl_autoload_register(static function (string $class): void {
    if (!str_starts_with($class, 'App\\')) return;
    $file = dirname(__DIR__, 2).'/app/'.str_replace('\\', '/', substr($class, 4)).'.php';
    if (is_file($file)) require_once $file;
});

use App\Domain\Commerce\{OnepageAcceptedOrder, OnepageOrderReader};
use App\Domain\SitesIntegration\{
    AsaasOfficialPaymentReader, AsaasTrustedPaymentBinding, AsaasTrustedPaymentBindingSource,
    AsaasVerifiedSettlementSource, OnepageEntryPaymentBindingSource, OnepageEntryPaymentTerms,
    PortalCurrentRevocationEpochSource, PortalEd25519EntitlementIssuer,
    PortalSettlementEvidence, PortalSiteContractSource, SiteContractSnapshot, SitesCreateProjectEncoder,
};
use App\Homologation\Commerce\{ApprovedOfferCatalog, BillingSchedule, CompositeQuotePreview};

$checks = 0;
function entryCheck(bool $ok, string $message): void
{
    global $checks;
    if (!$ok) throw new RuntimeException('FAIL '.$message);
    ++$checks;
}
function entryDenied(callable $action): bool
{
    try { $action(); return false; } catch (DomainException) { return true; }
}
function entryOrder(array $replace = []): OnepageAcceptedOrder
{
    $catalog = new ApprovedOfferCatalog();
    $text = 'Accepted native Onepage contract fixture for code verification only.';
    $values = [
        'id' => '10000000-0000-4000-8000-000000000001',
        'quoteId' => '20000000-0000-4000-8000-000000000001',
        'companyId' => '30000000-0000-4000-8000-000000000001',
        'buyerId' => '40000000-0000-4000-8000-000000000001',
        'companyReference' => 'portal-org-entry-a', 'publicationRef' => 'onepage-publication-entry-v1',
        'quoteSnapshot' => (new CompositeQuotePreview())->quote(['site' => 'onepage', 'channels' => [], 'tier' => 'minimum']),
        'catalogVersion' => $catalog->version(), 'offerDigest' => hash('sha256', 'native-offer-jsonb-fixture'),
        'terms' => ['scope' => 'onepage', 'payment_policy' => 'onepage-d0-d90-v1',
            'onepage_payment_policy' => OnepageEntryPaymentTerms::requiredPolicy($catalog)],
        'termsDigest' => hash('sha256', 'native-terms-jsonb-fixture'),
        'contractRef' => 'onepage-contract-entry-v1', 'contractRevision' => 1,
        'contractText' => $text, 'contractDigest' => hash('sha256', $text),
        'acceptedAt' => '2026-11-18T01:20:00.000000Z',
    ];
    return new OnepageAcceptedOrder(...array_replace($values, $replace));
}

final class EntryOrders implements OnepageOrderReader
{
    public ?OnepageAcceptedOrder $order = null;
    public function findOwnedAcceptedOrder(string $businessReference, string $orderId): ?OnepageAcceptedOrder
    { return $businessReference === str_repeat('a', 64) && $this->order?->id === $orderId ? $this->order : null; }
}
final class EntryContracts implements PortalSiteContractSource
{
    public ?SiteContractSnapshot $contract = null;
    public function findAuthorizedSiteOrder(string $orderRef): ?SiteContractSnapshot
    { return $this->contract?->orderRef === $orderRef ? $this->contract : null; }
}
final class EntryBindings implements AsaasTrustedPaymentBindingSource
{
    public ?AsaasTrustedPaymentBinding $binding = null;
    public function findInstallationPayment(string $orderRef): ?AsaasTrustedPaymentBinding
    { return $this->binding?->orderRef === $orderRef ? $this->binding : null; }
}
final class EntryOfficialReader implements AsaasOfficialPaymentReader
{
    public ?array $record = null;
    public ?Closure $duringRead = null;
    public int $reads = 0;
    public function fetchPayment(string $paymentId): ?array
    {
        ++$this->reads;
        if ($this->duringRead !== null) ($this->duringRead)();
        return ($this->record['id'] ?? null) === $paymentId ? $this->record : null;
    }
}
final class EntryRevocations implements PortalCurrentRevocationEpochSource
{
    public ?int $epoch = 1;
    public function readCurrentActiveEpoch(string $tenantRef, string $orderRef, string $contractRef, int $contractRevision): ?int
    { return $this->epoch; }
}

$order = entryOrder();
$orders = new EntryOrders(); $orders->order = $order;
$contracts = new EntryContracts();
$contracts->contract = new SiteContractSnapshot($order->companyReference, $order->id, $order->contractRef, 1, 'onepage');
$bindings = new EntryBindings();
$account = 'sandbox-entry-account'; $customer = 'cus_Entry000001';
$reference = OnepageEntryPaymentTerms::reference($order, $account, $customer);
$schedule = OnepageEntryPaymentTerms::schedule($order);
entryCheck($schedule !== null && array_column($schedule, 'amount_cents') === [33000, 33000, 33000], 'entry is 33000; remaining installments stay in the schedule');
entryCheck(array_column($schedule, 'due_date') === ['2026-11-17', '2026-12-17', '2027-01-16'], 'D0 D30 D60 use accepted date in Sao Paulo');
$monthly = BillingSchedule::project((new ApprovedOfferCatalog())->quote('onepage'), $order->acceptedAt, 1)[3];
entryCheck($monthly['amount_cents'] === 7990 && $monthly['due_date'] === '2027-02-15', 'D90 monthly remains independent of entry and publication');
entryCheck(is_string($reference) && str_starts_with($reference, 'imagine:onepage:setup-1:'), 'entry has explicit scoped reference');
$line2 = OnepageEntryPaymentTerms::reference($order, $account, $customer, 'setup-2');
entryCheck($line2 !== null && $line2 !== $reference, 'equal-price installment 2 has a different reference');
$good = new AsaasTrustedPaymentBinding($order->companyReference, $order->id, $order->contractRef, 1,
    'onepage', 'pay_Entry000001', $customer, $reference, 33000, 'CREDIT_CARD');
$bindings->binding = $good;
$source = new OnepageEntryPaymentBindingSource($orders, str_repeat('a', 64), $account, $contracts, $bindings);
entryCheck($source->findInstallationPayment($order->id) === $good, 'trusted native setup-1 binding admitted');
entryCheck((new OnepageEntryPaymentBindingSource($orders, str_repeat('a', 64), $account))->findInstallationPayment($order->id) === null, 'missing contract/payment authorities deny by default');
entryCheck((new OnepageEntryPaymentBindingSource($orders, str_repeat('b', 64), $account, $contracts, $bindings))->findInstallationPayment($order->id) === null, 'different native buyer denied');

foreach ([
    ['externalReference' => $line2],
    ['externalReference' => OnepageEntryPaymentTerms::reference($order, 'other-account', $customer)],
    ['customerId' => 'cus_Another001'],
    ['tenantRef' => 'portal-org-other'],
    ['tenantRef' => $order->companyId],
    ['contractRef' => 'other-contract'],
    ['contractRevision' => 2],
    ['amountCents' => 99000],
    ['amountCents' => 32999],
] as $replacement) {
    $bindings->binding = new AsaasTrustedPaymentBinding(...array_replace(get_object_vars($good), $replacement));
    entryCheck($source->findInstallationPayment($order->id) === null, 'binding scope mismatch denied: '.implode(',', array_keys($replacement)));
}
$bindings->binding = $good;
foreach ([
    ['offerDigest' => hash('sha256', 'other-offer')],
    ['termsDigest' => hash('sha256', 'other-terms')],
    ['publicationRef' => 'onepage-other-publication'],
    ['acceptedAt' => '2026-11-19T01:20:00.000000Z'],
    ['companyReference' => 'portal-org-other'],
    ['terms' => ['scope' => 'onepage', 'payment_policy' => 'onepage-d0-d90-v1']],
] as $replacement) {
    $orders->order = entryOrder($replacement);
    entryCheck($source->findInstallationPayment($order->id) === null, 'native accepted context mismatch denied: '.implode(',', array_keys($replacement)));
}
$orders->order = $order;
$contracts->contract = null;
entryCheck($source->findInstallationPayment($order->id) === null, 'missing or revoked contract denies');
$contracts->contract = new SiteContractSnapshot($order->companyReference, $order->id, $order->contractRef, 1, 'onepage');

$reader = new EntryOfficialReader();
$reader->record = ['id' => $good->paymentId, 'customer' => $good->customerId,
    'externalReference' => $good->externalReference, 'billingType' => 'CREDIT_CARD',
    'status' => 'RECEIVED', 'value' => '330.00', 'deleted' => false, 'refunds' => [], 'subscription' => null];
$settlements = new AsaasVerifiedSettlementSource($source, $reader);
entryCheck($settlements->findCurrentSettledOrder($order->id) instanceof PortalSettlementEvidence, 'official RECEIVED entry yields only that payment evidence');
foreach ([['status' => 'CONFIRMED'], ['value' => '329.99'], ['customer' => 'cus_Another001'],
    ['externalReference' => $line2], ['refunds' => [['id' => 'refund-entry']]], ['subscription' => 'sub_Monthly001']] as $change) {
    $original = $reader->record;
    $reader->record = array_replace($original, $change);
    entryCheck($settlements->findCurrentSettledOrder($order->id) === null, 'official payment mismatch denied: '.implode(',', array_keys($change)));
    $reader->record = $original;
}
$reader->duringRead = static function () use ($orders): void { $orders->order = null; };
entryCheck($settlements->findCurrentSettledOrder($order->id) === null, 'native authority revoked during GET denies on reread');
$reader->duringRead = null; $orders->order = $order;
$reader->duringRead = static function () use ($bindings): void { $bindings->binding = null; };
entryCheck($settlements->findCurrentSettledOrder($order->id) === null, 'binding revoked during GET denies on reread');
$reader->duringRead = null; $bindings->binding = $good;

$keypair = sodium_crypto_sign_keypair(); $key = sodium_crypto_sign_secretkey($keypair);
$revocations = new EntryRevocations();
$issuer = new PortalEd25519EntitlementIssuer($contracts, $settlements, $revocations);
$proof = $issuer->issue($order->id, 'entry-operation', 'entry-idempotency', 1800000000, $key);
$signature = hex2bin($proof['signature']); unset($proof['signature']);
entryCheck(sodium_crypto_sign_verify_detached($signature, json_encode($proof,
    JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), sodium_crypto_sign_publickey($keypair)),
    'existing Ed25519 issuer verifies the entry proof without full settlement');
$command = (new SitesCreateProjectEncoder($contracts))->build($order->id, 'entry-operation', 'entry-idempotency');
entryCheck($command['schemaVersion'] === '1' && $command['tenantRef'] === $order->companyReference
    && !array_key_exists('paid', $command) && !array_key_exists('canPublish', $command), 'CreateProject v1 remains organization-scoped without payment/publication flags');
$revocations->epoch = null;
entryCheck(entryDenied(static fn() => $issuer->issue($order->id, 'entry-operation', 'entry-idempotency', 1800000000, $key)), 'missing independent revocation authority denies issuer');
entryCheck(entryDenied(static fn() => (new PortalEd25519EntitlementIssuer())->issue($order->id, 'entry-operation', 'entry-idempotency', 1800000000, $key)), 'unwired issuer remains default deny');

echo $checks." native Onepage entry binding / Asaas / CreateProject assertions passed; no PSP contacted.\n";
