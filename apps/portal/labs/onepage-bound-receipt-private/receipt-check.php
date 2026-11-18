<?php
declare(strict_types=1);

namespace Imagine\Lab\OnepageBoundReceipt;

use Closure;
use DateTimeImmutable;
use DateTimeZone;
use Imagine\Lab\OnepageBindingProducer\ProducerContract;
use Imagine\Lab\OnepagePaymentObservation\{BoundPaymentFixture,Observation};
use RuntimeException;

require_once __DIR__.'/../onepage-payment-observation/Observation.php';

/** In-memory substitute for an independent read-only ledger. Not a runtime identity. */
final readonly class FixtureLedger
{
    private function __construct(private string $paymentId,private array $entry) {}

    /** Prepared by fixture setup, from an accepted plan and its separate source scenario;
     * never from the SQL binding/result. This fixture cannot establish paid_at. */
    public static function stage(ProducerContract $plan,DateTimeImmutable $readAt,
        string $status,string $reversal):self
    {
        $p=$plan->scope;$line=$plan->lines['setup-1']??null;
        if ($line===null||$p['account']!=='lab-cas-fixture'||$p['environment']!=='sandbox'
            ||$p['currency']!=='BRL'||!in_array($status,['RECEIVED','PENDING'],true)
            ||!in_array($reversal,['fixture_verified_clear','fixture_reversed','unknown'],true))
            throw new RuntimeException('OFFLINE_LEDGER_SCENARIO_REQUIRED');
        // Known independently from the synthetic #926 setup—not copied from the observed row.
        $id='pay_LABalpha';
        $scope=['company'=>$p['company'],'order_id'=>$p['order'],'line'=>'setup-1','quote_id'=>$p['quote'],
            'contract_ref'=>$p['contract'],'contract_revision'=>$p['revision'],'offer_digest'=>$p['offer_digest'],
            'terms_digest'=>$p['terms_digest'],'contract_digest'=>$p['contract_digest'],'catalog_version'=>$p['catalog_version'],
            'account'=>$p['account'],'environment'=>$p['environment'],'reference'=>$line['external_reference'],
            'customer'=>'cus_LABreserve','currency'=>$p['currency'],'amount_cents'=>$line['amount_cents'],'due_date'=>$line['due_date']];
        return new self($id,['scope'=>$scope,'read_at'=>$readAt,'reversal'=>$reversal,'payment'=>[
            'object'=>'payment','id'=>$id,'customer'=>'cus_LABreserve','externalReference'=>$line['external_reference'],
            'currency'=>'BRL','value_cents'=>$line['amount_cents'],'dueDate'=>$line['due_date'],
            'status'=>$status,'deleted'=>false,'subscription'=>null]]);
    }
    public function readOnly(string $exactId):?array
    {
        return hash_equals($this->paymentId,$exactId)?$this->entry:null;
    }
    public function __debugInfo():array { return ['fixture_only'=>true]; }
    public function __serialize():array { throw new RuntimeException('PRIVATE_FIXTURE_LEDGER'); }
}

/** Checkpoint after the second guarded buyer backend rereads the committed binding.
 * The observer receives only the normalized record and an independent narrow callback;
 * no PDO, buyer/MFA, bootstrap, arbitrary filters, save operation, signing or release. */
function observe(?array $bound,DateTimeImmutable $asOf,Closure $read):Observation
{
    if ($bound!==null) {
        $time=$bound['bound_at']??null;$utc=new DateTimeZone('UTC');
        $date=is_string($time)?DateTimeImmutable::createFromFormat('!Y-m-d\TH:i:s.u\Z',$time,$utc):false;
        if (!$date||$date->format('Y-m-d\TH:i:s.u\Z')!==$time) return Observation::UNVERIFIED;
        $bound['bound_at']=$date;
    }
    return BoundPaymentFixture::inspect($bound,$asOf,$read);
}

/** Integrated fixture assertions. The caller stages independent read-only inputs
 * before handing over the readback. No fixture evidence or IDs are printed. */
function check(?array $bound,FixtureLedger $receipt,FixtureLedger $pending,FixtureLedger $reversed,
    FixtureLedger $unknown,DateTimeImmutable $asOf):void
{
    $requests=0;
    $one=static function(string $id)use($receipt,&$requests):?array{
        ++$requests;
        return $receipt->readOnly($id);
    };
    if (observe(null,$asOf,$one)!==Observation::UNVERIFIED || $requests!==0
        || observe($bound,$asOf,$one)!==Observation::MATCHED_RECEIVED_FIXTURE || $requests!==1
        || observe($bound,$asOf,$pending->readOnly(...))!==Observation::UNVERIFIED
        || observe($bound,$asOf,$reversed->readOnly(...))!==Observation::UNVERIFIED
        || observe($bound,$asOf,$unknown->readOnly(...))!==Observation::UNVERIFIED)
        throw new RuntimeException('FIXTURE_RECEIPT_INPUT_READ_REQUIRED');
    // Deliberately no eligibility result: there is no authoritative paid_at.
    echo "PASS BOUND_RECEIPT_INPUT_FIXTURE_MATCH_ONLY\n";
}
