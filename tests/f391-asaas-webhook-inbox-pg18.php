<?php

declare(strict_types=1);

/**
 * F1-391: Actual PostgreSQL18 durable Asaas webhook inbox under a restricted
 * login, no real sender/provider, no HTTP endpoint, no customer or PSP data.
 */
if (PHP_SAPI!=='cli' || getenv('GITHUB_ACTIONS')!=='true' ||
    getenv('PORTAL_F391_ASAAS_WEBHOOK_CI')!=='1' ||
    getenv('DB_HOST')!=='127.0.0.1' ||
    getenv('DB_DATABASE')!=='portal_asaas_ci') {
    fwrite(STDERR,"F1-391 requires disposable isolated GitHub PG18.\n");
    exit(2);
}
require dirname(__DIR__).'/apps/portal/app/Domain/SitesIntegration/AsaasWebhookPgInbox.php';

use App\Domain\SitesIntegration\AsaasWebhookPgInbox;
$checks=0;
function check(bool $condition,string $label):void {
    global $checks;
    if(!$condition)throw new RuntimeException('F1-391 FAIL: '.$label);
    ++$checks;
}
function refused(callable $fn):bool {
    try{$fn();}catch(DomainException|PDOException|InvalidArgumentException|RuntimeException){
        return true;
    }
    return false;
}
function db(string $user,string $password):PDO {
    return new PDO(
        'pgsql:host=127.0.0.1;port=5432;dbname=portal_asaas_ci;connect_timeout=5',
        $user,$password,
        [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,
         PDO::ATTR_EMULATE_PREPARES=>false]
    );
}
$pdo=db('asaas_webhook_runtime','CI-ONLY-NOT-A-REAL-KEY-1234567890');
$admin=db('postgres','CI-ASaaS-ADMIN-SYNTHETIC');
check($pdo->query('SELECT session_user')->fetchColumn()==='asaas_webhook_runtime',
    'real restricted PG18 account mandatory');
check((int)$admin->query('SHOW server_version_num')->fetchColumn()>=180000,
    'PostgreSQL 18 required');
check($admin->query(
    "SELECT relrowsecurity AND relforcerowsecurity FROM pg_class
      WHERE oid='portal_psp.asaas_inbox'::regclass"
)->fetchColumn()!==false,'RLS metadata present');

$token=str_repeat('T',48);
$inbox=new AsaasWebhookPgInbox($pdo,'ci-asaas-sandbox',$token);
$event=[
    'id'=>'evt_12345678abcdef0099',
    'event'=>'PAYMENT_RECEIVED',
    'dateCreated'=>'2026-11-14 07:00:00',
    'payment'=>[
        'id'=>'pay_123456789000','value'=>69.90,
        'customer'=>'cus_123456789000',
        'status'=>'RECEIVED',
        'description'=>'FICTIONAL ONLY',
    ],
];
$raw=json_encode($event,JSON_THROW_ON_ERROR);
check(refused(static fn()=>$inbox->capture('GET','application/json',$token,$raw)),
    'GET refused before persistence');
check(refused(static fn()=>$inbox->capture('POST','application/x-www-form-urlencoded',$token,$raw)),
    'invalid content type refused');
check(refused(static fn()=>$inbox->capture('POST','application/json','forged-webhook-token',$raw)),
    'spoofed webhook access token denied');
check(refused(static fn()=>$inbox->capture('POST','application/json',$token,'{')),
    'malformed JSON denied');
check(refused(static fn()=>$inbox->capture('POST','application/json',$token,str_repeat('A',32769))),
    'oversized body denied');
check(refused(static fn()=>new AsaasWebhookPgInbox($pdo,'ci-asaas-sandbox','short')),
    'short secret refused');
check(refused(static fn()=>new AsaasWebhookPgInbox($admin,'ci-asaas-sandbox',$token)),
    'superuser/owner connection refused for ingestion');

$stored=$inbox->capture('POST','application/json; charset=utf-8',$token,$raw);
check($stored===['received'=>true,'duplicate'=>false],
    'first event is committed before application responds');
$retry=$inbox->capture('POST','application/json',$token,$raw);
check($retry===['received'=>true,'duplicate'=>true],
    'at-least-once exact duplicate is accepted without second processing');
check((int)$admin->query('SELECT count(*) FROM portal_psp.asaas_inbox')->fetchColumn()===1,
    'one idempotent row persisted in real PostgreSQL18');
$row=$admin->query(
    'SELECT event_type,payment_id,state,payload_sha256
     FROM portal_psp.asaas_inbox'
)->fetch(PDO::FETCH_ASSOC);
check($row['event_type']==='PAYMENT_RECEIVED' &&
    $row['payment_id']==='pay_123456789000' &&
    $row['state']==='pending_reconciliation' &&
    $row['payload_sha256']===hash('sha256',$raw),
    'webhook event is only queued; never marks a paid contract');

$forged=$event;
$forged['payment']['status']='REFUNDED';
check(refused(static fn()=>$inbox->capture(
    'POST','application/json',$token,json_encode($forged,JSON_THROW_ON_ERROR)
)), 'same event ID with altered body fails closed');
check((int)$admin->query('SELECT count(*) FROM portal_psp.asaas_inbox')->fetchColumn()===1,
    'conflicting duplicate must not overwrite original');

$second=$event;
$second['id']='evt_12345678abcdef0100';
$second['event']='PAYMENT_REFUNDED';
check($inbox->capture('POST','application/json',$token,
    json_encode($second,JSON_THROW_ON_ERROR))['duplicate']===false,
    'refund notification queued independently without retroactively marking paid');
check((int)$admin->query(
    "SELECT count(*) FROM portal_psp.asaas_inbox
      WHERE state='pending_reconciliation'"
)->fetchColumn()===2,
    'separate refund must remain pending official PSP reconciliation');

foreach(['DELETE FROM portal_psp.asaas_inbox',
         "UPDATE portal_psp.asaas_inbox SET state='reconciled'",
         'SET ROLE postgres'] as $sql) {
    check(refused(static fn()=>$pdo->exec($sql)),
        'ingress SQL login must not mutate state, delete or escalate');
}
$other=new AsaasWebhookPgInbox($pdo,'ci-other-account',$token);
$outside=$event;
$outside['id']='evt_12345678abcdef0111';
check(refused(static fn()=>$other->capture(
    'POST','application/json',$token,json_encode($outside,JSON_THROW_ON_ERROR)
)), 'DB RLS refuses forged provider account from same login');
check((int)$admin->query('SELECT count(*) FROM portal_psp.asaas_inbox')->fetchColumn()===2,
    'no cross-account row may be added');

$invalid=$event;
$invalid['id']='evt_12345678abcdef0112';
unset($invalid['payment']['id']);
check(refused(static fn()=>$inbox->capture(
    'POST','application/json',$token,json_encode($invalid,JSON_THROW_ON_ERROR)
)), 'missing provider charge ID denied');
check($pdo->query('SELECT count(*) FROM portal_psp.asaas_inbox')->fetchColumn()==2,
    'restricted login may count only its own provider account inbox rows');
check((int)$admin->query(
    "SELECT count(*) FROM portal_psp.asaas_inbox WHERE state='reconciled'"
)->fetchColumn()===0,
    'no PAYMENT_RECEIVED webhook can ever mark a settlement/entitlement by itself');

echo "$checks F1-391 authenticated, durable, idempotent PG18 Asaas webhook inbox guards passed.\n";
echo "NO-GO: synthetic tokens/accounts; no HTTP endpoint, PSP keys, reconciler, payment, contract, Site or VPS.\n";
