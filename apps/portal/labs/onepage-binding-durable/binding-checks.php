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
    $bind=static function(PDO $p,string $sessionHash,string $line) use($order): string {
        $q=$p->prepare('SELECT onepage_buyer_lab.fixture_bind_charge(?,CAST(? AS uuid),?)');
        $q->execute([$sessionHash,$order,$line]);
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
    $acl=$db->selectOne("SELECT CASE WHEN pg_get_userbyid(p.proowner)='onepage_buyer_owner' AND p.prosecdef
          AND has_function_privilege('onepage_buyer_runtime',p.oid,'EXECUTE')
          AND NOT has_function_privilege('portal_browser_runtime',p.oid,'EXECUTE')
          AND NOT EXISTS (SELECT 1 FROM aclexplode(COALESCE(p.proacl,acldefault('f',p.proowner))) a
              WHERE a.privilege_type='EXECUTE' AND a.grantee NOT IN
                    (p.proowner,(SELECT oid FROM pg_roles WHERE rolname='onepage_buyer_runtime')))
          AND (SELECT count(*) FROM pg_class c WHERE c.oid IN
                    ('onepage_buyer_lab.fixture_binding_witnesses'::regclass,
                     'onepage_buyer_lab.fixture_charge_bindings'::regclass)
                   AND c.relrowsecurity AND c.relforcerowsecurity)=2 THEN 1 ELSE 0 END AS permitted
          FROM pg_proc p WHERE p.oid='onepage_buyer_lab.fixture_bind_charge(text,uuid,text)'::regprocedure");
    $check($acl!==null && (int)$acl->permitted===1);
    // Only bootstrap changes dataset between negative control phases.
    // Keys below are test administration labels, never runtime arguments.
    $seed=static function(string $probe,string $key,string $id,int $delta=0)use($owner,$pinned,$order,$plan,$scope): void {
        $pinned();
        $q=$owner->prepare('INSERT INTO onepage_buyer_lab.fixture_binding_witnesses
           (probe,order_id,line,contract_scope,account,environment,customer,reference,currency,amount_cents,due_date,payment_id)
           VALUES(?,CAST(? AS uuid),?,CAST(? AS jsonb),?,?,?,?,?, ?,CAST(? AS date),?)');
        $line=$plan->lines[$key];
        $q->execute([$probe,$order,$key,json_encode($scope,JSON_THROW_ON_ERROR),$plan->scope['account'],
            $plan->scope['environment'],'cus_LABreserve',$line['external_reference'],'BRL',
            $line['amount_cents']+$delta,$line['due_date'],$id]);
    };
    $remove=static function(string $probe)use($owner,$pinned,$check): void {
        $pinned();
        $q=$owner->prepare('DELETE FROM onepage_buyer_lab.fixture_binding_witnesses WHERE probe=?');
        $q->execute([$probe]);
        $check($q->rowCount()===1);
    };
    $latches=static function()use($db): array {
        return array_map(static fn($r): array => [$r->line,$r->attempted],$db->select(
            'SELECT line,attempted FROM onepage_buyer_lab.fixture_charge_reservations ORDER BY line'));
    };
    $beforeLatches=$latches();
    $check($bind($runtime,$proof,'setup-1')==='pending');
    $seed('probe-amount','setup-1','pay_LABalpha',-1);
    $check($bind($runtime,$proof,'setup-1')==='conflict' && $count()===0);
    $remove('probe-amount');

    $seed('probe-first','setup-1','pay_LABalpha');
    $seed('probe-dissent','setup-1','pay_LABbeta');
    $check($bind($runtime,$proof,'setup-1')==='conflict' && $count()===0 && $latches()===$beforeLatches);
    $remove('probe-dissent'); // Restore uniquely eligible evidence, not a latch reset.
    $seed('probe-cross','setup-2','pay_LABalpha');
    $seed('probe-untouched','setup-3','pay_LABdelta');
    $check($bind($runtime,$proof,'setup-3')==='denied');
    foreach ([$sameCompanyOther,$foreign] as $other) $check($bind($runtime,$session($other),'setup-1')==='denied');
    $expect('42883',static fn()=>$runtime->query(
        "SELECT onepage_buyer_lab.fixture_bind_charge(NULL::text,NULL::uuid,NULL::text,NULL::text)"));
    foreach (['fixture_binding_witnesses','fixture_charge_bindings'] as $t) {
        $expect('42501',static fn()=>$runtime->query('SELECT count(*) FROM onepage_buyer_lab.'.$t));
        $expect('42501',static fn()=>$runtime->exec('DELETE FROM onepage_buyer_lab.'.$t));
        $expect('42501',static fn()=>$runtime->exec('INSERT INTO onepage_buyer_lab.'.$t.' DEFAULT VALUES'));
        $expect('42501',static fn()=>$runtime->exec('UPDATE onepage_buyer_lab.'.$t." SET payment_id='pay_LABdeny'"));
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
            $check($bind($runtime,$proof,'setup-1')==='linked');
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
        $check($bind($runtime,$proof,'setup-1')==='linked');
        $expect('55P03',static fn()=>$bind($b,$proof,'setup-1'));
        $runtime->commit();
    } finally {
        if ($runtime->inTransaction()) $runtime->rollBack();
    }
    $check($bind($b,$proof,'setup-1')==='replay');
    $seed('probe-dissent','setup-1','pay_LABbeta');
    $check($bind($b,$proof,'setup-1')==='conflict' && $count()===1); // ambiguity post-link
    $remove('probe-first');
    $check($bind($b,$proof,'setup-1')==='conflict' && $count()===1); // sole different ID
    $remove('probe-dissent');
    $seed('probe-first','setup-1','pay_LABalpha');
    $check($bind($b,$proof,'setup-1')==='replay');
    $check($bind($b,$proof,'setup-2')==='conflict'); // same ID for another line
    $remove('probe-cross');
    $seed('probe-fresh','setup-2','pay_LABgamma');
    $check($latches()===$beforeLatches);
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
        $check($bind($b,$proof,'setup-2')==='denied');
        $check($bind($b,$proof,'setup-1')==='denied' && $count()===1);
        echo "PASS BINDING_DURABLE: one exact link, rollback and revocation, no release\n";
    };
}
