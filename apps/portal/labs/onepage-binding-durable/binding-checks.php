<?php
declare(strict_types=1);

use App\Labs\OnepageBuyer\Guard;
use Illuminate\Database\Connection;
use Illuminate\Http\Request;
use Imagine\Lab\OnepageBindingProducer\ProducerContract;
require_once __DIR__.'/../onepage-binding-producer/ProducerContract.php';

/** Called only from the separately composed, guarded #925 runner. */
function checkDurableFixtureBinding(Connection $db, PDO $runtime, array $identity, Closure $pinned,
    Request $own, Request $sameCompanyOther, Request $foreign, array $first): Closure
{
    if (getenv('ONEPAGE_PENDING_ORDER_LAB')!=='SYNTHETIC_ONLY') throw new RuntimeException('LAB_REQUIRED');
    $pinned();
    $owner=$db->getPdo();
    Guard::runtime($runtime,$owner,$identity,'onepage_buyer_runtime');
    $plan=ProducerContract::prepare($first,'lab-cas-fixture','cus_LABreserve');
    if (!$plan) throw new RuntimeException('NATIVE_PLAN_REQUIRED');
    // Independently project the already-attested native snapshot, not ledger rows.
    $row=$first['snapshot'];
    $scope=array_diff_key($row,array_flip(['buyer','status','quote_snapshot','terms','contract_text']));
    $order=$row['id'];
    $check=static function(bool $v): void { if (!$v) throw new RuntimeException('BINDING_INVARIANT'); };
    $session=static fn(Request $r): string => hash('sha256',$r->session()->get('portal.browser_auth')['reference']);
    $proof=$session($own);
    $bind=static function(PDO $p,string $sessionHash,string $line,string $probe) use($order): string {
        $q=$p->prepare('SELECT onepage_buyer_lab.fixture_bind_charge(?,CAST(? AS uuid),?,?)');
        $q->execute([$sessionHash,$order,$line,$probe]);
        $result=$q->fetchColumn();
        if (!in_array($result,['denied','pending','conflict','linked','replay'],true)) throw new RuntimeException('BINDING_RESULT');
        return $result;
    };
    $expect=static function(string $state,Closure $body) use($check): void {
        try { $body(); } catch (PDOException $e) { $check(($e->errorInfo[0]??$e->getCode())===$state); return; }
        throw new RuntimeException('MISSING_DATABASE_DENIAL');
    };
    $count=static function()use($db): int {
        return (int)$db->selectOne('SELECT count(*) n FROM onepage_buyer_lab.fixture_charge_bindings')->n;
    };

    $sql=file_get_contents(__DIR__.'/binding.sql');
    if ($sql===false) throw new RuntimeException('BINDING_SQL_SOURCE');
    $db->unprepared($sql);
    $pinned();
    $owner->beginTransaction();
    try {
        $q=$owner->prepare('INSERT INTO onepage_buyer_lab.fixture_binding_witnesses
           (probe,order_id,line,contract_scope,account,environment,customer,reference,currency,amount_cents,due_date,payment_id)
           VALUES(?,CAST(? AS uuid),?,CAST(? AS jsonb),?,?,?,?,?, ?,CAST(? AS date),?)');
        foreach ([
            ['probe-first','setup-1','pay_LABalpha',0],['probe-dissent','setup-1','pay_LABbeta',0],
            ['probe-cross','setup-2','pay_LABalpha',0],['probe-fresh','setup-2','pay_LABgamma',0],
            ['probe-amount','setup-1','pay_LABalpha',-1],['probe-untouched','setup-3','pay_LABdelta',0],
        ] as [$probe,$key,$id,$delta]) {
            $line=$plan->lines[$key];
            $q->execute([$probe,$order,$key,json_encode($scope,JSON_THROW_ON_ERROR),$plan->scope['account'],
                $plan->scope['environment'],'cus_LABreserve',$line['external_reference'],'BRL',
                $line['amount_cents']+$delta,$line['due_date'],$id]);
        }
        $owner->commit();
    } finally {
        if ($owner->inTransaction()) $owner->rollBack();
    }

    $check($bind($runtime,$proof,'setup-1','probe-missing')==='pending');
    $check($bind($runtime,$proof,'setup-1','probe-amount')==='conflict');
    $check($bind($runtime,$proof,'setup-1','probe-fresh')==='conflict');
    $check($bind($runtime,$proof,'setup-3','probe-untouched')==='denied');
    foreach ([$sameCompanyOther,$foreign] as $other) $check($bind($runtime,$session($other),'setup-1','probe-first')==='denied');
    foreach (['fixture_binding_witnesses','fixture_charge_bindings'] as $t) {
        $expect('42501',static fn()=>$runtime->query('SELECT count(*) FROM onepage_buyer_lab.'.$t));
        $expect('42501',static fn()=>$runtime->exec('DELETE FROM onepage_buyer_lab.'.$t));
    }
    $check($count()===0);

    $pinned();
    $b=new PDO('pgsql:host=127.0.0.1;port=55439;dbname=portal_onepage_buyer_ci','onepage_buyer_runtime','',
        [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_EMULATE_PREPARES=>false]);
    Guard::runtime($b,$owner,$identity,'onepage_buyer_runtime');
    $check($runtime->query('SELECT pg_backend_pid()')->fetchColumn()!==$b->query('SELECT pg_backend_pid()')->fetchColumn());
    $b->exec("SET lock_timeout='750ms'");
    $b->exec("SET statement_timeout='3s'");
    $pinned();
    $owner->exec("SET lock_timeout='750ms'");
    try {
        $runtime->beginTransaction();
        try {
            $check($bind($runtime,$proof,'setup-1','probe-first')==='linked');
            $check($count()===0);
            // Live membership revocation must serialize with the native read.
            $expect('55P03', static function() use($owner,$row): void {
                $q=$owner->prepare("UPDATE public.portal_memberships SET status='suspended',
                   membership_revision=membership_revision+1 WHERE identity_id=CAST(? AS uuid) AND organization_id=CAST(? AS uuid)");
                $q->execute([$row['buyer'],$row['company']]);
            });
            $runtime->rollBack();
        } finally {
            if ($runtime->inTransaction()) $runtime->rollBack();
        }
    } finally {
        $owner->exec('RESET lock_timeout');
    }
    $check($count()===0);

    $runtime->beginTransaction();
    try {
        $check($bind($runtime,$proof,'setup-1','probe-first')==='linked');
        $expect('55P03',static fn()=>$bind($b,$proof,'setup-1','probe-first'));
        $runtime->commit();
    } finally {
        if ($runtime->inTransaction()) $runtime->rollBack();
    }
    $check($bind($b,$proof,'setup-1','probe-first')==='replay');
    $check($bind($b,$proof,'setup-1','probe-dissent')==='conflict');
    $check($bind($b,$proof,'setup-2','probe-cross')==='conflict');
    $exact=$db->selectOne('SELECT order_id,line,account,environment,payment_id
       FROM onepage_buyer_lab.fixture_charge_bindings');
    $check($count()===1 && $exact->order_id===$order && $exact->line==='setup-1'
        && $exact->account===$plan->scope['account'] && $exact->environment==='sandbox'
        && $exact->payment_id==='pay_LABalpha');

    // Runs after the existing #925 runner commits native membership revocation.
    // setup-2 is attempted but unbound, and its matching distinct ID was not used.
    return static function()use($check,$bind,$b,$proof,$count,$pinned,$owner,$identity): void {
        $pinned();
        Guard::runtime($b,$owner,$identity,'onepage_buyer_runtime');
        $check($bind($b,$proof,'setup-2','probe-fresh')==='denied');
        $check($bind($b,$proof,'setup-1','probe-first')==='denied' && $count()===1);
        echo "PASS BINDING_DURABLE: one exact link, rollback and revocation, no release\n";
    };
}
