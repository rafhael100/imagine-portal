<?php
declare(strict_types=1);

namespace Imagine\Lab\OnepageBindingProducer;

use App\Homologation\Commerce\ApprovedOfferCatalog;
use App\Homologation\Commerce\BillingSchedule;
use App\Homologation\Commerce\CompositeQuotePreview;
use DateTimeImmutable;
use DateTimeZone;
use Throwable;

require_once __DIR__.'/../../app/Homologation/Commerce/ApprovedOfferCatalog.php';
require_once __DIR__.'/../../app/Homologation/Commerce/BillingSchedule.php';
require_once __DIR__.'/../../app/Homologation/Commerce/CompositeQuotePreview.php';

/** Unregistered offline shape contract. Does not attest the reader or create charges. */
final readonly class ProducerContract
{
    private function __construct(public array $scope, public array $lines, private string $fixtureCustomer) {}

    public static function prepare(array $nativeRead, string $fixtureAccount, string $fixtureCustomer): ?self
    {
        if (($nativeRead['status'] ?? null) !== 'pending_payment' || ($nativeRead['laboratory_only'] ?? null) !== true
            || ($nativeRead['settled'] ?? null) !== false || ($nativeRead['preview_admitted'] ?? null) !== false
            || !array_key_exists('binding', $nativeRead) || $nativeRead['binding'] !== null
            || !is_array($s = $nativeRead['snapshot'] ?? null)
            || ($s['status'] ?? null) !== 'pending_payment'
            || !preg_match('/\Alab-[A-Za-z0-9-]{1,60}\z/D', $fixtureAccount)
            || !preg_match('/\Acus_LAB[A-Za-z0-9]{1,60}\z/D', $fixtureCustomer)) return null;

        foreach (['id','company','quote_id'] as $field) {
            if (!is_string($s[$field] ?? null) || !preg_match('/\A[0-9a-f]{8}(?:-[0-9a-f]{4}){3}-[0-9a-f]{12}\z/D', $s[$field])) return null;
        }
        foreach (['offer_digest','contract_digest','terms_digest'] as $field) {
            if (!is_string($s[$field] ?? null) || !preg_match('/\A[a-f0-9]{64}\z/D', $s[$field])) return null;
        }
        if (!is_int($s['contract_revision'] ?? null) || $s['contract_revision'] < 1
            || !is_string($s['contract_ref'] ?? null) || !preg_match('/\Alab-[a-zA-Z0-9-]{1,100}\z/D', $s['contract_ref'])
            || !is_string($s['contract_text'] ?? null) || hash('sha256', $s['contract_text']) !== $s['contract_digest']
            || ($s['terms']['scope'] ?? null) !== 'synthetic_only' || ($s['terms']['payment_policy'] ?? null) !== 'undecided'
            || !is_string($s['accepted_at'] ?? null)) return null;

        try {
            $stamp = 'Y-m-d\TH:i:s.u\Z';
            $accepted = DateTimeImmutable::createFromFormat('!'.$stamp, $s['accepted_at'], new DateTimeZone('UTC'));
            if (!$accepted || $accepted->format($stamp) !== $s['accepted_at']) return null;
            $catalog = new ApprovedOfferCatalog();
            $q = $s['quote_snapshot'] ?? null;
            if (!is_array($q) || ($q['site'] ?? null) !== 'onepage' || ($q['channels'] ?? null) !== []
                || ($q['currency'] ?? null) !== 'BRL' || ($q['catalog_version'] ?? null) !== $catalog->version()) return null;
            $recomputed = (new CompositeQuotePreview($catalog))->quote([
                'site'=>'onepage','channels'=>[],'tier'=>$q['media_tier'] ?? null,
            ]);
            ksort($recomputed);
            ksort($q);
            if ($q !== $recomputed) return null;
            $site = $catalog->quote('onepage');
            $scope = ['order'=>$s['id'], 'company'=>$s['company'], 'quote'=>$s['quote_id'],
                'contract'=>$s['contract_ref'], 'revision'=>$s['contract_revision'], 'offer_digest'=>$s['offer_digest'],
                'terms_digest'=>$s['terms_digest'], 'contract_digest'=>$s['contract_digest'],
                'catalog_version'=>$catalog->version(), 'accepted_at'=>$s['accepted_at'], 'account'=>$fixtureAccount, 'environment'=>'sandbox', 'currency'=>'BRL'];
            $lines = [];
            foreach (BillingSchedule::project($site, $accepted->format(DATE_ATOM), 1) as $row) {
                if ($row['kind'] !== 'setup') continue;
                unset($row['kind']);
                $row['external_reference'] = 'imagine:lab-op-v1:'.hash('sha256', json_encode(
                    ['imagine.onepage.setup-reference.fixture.v1', $scope, $row], JSON_THROW_ON_ERROR));
                $lines[$row['key']] = $row;
            }
            if (array_keys($lines) !== ['setup-1','setup-2','setup-3']
                || ($q['site_setup_cents'] ?? null) !== $site['setup_total_cents']
                || ($q['illustrative_three_parts_cents'] ?? null) !== array_column($lines, 'amount_cents')) return null;
            return new self($scope, $lines, $fixtureCustomer);
        } catch (Throwable) {
            return null;
        }
    }

    /** Three parsed synthetic creation receipts, in any order. No settlement fields returned. */
    public function assemble(array $receipts): ?array
    {
        if (!array_is_list($receipts) || count($receipts) !== count($this->lines)) return null;
        $byReference = array_column($this->lines, null, 'external_reference');
        $byLine = $seenIds = [];
        foreach ($receipts as $receipt) {
            if (!is_array($receipt) || ($receipt['kind'] ?? null) !== 'synthetic_individual_creation'
                || ($receipt['account'] ?? null) !== $this->scope['account']
                || ($receipt['environment'] ?? null) !== $this->scope['environment']
                || !is_array($p = $receipt['payment'] ?? null)) return null;
            $id = $p['id'] ?? null;
            $ref = $p['externalReference'] ?? null;
            if (!is_string($id) || !preg_match('/\Apay_LAB[A-Za-z0-9]{1,60}\z/D', $id) || isset($seenIds[$id])
                || !is_string($ref) || !isset($byReference[$ref])) return null;
            $line = $byReference[$ref];
            if (isset($byLine[$line['key']]) || ($p['object'] ?? null) !== 'payment'
                || ($p['customer'] ?? null) !== $this->fixtureCustomer || ($p['dueDate'] ?? null) !== $line['due_date']
                || ($p['installment'] ?? null) !== null || ($p['installmentNumber'] ?? null) !== null
                || ($p['subscription'] ?? null) !== null || ($p['deleted'] ?? null) !== false
                || (array_key_exists('currency', $p) && $p['currency'] !== 'BRL')
                || self::cents($p['value'] ?? null) !== $line['amount_cents']) return null;
            $seenIds[$id] = true;
            $byLine[$line['key']] = $line + ['payment_id'=>$id];
        }
        return ['fixture_only'=>true, 'scope'=>$this->scope + ['customer_id'=>$this->fixtureCustomer], 'lines'=>array_replace($this->lines, $byLine)];
    }

    /** Lossless lexeme or integer input only; no coercion/rounding. */
    private static function cents(mixed $value): ?int
    {
        if (is_int($value)) return $value > 0 && $value <= 1000000 ? $value * 100 : null;
        if (!is_string($value) || !preg_match('/\A(0|[1-9][0-9]{0,6})(?:\.([0-9]{1,2}))?\z/D', $value, $m)) return null;
        return (int)$m[1] * 100 + (int)str_pad($m[2] ?? '', 2, '0');
    }
}
