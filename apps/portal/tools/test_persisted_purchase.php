<?php

declare(strict_types=1);

require __DIR__.'/../app/Homologation/PersistedTestPurchase.php';
use App\Homologation\PersistedTestPurchase;

function connection(): PDO {
    return new PDO('pgsql:host=127.0.0.1;port=5432;dbname=portal_purchase_rehearsal','postgres','synthetic-ci-only',[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
}
function check(bool $value,string $label): void { if (!$value) throw new RuntimeException($label); }
function denied(callable $operation): void {
    try { $operation(); } catch (RuntimeException|InvalidArgumentException $e) { return; }
    throw new RuntimeException('Expected rejection.');
}
$db=connection();
$db->exec("CREATE SCHEMA purchase_rehearsal; CREATE TABLE purchase_rehearsal.orders (
 id text PRIMARY KEY,tenant text NOT NULL CHECK (tenant IN ('test-org-a','test-org-b')),
 request_key text NOT NULL,product text NOT NULL CHECK (product IN ('onepage','landing-pages','performance')),
 payment text NOT NULL DEFAULT 'pending' CHECK (payment IN ('pending','declined','approved')),
 revision integer NOT NULL DEFAULT 0 CHECK (revision>=0),briefing jsonb,
 UNIQUE(tenant,request_key), CHECK (briefing IS NULL OR payment='approved'))");
$store=new PersistedTestPurchase($db);
foreach (['onepage','landing-pages','performance'] as $product) {
    $key='TEST-request-'.$product;
    $order=$store->create('test-org-a',$key,$product);
    check($store->create('test-org-a',$key,$product)===$order,'Exact replay');
    denied(fn()=>$store->create('test-org-a',$key,$product==='onepage'?'performance':'onepage'));
    denied(fn()=>$store->read('test-org-b',$order['id']));
    $brief=['business'=>'Empresa fictícia','goal'=>'Objetivo fictício','audience'=>'Público fictício','materials'=>'Somente amostras'];
    denied(fn()=>$store->briefing('test-org-a',$order['id'],0,$brief));
    $declined=$store->payment('test-org-a',$order['id'],0,'declined');
    denied(fn()=>$store->briefing('test-org-a',$order['id'],1,$brief));
    denied(fn()=>$store->payment('test-org-a',$order['id'],0,'approved'));
    $pending=$store->payment('test-org-a',$order['id'],1,'pending');
    denied(fn()=>$store->briefing('test-org-a',$order['id'],2,$brief));
    $approved=$store->payment('test-org-a',$order['id'],2,'approved');
    denied(fn()=>$store->briefing('test-org-a',$order['id'],3,['goal'=>'incompleto']));
    $complete=$store->briefing('test-org-a',$order['id'],3,$brief);
    // A new database connection and service instance must read the saved data.
    $reloaded=(new PersistedTestPurchase(connection()))->read('test-org-a',$order['id']);
    check($complete===$reloaded && $reloaded['briefing']==$brief,'Durable reload');
    check($complete['real_payment']===false && $complete['authorizes_http']===false,'No operational authority');
    denied(fn()=>$store->payment('test-org-a',$order['id'],4,'declined'));
    denied(fn()=>$store->briefing('test-org-b',$order['id'],4,$brief));
    $other=$store->create('test-org-b',$key,$product);
    check($other['id']!==$order['id'],'Distinct tenant order');
    echo 'PASS persisted purchase '.$product.PHP_EOL;
}
denied(fn()=>$store->create('real-company','TEST-real-request','onepage'));
denied(fn()=>$store->create('test-org-a','TEST-unknown-request','unknown'));
check((int)$db->query('SELECT count(*) FROM purchase_rehearsal.orders')->fetchColumn()===6,'No duplicated orders');
echo 'PASS replay, conflict, payment gating, tenant mismatch, stale revision, durable briefing'.PHP_EOL;
