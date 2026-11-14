<?php

declare(strict_types=1);

/**
 * Synthetic CI-only P0: shared SQL login MUST NOT read adjacent customers.
 * SQL capabilities signed by an independent application-tier fixture key;
 * PostgreSQL checks HMAC in SECURITY DEFINER functions and forbids raw table access.
 * NO production IAM, no Laravel bindings, no VPS, no personal data.
 */
if (PHP_SAPI!=='cli'
    || getenv('GITHUB_ACTIONS')!=='true'
    || getenv('PORTAL_CAPABILITY_CI_ONLY')!=='1'
    || getenv('CAPABILITY_DB_HOST')!=='127.0.0.1'
    || getenv('CAPABILITY_DB_NAME')!=='portal_capability_ci') {
    fwrite(STDERR, "Refusing non-disposable customer SQL capability lab.\n");
    exit(2);
}
$dsn='pgsql:host=127.0.0.1;port=5432;connect_timeout=5;dbname=portal_capability_ci';
$options=[
 PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,
 PDO::ATTR_EMULATE_PREPARES=>false,
 PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,
 PDO::ATTR_TIMEOUT=>6,
];
$user=new PDO($dsn,'portal_capability_ci','ci-only-sql-client-disposable-2026',$options);
$admin=new PDO($dsn,'postgres','ci-postgres-capability-admin-only',$options);
if ($user->query('SELECT session_user')->fetchColumn()!=='portal_capability_ci'
    || $admin->query('SELECT current_database()')->fetchColumn()!=='portal_capability_ci'
    || (int)$admin->query('SHOW server_version_num')->fetchColumn()<180000) {
    throw new RuntimeException('SQL identity or PostgreSQL18 fixture mismatch.');
}

$checks=0;
function ciCheck(bool $ok,string $label): void {
 global $checks;
 $checks++;
 if(!$ok) throw new RuntimeException('Capability SQL CI: '.$label);
}
function ciDeny(callable $action,string $label): void {
 try {
  $action();
 } catch(PDOException $error) {
  ciCheck(in_array((string)$error->getCode(),['42501','23505','22023'],true),
   $label.' (unexpected SQLSTATE)');
  return;
 }
 throw new RuntimeException('Unsafe SQL capability allowed: '.$label);
}
const KEY='ci-only-capability-hmac-key-not-for-production-2026-11';
function ciMac(string $action,string $org,string $customer,string $id,int $expiry): string {
 return hash_hmac('sha256',
  $action.'|'.$org.'|'.$customer.'|'.$id.'|'.$expiry,
  KEY);
}
$expiry=time()+65;
$alice='demo-11111111111111111111111111111111';
$bob='demo-22222222222222222222222222222222';
$carol='demo-33333333333333333333333333333333';
$aliceId='TEST-aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';
$bobId='TEST-bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb';
$carolId='TEST-cccccccccccccccccccccccccccccccc';
$org='test-org-a';
$other='test-org-b';

