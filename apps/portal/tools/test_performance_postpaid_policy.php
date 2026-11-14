<?php
declare(strict_types=1);
require __DIR__.'/../app/Homologation/Commerce/PerformancePostpaidPolicy.php';
use App\Homologation\Commerce\PerformancePostpaidPolicy as P;
function verify(bool $condition): void { if (!$condition) throw new RuntimeException('Postpaid policy regression'); }
foreach ([0=>17000, 35000=>17000, 84999=>17000, 85000=>17000, 85003=>17001, 400000=>80000] as $spend=>$fee) {
    $r = P::fullMonth(['google_ads'], ['google_ads'=>$spend]);
    verify($r['management_fee_cents']===$fee && $r['activation_total_cents']===49000 && !$r['automatic_charge_enabled']);
}
$r=P::fullMonth(['google_ads','meta_ads'], ['meta_ads'=>35000,'google_ads'=>400000]);
verify($r['management_fee_cents']===97000 && $r['activation_total_cents']===98000);
verify($r['channels']['meta_ads']['minimum_applied'] && !$r['channels']['google_ads']['minimum_applied']);
foreach ([[[],[]],[['google_ads','google_ads'],['google_ads'=>0]],[['google_ads'],[]],[['google_ads'],['google_ads'=>null]],[['google_ads'],['google_ads'=>-1]],[['google_ads'],['google_ads'=>true]],[['google_ads'],['google_ads'=>35.0]],[['google_ads'],['google_ads'=>0,'meta_ads'=>0]],[['google_ads'],['google_ads'=>100000001]]] as [$channels,$spend]) {
    try { P::fullMonth($channels,$spend); throw new RuntimeException('Invalid scope/spend accepted'); }
    catch (InvalidArgumentException) {}
}
echo "PASS per-channel floor, approved examples, mixed-channel isolation, cents rounding, missing evidence and invalid scope\n";
