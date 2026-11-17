<?php
declare(strict_types=1);

use App\Homologation\Commerce\CompositeQuotePreview;
use Imagine\Lab\OnepageBindingProducer\ProducerContract;

require __DIR__.'/ProducerContract.php';

$row = ['id'=>'12db12db-12db-12db-12db-12db12db12db','quote_id'=>'21db21db-21db-21db-21db-21db21db21db',
    'company'=>'31db31db-31db-31db-31db-31db31db31db','buyer'=>'41db41db-41db-41db-41db-41db41db41db',
    'status'=>'pending_payment','contract_ref'=>'lab-test-contract','contract_revision'=>1,
    'contract_text'=>'Synthetic pending contract only.','contract_digest'=>hash('sha256','Synthetic pending contract only.'),
    'offer_digest'=>str_repeat('a',64),'terms_digest'=>str_repeat('b',64),'accepted_at'=>'2026-11-02T01:00:00.000000Z',
    'terms'=>['scope'=>'synthetic_only','payment_policy'=>'undecided'],
    'quote_snapshot'=>(new CompositeQuotePreview())->quote(['site'=>'onepage','channels'=>[],'tier'=>'minimum'])];
$read = ['status'=>'pending_payment','laboratory_only'=>true,'settled'=>false,'preview_admitted'=>false,'binding'=>null,'snapshot'=>$row];
$prepare = static fn(array $v) => ProducerContract::prepare($v,'lab-synthetic-account','cus_LABfixture');
$n = 0;
$assert = static function (string $name, bool $pass) use (&$n): void {
    if (!$pass) throw new RuntimeException($name);
    $n++;
};

$plan = $prepare($read) ?? throw new RuntimeException('fixture prepare failed');
$assert('only setup dates, São Paulo D0/D30/D60', array_column($plan->lines,'due_date') === ['2026-11-01','2026-12-01','2026-12-31']);
$assert('native term amounts', array_column($plan->lines,'amount_cents') === [33000,33000,33000]);
$assert('same plan stable', $plan->lines === $prepare($read)?->lines);
$refs = array_column($plan->lines,'external_reference');
$assert('opaque unique refs compatible with #924 gateway', count(array_unique($refs)) === 3 && count(array_filter($refs, static fn($v)=>preg_match('/\Aimagine:lab-op-v1:[a-f0-9]{64}\z/D',$v))) === 3);
foreach (['id'=>'52db52db-52db-52db-52db-52db52db52db','contract_revision'=>2,'offer_digest'=>str_repeat('c',64),'company'=>'61db61db-61db-61db-61db-61db61db61db'] as $key=>$v) {
    $c = $read;
    $c['snapshot'][$key]=$v;
    $assert("ref scope $key", array_intersect($refs, array_column($prepare($c)?->lines ?? [], 'external_reference')) === []
        && $prepare($c) !== null);
}
foreach (['settled'=>true,'preview_admitted'=>true,'binding'=>[],'laboratory_only'=>false] as $key=>$v) {
    $assert("envelope $key", $prepare([$key=>$v]+$read) === null);
}
foreach (['catalog_version'=>str_repeat('c',64),'site'=>'landing-pages','channels'=>['google_ads'],'site_setup_cents'=>98999] as $key=>$v) {
    $c = $read; $c['snapshot']['quote_snapshot'][$key]=$v;
    $assert("quote $key", $prepare($c) === null);
}
$c=$read;
$c['snapshot']['terms']['payment_policy']='guessed';
$assert('no nonfixture policy assumption', $prepare($c) === null);
$assert('no actual account namespace', ProducerContract::prepare($read,'actual-account','cus_LABfixture') === null);

$rows = [];
foreach (array_values($plan->lines) as $i=>$line) {
    $rows[] = ['kind'=>'synthetic_individual_creation','account'=>'lab-synthetic-account','environment'=>'sandbox',
        'payment'=>['id'=>'pay_LABfixture'.($i+1),'object'=>'payment','customer'=>'cus_LABfixture',
            'externalReference'=>$line['external_reference'],'dueDate'=>$line['due_date'],'value'=>$i===0?330:'330.00',
            'deleted'=>false,'status'=>'PENDING']];
}
$bound = $plan->assemble($rows);
$assert('complete batch, stable order and no result state', ($bound['fixture_only'] ?? false) && array_keys($bound['lines']) === ['setup-1','setup-2','setup-3']
    && array_column($bound['lines'],'payment_id')===['pay_LABfixture1','pay_LABfixture2','pay_LABfixture3'] && !isset($bound['lines']['setup-1']['status']));
$assert('order of receipt arrival irrelevant', $bound === $plan->assemble(array_reverse($rows)));
$assert('no partial batch', $plan->assemble([$rows[0],$rows[1]]) === null);
$assert('no duplicate row', $plan->assemble([$rows[0],$rows[0],$rows[2]]) === null);
foreach (['account'=>'lab-other','environment'=>'production','kind'=>'webhook'] as $key=>$v) {
    $c = $rows; $c[1][$key]=$v;
    $assert("context $key", $plan->assemble($c) === null);
}
foreach (['id'=>['pay_LABfixture1','pay_other',null],'externalReference'=>[$refs[0],'generic'],
    'dueDate'=>['2026-12-02',null],'value'=>['329.99','330.001',330.0,null],'customer'=>['cus_LABother'],
    'deleted'=>[null,true],'currency'=>['USD'],'subscription'=>['sub_LABfixture'],
    'installment'=>['unknown-group'],'installmentNumber'=>[2]] as $key=>$values) {
    foreach ($values as $value) {
        $c=$rows; $c[1]['payment'][$key]=$value;
        $assert("mismatched $key", $plan->assemble($c) === null);
    }
}
echo "PASS: $n offline Onepage binding-producer checks\n";
