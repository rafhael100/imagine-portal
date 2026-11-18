<?php
declare(strict_types=1);

namespace Imagine\Lab\OnepageBoundReceiptDurable;

use Closure;
use DateTimeImmutable;
use Imagine\Lab\OnepageBoundReceipt\FixtureLedger;
use Imagine\Lab\OnepagePaymentObservation\Observation;
use PDO;
use RuntimeException;
use function Imagine\Lab\OnepageBoundReceipt\observe;

require_once __DIR__.'/../onepage-bound-receipt-private/receipt-check.php';

/**
 * Inert until supplied three independently attested, disposable connections.
 *
 * $assertProfile: pin actual backend/role/database/cluster (no role switching).
 * $stage: bootstrap-only fixture writer, commits each variation before readers.
 * $logState: independent read-only test oracle (count and row fingerprint).
 * $bound and $exactId are independent; $exactId is the pinned fixture sentinel
 * chosen by setup, never populated from the native reread.
 */
function checkpoint(PDO $observer,PDO $writer,PDO $otherWriter,Closure $assertProfile,Closure $stage,
    Closure $logState,string $order,?array $bound,string $exactId,DateTimeImmutable $at,
    FixtureLedger $received,FixtureLedger $pending,FixtureLedger $reversed):void
{
    $test=static function(bool $ok):void { if(!$ok)throw new RuntimeException('DURABLE_FIXTURE_RECEIPT_CHECK'); };
    $test(getenv('ONEPAGE_PENDING_ORDER_LAB')==='SYNTHETIC_ONLY'
        &&$observer!==$writer&&$observer!==$otherWriter&&$writer!==$otherWriter&&$exactId==='pay_LABalpha');
    $attest=static function()use($test,$assertProfile,$observer,$writer,$otherWriter):void{
        $test(!$observer->inTransaction()&&!$writer->inTransaction()&&!$otherWriter->inTransaction()
            &&$assertProfile($observer,'onepage_fixture_receipt_observer')===true
            &&$assertProfile($writer,'onepage_fixture_receipt_writer')===true
            &&$assertProfile($otherWriter,'onepage_fixture_receipt_writer')===true);
    };
    $attest();
    $one=static function(int $v,FixtureLedger $ledger)use($stage,$order,$exactId,$test):void{
        $input=$ledger->readOnly($exactId);
        $test(is_array($input));
        unset($input['read_at']);
        $input['fixture_origin']='independent-read-scenario.v1';
        $test($stage($order,'setup-1',$v,$input)===true);
    };
    $read=static function()use($observer,$order,$test):array{
        $q=$observer->prepare('SELECT onepage_buyer_lab.fixture_observe_receipt_input(CAST(? AS uuid),?)');
        $q->execute([$order,'setup-1']);$value=$q->fetchColumn();
        $test(is_string($value));
        $record=json_decode($value,true,16,JSON_THROW_ON_ERROR);
        $test(is_array($record)&&is_int($record['version']??null)&&is_array($record['evidence']??null)
            &&is_string($record['digest']??null)&&preg_match('/\A[a-f0-9]{64}\z/D',$record['digest'])===1);
        return $record;
    };
    $append=static function(PDO $p,int $v,string $digest)use($order):string{
        $q=$p->prepare('SELECT onepage_buyer_lab.fixture_append_bound_receipt_input(CAST(? AS uuid),?,CAST(? AS bigint),?)');
        $q->execute([$order,'setup-1',$v,$digest]);
        return (string)$q->fetchColumn();
    };
    $interpret=static function(array $record)use($bound,$at,$exactId):Observation{
        $e=$record['evidence'];
        unset($e['fixture_origin']);$e['read_at']=$at; // fixture read instant, never paid_at
        return observe($bound,$at,static fn(string $id)=>hash_equals($exactId,$id)?$e:null);
    };
    $before=$logState();
    $test(is_array($before)&&($before['count']??null)===0);
    foreach ([$pending,$reversed] as $i=>$scenario) {
        $one($i+1,$scenario);
        $seen=$read();
        $test($interpret($seen)===Observation::UNVERIFIED
            && $append($writer,$seen['version'],$seen['digest'])==='denied' && $logState()===$before);
    }
    // Compare a token against a newer staged version of the same evidence.
    $one(3,$received);
    $old=$read();
    $test($interpret($old)===Observation::MATCHED_RECEIVED_FIXTURE);
    $one(4,$received);
    $now=$read();
    $test($now['version']===4&&$old['digest']===$now['digest']&&$interpret($now)===Observation::MATCHED_RECEIVED_FIXTURE
        &&$append($writer,$old['version'],$old['digest'])==='denied'&&$logState()===$before);
    $writer->beginTransaction();
    try {
        $test($append($writer,4,$now['digest'])==='recorded'&&$logState()===$before);
        $writer->rollBack();
    } finally { if($writer->inTransaction()) $writer->rollBack(); }
    $test($logState()===$before);
    $writer->beginTransaction();
    try {
        $test($append($writer,4,$now['digest'])==='recorded'&&$logState()===$before);
        $writer->commit();
    } finally { if($writer->inTransaction()) $writer->rollBack(); }
    $after=$logState();
    $test(is_array($after)&&($after['count']??null)===1&&$append($otherWriter,4,$now['digest'])==='replay'
        &&$logState()===$after);
    $one(5,$pending); // historical fixture row remains, current replay not allowed
    $test($append($otherWriter,4,$now['digest'])==='denied'&&$logState()===$after);
    $attest();
    echo "PASS ONEPAGE_FIXTURE_RECEIPT_INPUT_APPEND_ONLY_NO_PAID_AT\n";
}
