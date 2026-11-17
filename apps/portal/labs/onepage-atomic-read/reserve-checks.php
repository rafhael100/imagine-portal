<?php
declare(strict_types=1);

use App\Labs\OnepageBuyer\Guard;
use Illuminate\Database\Connection;
use Illuminate\Http\Request;
use Imagine\Lab\OnepageBindingProducer\ProducerContract;

require_once __DIR__.'/../onepage-binding-producer/ProducerContract.php';

/** Fixture hook only. Called after original runtime attestation and native reader positive. */
function checkChargeReservation(Connection $db, PDO $runtime, array $identity, Closure $pinned,
    Request $own, Request $sameCompanyOther, Request $foreign, array $first): Closure
{
    if (getenv('ONEPAGE_PENDING_ORDER_LAB') !== 'SYNTHETIC_ONLY') throw new RuntimeException('LAB_REQUIRED');
    $pinned();
    $owner = $db->getPdo();
    Guard::runtime($runtime,$owner,$identity,'onepage_buyer_runtime');
    $plan = ProducerContract::prepare($first,'lab-cas-fixture','cus_LABreserve');
    if (!$plan) throw new RuntimeException('NATIVE_PLAN_REQUIRED');
    $row = $first['snapshot'];
    $scope = array_diff_key($row,array_flip(['buyer','status','quote_snapshot','terms','contract_text']));
    $order = $row['id'];
    $session = static fn(Request $r): string => hash('sha256',$r->session()->get('portal.browser_auth')['reference']);
    $check = static function(bool $ok): void { if (!$ok) throw new RuntimeException('RESERVATION_INVARIANT'); };
    $connect = static function() use($pinned,$owner,$identity): PDO {
        $pinned();
        $p = new PDO('pgsql:host=127.0.0.1;port=55439;dbname=portal_onepage_buyer_ci','onepage_buyer_runtime','',
            [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_EMULATE_PREPARES=>false]);
        Guard::runtime($p,$owner,$identity,'onepage_buyer_runtime');
        $pinned();
        return $p;
    };
    $claim = static function(PDO $p,string $proof,string $line) use($order): bool {
        $q = $p->prepare('SELECT CASE WHEN onepage_buyer_lab.fixture_claim_charge(?,CAST(? AS uuid),?) THEN 1 ELSE 0 END');
        $q->execute([$proof,$order,$line]);
        $v = $q->fetchColumn();
        if (!in_array($v,[0,1,'0','1'],true)) throw new RuntimeException('UNEXPECTED_CAS_RESULT');
        return (int)$v===1;
    };
    $expectSqlState = static function(string $state, Closure $body): void {
        try { $body(); } catch (PDOException $e) {
            if (($e->errorInfo[0] ?? $e->getCode()) === $state) return;
            throw $e;
        }
        throw new RuntimeException('MISSING_SQL_DENIAL');
    };
    $sql = file_get_contents(__DIR__.'/reserve.sql');
    if ($sql === false) throw new RuntimeException('FIXTURE_SOURCE_REQUIRED');
    $db->unprepared($sql);
    $proof = $session($own);
    $check(!$claim($runtime,$proof,'setup-1'));
    $count = static function() use($db): int {
        return (int)$db->selectOne('SELECT count(*) n FROM onepage_buyer_lab.fixture_charge_reservations')->n;
    };

    // Bootstrap is the only trusted synthetic plan producer. All three rows
    // commit atomically; no caller capability can manufacture or reset them.
    $insert = static function(array $line) use($owner,$order,$scope,$plan): void {
        $q=$owner->prepare('INSERT INTO onepage_buyer_lab.fixture_charge_reservations
            (order_id,line,contract_scope,account,environment,customer,reference,due_date,amount_cents)
            VALUES(CAST(? AS uuid),?,CAST(? AS jsonb),?,?,?,?,CAST(? AS date),?)');
        $q->execute([$order,$line['key'],json_encode($scope,JSON_THROW_ON_ERROR),$plan->scope['account'],
            $plan->scope['environment'],'cus_LABreserve',$line['external_reference'],$line['due_date'],$line['amount_cents']]);
    };
    $pinned();
    $owner->beginTransaction();
    try {
        $insert($plan->lines['setup-1']);
        $check(!$claim($runtime,$proof,'setup-1')); // another connection cannot see partial tx
        $owner->rollBack();
    } finally {
        if ($owner->inTransaction()) $owner->rollBack();
    }
    $check($count()===0);
    $pinned();
    $owner->beginTransaction();
    try {
        foreach ($plan->lines as $line) $insert($line);
        $owner->commit();
    } finally {
        if ($owner->inTransaction()) $owner->rollBack();
    }
    $check($count()===3);
    $browserAccess = $db->selectOne("SELECT has_function_privilege('portal_browser_runtime',
        'onepage_buyer_lab.fixture_claim_charge(text,uuid,text)','EXECUTE') AS allowed")->allowed;
    $check(in_array($browserAccess,[false,'f',0,'0'],true));
    foreach ([$sameCompanyOther,$foreign] as $other) $check(!$claim($runtime,$session($other),'setup-1'));
    $check(!$claim($runtime,$proof,'monthly-1'));
    $expectSqlState('42501',static fn()=>$runtime->query('SELECT attempted FROM onepage_buyer_lab.fixture_charge_reservations'));
    $expectSqlState('42501',static fn()=>$runtime->exec('UPDATE onepage_buyer_lab.fixture_charge_reservations SET attempted=false'));

    $contender = $connect();
    $check($runtime->query('SELECT pg_backend_pid()')->fetchColumn() !== $contender->query('SELECT pg_backend_pid()')->fetchColumn());
    $contender->exec("SET lock_timeout='750ms'");
    $contender->exec("SET statement_timeout='3s'");
    $simulate = static function(PDO $p, bool $won, int &$fakeCalls): void {
        if ($p->inTransaction()) throw new RuntimeException('ATTEMPT_NOT_COMMITTED');
        if ($won) $fakeCalls++;
    };
    $fakeCalls = 0;

    // A owns an uncommitted row lock. B overlaps, cannot win or fake-send.
    $runtime->beginTransaction();
    try {
        $won = $claim($runtime,$proof,'setup-1');
        $check($won);
        $expectSqlState('55P03',static fn()=>$claim($contender,$proof,'setup-1'));
        $check($fakeCalls===0);
        $runtime->commit();
    } finally {
        if ($runtime->inTransaction()) $runtime->rollBack();
    }
    $simulate($runtime,$won,$fakeCalls);
    $check($fakeCalls===1);
    $check(!$claim($contender,$proof,'setup-1'));

    // Simulate losing a connection after commit but before any send: a fresh
    // connection sees the persisted latch and does not reacquire a send.
    $check($claim($contender,$proof,'setup-2'));
    $contender = null;
    $fresh = $connect();
    $check(!$claim($fresh,$proof,'setup-2') && $fakeCalls===1);

    // If the transaction never committed, no pre-send capability escaped.
    $fresh->beginTransaction();
    try {
        $check($claim($fresh,$proof,'setup-3'));
        $fresh->rollBack();
    } finally {
        if ($fresh->inTransaction()) $fresh->rollBack();
    }
    // A different connection can still acquire the rolled-back line. Leave
    // it unattempted to prove membership revocation, rather than latch denial.
    $runtime->beginTransaction();
    try {
        $check($claim($runtime,$proof,'setup-3'));
        $runtime->rollBack();
    } finally {
        if ($runtime->inTransaction()) $runtime->rollBack();
    }
    return static function() use($check,$claim,$runtime,$proof,$pinned,$owner,$identity): void {
        $pinned();
        Guard::runtime($runtime,$owner,$identity,'onepage_buyer_runtime');
        $check(!$claim($runtime,$proof,'setup-3'));
        $q = $owner->query('SELECT count(*) FROM onepage_buyer_lab.fixture_charge_reservations WHERE attempted');
        $check((int)$q->fetchColumn()===2);
        echo "PASS RESERVATION_CAS: durable single attempt, guarded scope, no release\n";
    };
}