$role=$admin->query("SELECT rolsuper,rolbypassrls,rolcreaterole,rolcreatedb,rolinherit
                     FROM pg_roles WHERE rolname='portal_capability_ci'")->fetch();
ciCheck(is_array($role) && !in_array(true,$role,true),
    'application SQL login cannot bypass RLS, inherit privileged role or become admin');
$grants=$admin->query("SELECT
  has_table_privilege('portal_capability_ci','portal_capability_lab.orders','SELECT') AS can_read,
  has_table_privilege('portal_capability_ci','portal_capability_lab.orders','INSERT') AS can_insert,
  has_table_privilege('portal_capability_ci','portal_capability_lab.orders','UPDATE') AS can_update,
  has_table_privilege('portal_capability_ci','portal_capability_lab.signing_key','SELECT') AS can_read_key
")->fetch();
ciCheck(is_array($grants)
 && !in_array(true,array_values($grants),true)
 && !in_array('t',array_values($grants),true),
 'no table or signing-secret grants to SQL application login');
$policy=$admin->query("SELECT relrowsecurity,relforcerowsecurity
 FROM pg_class WHERE oid='portal_capability_lab.orders'::regclass")->fetch();
ciCheck((bool)$policy['relrowsecurity']&&(bool)$policy['relforcerowsecurity'],
 'CI table must enforce RLS even for owner');

ciDeny(static fn()=> $user->query('SELECT * FROM portal_capability_lab.orders')->fetchAll(),
 'raw table SELECT from shared SQL login');
ciDeny(static fn()=> $user->query('SELECT * FROM portal_capability_lab.signing_key')->fetchAll(),
 'raw signing secret SELECT');
ciDeny(static fn()=> $user->exec("SET ROLE postgres"),
 'privileged SQL impersonation');
ciDeny(static fn()=> $user->exec("INSERT INTO portal_capability_lab.orders
 (id,organization_id,customer_id,request_key,product)
 VALUES ('TEST-dddddddddddddddddddddddddddddddd','test-org-a',
 'demo-11111111111111111111111111111111','TEST-direct-12345678','onepage')"),
 'direct write forbidden');
ciDeny(static fn()=> $user->query("SELECT portal_capability_lab.assert_capability(
 'list','test-org-a','demo-11111111111111111111111111111111','-',0,'bad')"),
 'internal signature verifier not callable by shared role');

$create=$user->prepare('SELECT portal_capability_lab.create_owned_order(?,?,?,?,?,?,?)');
$listing=$user->prepare('SELECT * FROM portal_capability_lab.list_owned_orders(?,?,?,?)');
$approve=$user->prepare('SELECT portal_capability_lab.approve_owned_order(?,?,?,?,?)');
$createOrder=static function(string $organization,string $owner,string $id,
 string $requestKey,string $product,int $validUntil,string $proof) use ($create): string {
 $create->execute([$organization,$owner,$id,$requestKey,$product,$validUntil,$proof]);
 $result=$create->fetchColumn();
 $create->closeCursor();
 return (string)$result;
};
$listOrders=static function(string $organization,string $owner,int $validUntil,
 string $proof) use ($listing):array {
 $listing->execute([$organization,$owner,$validUntil,$proof]);
 $items=$listing->fetchAll();
 $listing->closeCursor();
 return $items;
};
$approveOrder=static function(string $organization,string $owner,string $id,
 int $validUntil,string $proof) use ($approve):bool {
 $approve->execute([$organization,$owner,$id,$validUntil,$proof]);
 $success=$approve->fetchColumn();
 $approve->closeCursor();
 return in_array($success,['t','1',true,1],true);
};
$cases=[
 [$org,$alice,$aliceId,'onepage','TEST-same-key-12345678'],
 [$org,$bob,$bobId,'landing-pages','TEST-same-key-12345678'],
 [$other,$carol,$carolId,'performance','TEST-same-key-12345678'],
];
foreach($cases as [$tenant,$owner,$id,$product,$key]) {
 ciCheck($createOrder($tenant,$owner,$id,$key,$product,$expiry,
  ciMac('create',$tenant,$owner,$id,$expiry))===$id,
 'explicit app-issued create capability must persist exactly own row');
 $rows=$listOrders($tenant,$owner,$expiry,ciMac('list',$tenant,$owner,'-',$expiry));
 ciCheck(count($rows)===1 && $rows[0]['order_id']===$id
  && $rows[0]['product']===$product && $rows[0]['state']==='pending',
  'each same-role synthetic customer may list only their own order');
}
ciCheck($createOrder($org,$alice,$aliceId,'TEST-same-key-12345678','onepage',
 $expiry,ciMac('create',$org,$alice,$aliceId,$expiry))===$aliceId,
 'same account/idempotency must be stable');
ciDeny(static fn()=> $createOrder($org,$alice,'TEST-eeeeeeeeeeeeeeeeeeeeeeeeeeeeeeee',
 'TEST-same-key-12345678','performance',$expiry,
 ciMac('create',$org,$alice,'TEST-eeeeeeeeeeeeeeeeeeeeeeeeeeeeeeee',$expiry)),
 'product collision under same owner idempotency key');

ciDeny(static fn()=> $listOrders($org,$bob,$expiry,
 ciMac('list',$org,$alice,'-',$expiry)), 'signed Alice actor may not become Bob');
ciDeny(static fn()=> $listOrders($other,$alice,$expiry,
 ciMac('list',$org,$alice,'-',$expiry)), 'Alice same ID under another tenant denied');
ciDeny(static fn()=> $listOrders($org,$alice,time()-5,
 ciMac('list',$org,$alice,'-',time()-5)), 'expired app-issued actor proof denied');
ciDeny(static fn()=> $listOrders($org,$alice,time()+1000,
 ciMac('list',$org,$alice,'-',time()+1000)), 'overlong capability expiry denied');
ciDeny(static fn()=> $listOrders($org,$alice,$expiry,str_repeat('a',64)),
 'fake 64-hex signature rejected');
ciDeny(static fn()=> $createOrder($org,$alice,
 'TEST-ffffffffffffffffffffffffffffffff','TEST-forged-12345678','onepage',
 $expiry,ciMac('create',$org,$alice,$aliceId,$expiry)), 'capability bound to exact order id');

ciCheck($approveOrder($org,$alice,$aliceId,$expiry,
 ciMac('approve',$org,$alice,$aliceId,$expiry)),
 'owner with explicit app-issued approve capability may update own order');
ciDeny(static fn()=> $approveOrder($org,$alice,$bobId,$expiry,
 ciMac('approve',$org,$alice,$aliceId,$expiry)),
 'approval proof must not reach another customer order');
ciCheck(!$approveOrder($org,$alice,$aliceId,$expiry,
 ciMac('approve',$org,$alice,$aliceId,$expiry)),
 'second approve replay must not affect revision/state');

$aliceRows=$listOrders($org,$alice,$expiry,ciMac('list',$org,$alice,'-',$expiry));
$bobRows=$listOrders($org,$bob,$expiry,ciMac('list',$org,$bob,'-',$expiry));
ciCheck(count($aliceRows)===1 && $aliceRows[0]['state']==='approved'
 && count($bobRows)===1 && $bobRows[0]['state']==='pending',
 'one owner approval must never affect neighbor with same SQL login');
$reconnect=new PDO($dsn,'portal_capability_ci','ci-only-sql-client-disposable-2026',$options);
$reconnected=$reconnect->prepare('SELECT * FROM portal_capability_lab.list_owned_orders(?,?,?,?)');
$reconnected->execute([$org,$bob,$expiry,ciMac('list',$org,$bob,'-',$expiry)]);
ciCheck(count($reconnected->fetchAll())===1,
 'a new SQL connection must maintain same-customer visibility fence');

$count=(int)$admin->query('SELECT count(*) FROM portal_capability_lab.orders')->fetchColumn();
ciCheck($count===3, 'exactly three individually owned rows should survive no-op replays');
echo "PASS: {$checks} checks; shared SQL login cannot raw-read, forge owner/tenant, or replay wrong capability.\n";
echo "NO-GO: HMAC and roles are fixture-only; NOT integrated into the Portal runtime or real IAM.\n";
