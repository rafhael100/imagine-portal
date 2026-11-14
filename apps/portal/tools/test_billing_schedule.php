<?php
declare(strict_types=1);
require __DIR__.'/../app/Homologation/Commerce/ApprovedOfferCatalog.php';
require __DIR__.'/../app/Homologation/Commerce/BillingSchedule.php';
use App\Homologation\Commerce\ApprovedOfferCatalog;
use App\Homologation\Commerce\BillingSchedule;
function check(bool $ok):void {if(!$ok)throw new RuntimeException('Schedule regression');}
$c=new ApprovedOfferCatalog();
foreach (['onepage'=>33000,'landing-pages'=>43000] as $product=>$amount) {
 $q=$c->quote($product);$r=BillingSchedule::project($q,'2026-11-02T03:00:00Z',4);
 check(count($r)===7&&$r[0]['amount_cents']===$amount&&$r[2]['due_date']==='2027-01-01');
 check(array_column(array_slice($r,3),'due_date')===['2027-01-31','2027-02-28','2027-03-31','2027-04-30']);
 check($r[3]['amount_cents']===7990);
 $q['setup_total_cents']++;try {BillingSchedule::project($q,'2026-01-01');throw new RuntimeException('Accepted mismatch');}catch(InvalidArgumentException $e){}
}
$r=BillingSchedule::project($c->quote('onepage'),'2026-11-02T01:00:00Z',1);
check($r[0]['due_date']==='2026-11-01');
echo "PASS setup dates, totals, timezone, month-end and monthly anchor\n";
