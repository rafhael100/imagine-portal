<?php
declare(strict_types=1);
require __DIR__.'/../app/Homologation/Commerce/ApprovedOfferCatalog.php';
require __DIR__.'/../app/Homologation/Commerce/CommercialSelectionHandoff.php';

use App\Homologation\Commerce\ApprovedOfferCatalog as Catalog;
use App\Homologation\Commerce\CommercialSelectionHandoff as Handoff;

$checks=0;
function eq426(mixed $actual,mixed $expected): void {
    global $checks;
    ++$checks;
    if ($actual!==$expected) {
        throw new RuntimeException('F426 assertion #'.$checks.' failed');
    }
}
function denies426(callable $action): void {
    global $checks;
    ++$checks;
    try { $action(); }
    catch (InvalidArgumentException|TypeError) { return; }
    throw new RuntimeException('F426 must reject invalid input #'.$checks);
}
$c=new Catalog();
$selection=Handoff::parse(['site'=>'onepage','channels'=>'google_ads,meta_ads','tier'=>'recommended']);
eq426($selection,['site'=>'onepage','channels'=>['google_ads','meta_ads'],'tier'=>'recommended']);
eq426(Handoff::parse(Handoff::sessionData($selection)),$selection);
$summary=Handoff::summarize($c,$selection);
eq426($summary['catalog_version'],$c->version());
eq426($summary['site_quote']['product'],'onepage');
eq426($summary['ads_quote']['product'],'performance');
eq426($summary['site_quote']['setup_total_cents'],99000);
eq426($summary['first_site_installment_cents'],33000);
eq426($summary['site_quote']['monthly']['amount_cents'],7990);
eq426($summary['site_quote']['monthly']['first_due_offset_days'],90);
eq426($summary['media_by_channel_cents'],['google_ads'=>150000,'meta_ads'=>100000]);
eq426($summary['planned_media_total_cents'],250000);
eq426($summary['ads_activation_cents'],98000);
eq426($summary['estimated_ads_management_cents'],50000);
eq426($summary['sum_of_separate_first_payments_cents'],131000);
eq426($summary['ads_quote']['real_postpaid_invoice_authorized'],false);
eq426($summary['single_combined_checkout_enabled'],false);
eq426($summary['real_charge_enabled'],false);
eq426($summary['data_collected'],false);
$min=Handoff::summarize($c,Handoff::parse(['site'=>'landing-pages','channels'=>'meta_ads','tier'=>'minimum']));
eq426($min['site_quote']['setup_total_cents'],129000);
eq426($min['first_site_installment_cents'],43000);
eq426($min['ads_activation_cents'],49000);
eq426($min['media_by_channel_cents'],['meta_ads'=>70000]);
eq426($min['estimated_ads_management_cents'],17000);
eq426($min['sum_of_separate_first_payments_cents'],92000);
$google=Handoff::summarize($c,Handoff::parse(['site'=>'none','channels'=>'google_ads','tier'=>'minimum']));
eq426($google['site_quote'],null);
eq426($google['planned_media_total_cents'],100000);
eq426($google['estimated_ads_management_cents'],20000);
eq426($google['sum_of_separate_first_payments_cents'],49000);
$siteOnly=Handoff::summarize($c,Handoff::parse(['site'=>'landing-pages','channels'=>'','tier'=>'recommended']));
eq426($siteOnly['ads_quote'],null);
eq426($siteOnly['first_site_installment_cents'],43000);
eq426($siteOnly['sum_of_separate_first_payments_cents'],43000);
foreach ([
  [],['site'=>'none','channels'=>'','tier'=>'minimum'],
  ['site'=>'onepage','channels'=>'chatgpt_ads','tier'=>'minimum'],
  ['site'=>'onepage','channels'=>'meta_ads,google_ads','tier'=>'minimum'],
  ['site'=>'onepage','channels'=>'google_ads,google_ads','tier'=>'minimum'],
  ['site'=>'onepage','channels'=>'google_ads','tier'=>'business'],
  ['site'=>'other','channels'=>'','tier'=>'minimum'],
  ['site'=>'onepage','channels'=>['google_ads'],'tier'=>'minimum'],
  ['site'=>'onepage','channels'=>'','tier'=>'recommended','price'=>'1'],
  ['site'=>'onepage','channels'=>'','tier'=>'recommended','product'=>'performance'],
  ['site'=>"onepage\n",'channels'=>'','tier'=>'minimum']
] as $bad) denies426(static fn()=>Handoff::parse($bad));
echo 'PASS F426 '.($checks).' assertions: strict non-binding cross-site choice, trusted V5 recomputation, single-product boundaries, invalid inputs rejected'.PHP_EOL;
