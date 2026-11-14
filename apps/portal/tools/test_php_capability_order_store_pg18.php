<?php

declare(strict_types=1);

// Actual candidate PHP classes + PostgreSQL18 capability functions, CI ONLY.
if (getenv('GITHUB_ACTIONS')!=='true'
    || getenv('PORTAL_CAPABILITY_CI_ONLY')!=='1'
    || getenv('CAPABILITY_DB_HOST')!=='127.0.0.1'
    || getenv('CAPABILITY_DB_NAME')!=='portal_capability_ci') {
    throw new RuntimeException('Refusing non-disposable Portal capability adapter test.');
}
require __DIR__.'/../app/Homologation/SyntheticOwnerSqlCapability.php';
require __DIR__.'/../app/Homologation/SyntheticCapabilityOrderStore.php';

use App\Homologation\SyntheticOwnerSqlCapability as Issuer;
use App\Homologation\SyntheticCapabilityOrderStore as Store;

$dsn='pgsql:host=127.0.0.1;port=5432;dbname=portal_capability_ci';
$pdo=new PDO($dsn,'portal_capability_ci','ci-only-sql-client-disposable-2026',[
 PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,
 PDO::ATTR_EMULATE_PREPARES=>false,
 PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,
]);
$issuer=new Issuer('ci-only-capability-hmac-key-not-for-production-2026-11');
$store=new Store($pdo,$issuer);
$cases=[
 ['test-org-a','demo-44444444444444444444444444444444',
  'TEST-44444444444444444444444444444444','onepage'],
 ['test-org-a','demo-55555555555555555555555555555555',
  'TEST-55555555555555555555555555555555','landing-pages'],
 ['test-org-b','demo-66666666666666666666666666666666',
  'TEST-66666666666666666666666666666666','performance'],
];
$checks=0;
function p0adapterCheck(bool $ok,string $detail): void {
 global $checks;
 if(!$ok) throw new RuntimeException('Unsafe SQL PHP adapter: '.$detail);
 ++$checks;
}
foreach($cases as [$org,$customer,$order,$product]) {
 $actual=$store->create($org,$customer,$order,'TEST-adapter-same-key-12345678',$product);
 p0adapterCheck($actual===$order,'create must persist signed owner/order');
 $own=$store->list($org,$customer);
 p0adapterCheck(count($own)===1 && $own[0]['order_id']===$order
  && $own[0]['product']===$product,'only individual customer orders visible');
}
[$orgA,$customerA,$orderA]=$cases[0];
[$orgB,$customerB,$orderB]=$cases[1];
p0adapterCheck($store->create($orgA,$customerA,$orderA,
 'TEST-adapter-same-key-12345678','onepage')===$orderA,
 'same-owner replay of same key returns unchanged order');
try {
 $store->create($orgA,$customerA,'TEST-77777777777777777777777777777777',
  'TEST-adapter-same-key-12345678','performance');
 throw new RuntimeException('Changed product for same request key was accepted.');
} catch(PDOException $e) {
 p0adapterCheck($e->getCode()==='23505','conflicting product must fail by SQL constraint');
}
p0adapterCheck($store->approve($orgA,$customerA,$orderA),'owner can advance own order');
p0adapterCheck(!$store->approve($orgA,$customerA,$orderB),
 'same company other owner order cannot be changed using A capability');
p0adapterCheck(!$store->approve($orgA,$customerA,$orderA),
 'approved state cannot be re-applied');
$a=$store->list($orgA,$customerA);
$b=$store->list($orgB,$customerB);
p0adapterCheck($a[0]['state']==='approved' && $b[0]['state']==='pending',
 'approval cannot affect neighbor with shared SQL role');
foreach([
 fn()=> $issuer->issue('admin',$orgA,$customerA,$orderA),
 fn()=> $issuer->issue('create','not-a-real-org',$customerA,$orderA),
 fn()=> $issuer->issue('list',$orgA,$customerA,$orderA),
 fn()=> $issuer->issue('approve',$orgA,$customerA,'javascript:alert(1)'),
 fn()=> $store->create($orgA,$customerA,$orderA,'oops','onepage'),
 fn()=> $store->create($orgA,$customerA,$orderA,'TEST-some-key-12345678','fake-product'),
] as $bad) {
 try {$bad(); throw new RuntimeException('Bad actor, action or product accepted');}
 catch(InvalidArgumentException) {$checks++;}
}
try {
 $pdo->query('SELECT id,customer_id FROM portal_capability_lab.orders')->fetchAll();
 throw new RuntimeException('SQL SELECT bypass accepted');
} catch(PDOException $e) {
 p0adapterCheck($e->getCode()==='42501','same login must have zero table SELECT grants');
}
$badIssuer=new Issuer('a-valid-length-but-wrong-demo-key-2026-11-xyz');
$badStore=new Store($pdo,$badIssuer);
try {
 $badStore->list($orgA,$customerA);
 throw new RuntimeException('HMAC from unknown issuer accepted');
} catch(PDOException $e) {
 p0adapterCheck($e->getCode()==='42501','HMAC signer must match protected SQL key');
}
$reconnect=new PDO($dsn,'portal_capability_ci','ci-only-sql-client-disposable-2026',[
 PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,
 PDO::ATTR_EMULATE_PREPARES=>false,
]);
$next=new Store($reconnect,$issuer);
p0adapterCheck(count($next->list($orgA,$customerA))===1
 && count($next->list($orgB,$customerB))===1,
 'proof must be replay-safe across a fresh SQL connection without widening owner');
echo "PASS: {$checks} PHP adapter / SQL capability checks, same-role customers isolated.\n";
echo "NO-GO: caller principal and signing key are CI fixtures; app routing unchanged.\n";
