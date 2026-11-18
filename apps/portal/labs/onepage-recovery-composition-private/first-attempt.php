<?php
declare(strict_types=1);

use App\Labs\OnepageBuyer\Guard;
use Imagine\Lab\OnepageBindingProducer\ProducerContract;

/** Private fixture call. No auth payload from browser; no capability returned to caller.
 * Hooks and transport are fixtures; fixtureLoseAcknowledgement discards a
 * successful COMMIT result after COMMIT, not a reproduced network fault. */
function runFixtureFirstAttempt(PDO $writer,PDO $reader,PDO $bootstrap,array $identity,Closure $pinned,
    ProducerContract $plan,string $proof,string $line,Closure $fakeTransport,
    ?Closure $beforeCommit=null,bool $fixtureLoseAcknowledgement=false): string
{
    if (getenv('ONEPAGE_PENDING_ORDER_LAB')!=='SYNTHETIC_ONLY'
        || !isset($plan->lines[$line])) return 'denied';
    $pinned();
    foreach ([$writer,$reader] as $p) Guard::runtime($p,$bootstrap,$identity,'onepage_buyer_runtime');
    if ($writer->query('SELECT pg_backend_pid()')->fetchColumn()===$reader->query('SELECT pg_backend_pid()')->fetchColumn())
        return 'denied';
    $order=$plan->scope['order'];
    $read=static function(PDO $p)use($proof,$order,$plan,$line): ?array {
        $q=$p->prepare('SELECT onepage_buyer_lab.fixture_read_attempt_plan(?,CAST(? AS uuid))');
        $q->execute([$proof,$order]);
        $raw=$q->fetchColumn();
        if (!is_string($raw)) return null;
        $items=json_decode($raw,true,16,JSON_THROW_ON_ERROR);
        if (!is_array($items)||!array_is_list($items)||count($items)!==count($plan->lines)) return null;
        $attempt=null;
        foreach (array_values($plan->lines) as $i=>$row) {
            $x=$items[$i];
            if (!is_array($x)||!is_bool($x['attempted']??null) || !is_string($x['tuple_xid']??null)
                || preg_match('/\A[0-9]{1,10}\z/D',$x['tuple_xid'])!==1) return null;
            if (($x['key']??null)===$line) $attempt=[$x['attempted'],$x['tuple_xid']];
            unset($x['attempted'],$x['tuple_xid']);
            $expected=$row+['account'=>$plan->scope['account'],'environment'=>'sandbox','customer'=>'cus_LABreserve'];
            ksort($x); ksort($expected);
            if ($x!==$expected) return null;
        }
        return $attempt;
    };
    $reconcile=static function()use($read,$reader,$pinned,$bootstrap,$identity): string {
        try {
            $pinned(); Guard::runtime($reader,$bootstrap,$identity,'onepage_buyer_runtime');
            return ($read($reader)[0]??null)===true?'reconcile_only':'denied';
        } catch (Throwable) { return 'denied'; }
    };
    // Low 32 bits match PostgreSQL tuple xmin. This is correlation only in
    // the pinned short-lived database; never an authorization token or actor ID.
    $xid=static function(PDO $p): ?string {
        $v=$p->query("SELECT mod(pg_current_xact_id()::text::numeric,4294967296)::text")->fetchColumn();
        return is_string($v) && preg_match('/\A[0-9]{1,10}\z/D',$v)===1?$v:null;
    };
    $opened=false;
    try {
        if ($writer->inTransaction() || $reader->inTransaction() || !$writer->beginTransaction()) return 'denied';
        $opened=true;
        $thisAttempt=$read($writer);
        if (($thisAttempt[0]??null)!==false) {
            $writer->rollBack();
            return ($thisAttempt[0]??null)===true?$reconcile():'denied';
        }
        $tx=$xid($writer);
        if ($tx===null) throw new RuntimeException('FIXTURE_XID_REQUIRED');
        $q=$writer->prepare('SELECT CASE WHEN onepage_buyer_lab.fixture_claim_charge(?,CAST(? AS uuid),?) THEN 1 ELSE 0 END');
        $q->execute([$proof,$order,$line]);
        if (!in_array($q->fetchColumn(),[1,'1'],true)) {
            $writer->rollBack();
            return $reconcile();
        }
        if ($beforeCommit!==null) $beforeCommit(); // test only
        if (!$writer->inTransaction() || $xid($writer)!==$tx || $read($writer)!==[true,$tx])
            throw new RuntimeException('FIXTURE_CAS_TRANSACTION_CHANGED');
        if (!$writer->commit()) return $reconcile();
        if ($fixtureLoseAcknowledgement) return $reconcile(); // after successful COMMIT only
        $pinned();
        Guard::runtime($reader,$bootstrap,$identity,'onepage_buyer_runtime');
        if ($reader->inTransaction() || $read($reader)!==[true,$tx]) return $reconcile();
    } catch (Throwable) {
        if ($opened) {
            try { if ($writer->inTransaction()) $writer->rollBack(); } catch (Throwable) {}
        }
        return $reconcile();
    }
    try {
        $fakeTransport($plan->lines[$line]); // exactly once; no authorization output
        return 'fixture_send';
    } catch (Throwable) { return 'reconcile_only'; }
}
