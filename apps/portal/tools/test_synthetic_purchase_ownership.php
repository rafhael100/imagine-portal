<?php
declare(strict_types=1);
require __DIR__.'/../app/Homologation/SyntheticPurchaseOwnership.php';
use App\Homologation\SyntheticPurchaseOwnership;
$checks=0;
$check=function(bool $result,string $message) use (&$checks): void {
    if (!$result) throw new RuntimeException($message);
    $checks++;
};
$a=new SyntheticPurchaseOwnership('TEST-order-12345678','test-org-a','test-customer-a');
$check($a->belongsTo('test-org-a','test-customer-a'),'Owner denied');
$check(!$a->belongsTo('test-org-a','test-customer-b'),'Another customer allowed');
$check(!$a->belongsTo('test-org-b','test-customer-a'),'Another organization allowed');
$check(!$a->belongsTo('test-org-b','test-customer-b'),'Other tenant allowed');
$check(!$a->belongsTo('test-org-a','administrador.teste'),'Admin shortcut allowed');
$check(!$a->belongsTo('',''),'Anonymous allowed');
$check(!$a->belongsTo('test-org-a','TEST-CUSTOMER-A'),'Identity normalization changed ownership');
foreach ([
 ['bad','test-org-a','test-customer-a'],
 ['TEST-order-12345678','real-org','test-customer-a'],
 ['TEST-order-12345678','test-org-a','rafhael@example.com'],
 ['TEST-order-12345678','test-org-a',''],
] as $args) {
    try { new SyntheticPurchaseOwnership(...$args); throw new RuntimeException('Invalid record accepted'); }
    catch (InvalidArgumentException) { $checks++; }
}
echo "PASS $checks synthetic ownership checks; no HTTP or database authorization\n";
