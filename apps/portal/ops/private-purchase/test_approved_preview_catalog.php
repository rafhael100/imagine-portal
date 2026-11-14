<?php
declare(strict_types=1);
if (PHP_SAPI!=='cli' || getenv('GITHUB_ACTIONS')!=='true') {
    fwrite(STDERR,"NO-GO: only disposable CI may evaluate synthetic pricing fixtures.\n");
    exit(2);
}
require dirname(__DIR__,2).'/app/Homologation/PreviewCommercialCatalog.php';
use App\Homologation\PreviewCommercialCatalog;

$file=dirname(__DIR__,2).'/resources/portal/catalog/approved-commercial-catalog.json';
$offers=(new PreviewCommercialCatalog($file))->offers();
function must(bool $condition,string $why):void {
    if (!$condition) throw new RuntimeException('Preview price mismatch: '.$why);
}
must(array_keys($offers)===['onepage','landing-pages','performance'],'offer IDs');
must($offers['onepage']['setup_total_cents']===99000 &&
    $offers['onepage']['installment_cents']===33000 &&
    $offers['onepage']['monthly_cents']===7990 &&
    $offers['onepage']['first_monthly_day']===90,'Onepage legacy approved prices');
must($offers['landing-pages']['setup_total_cents']===129000 &&
    $offers['landing-pages']['installment_cents']===43000 &&
    $offers['landing-pages']['monthly_cents']===7990 &&
    $offers['landing-pages']['first_monthly_day']===90,'Landing approved pricing');
must($offers['performance']['management_percent']===20 &&
    $offers['performance']['min_media_per_channel_cents']===70000 &&
    $offers['performance']['example_fee_cents']===14000,'Performance by-media rule');

$original=json_decode(file_get_contents($file),true,30,JSON_THROW_ON_ERROR);
$tmp=tempnam(sys_get_temp_dir(),'imagine-catalog-');
if($tmp===false)throw new RuntimeException('No temporary CI work area');
try {
    $cases=[
      static function(array &$d):void{$d['gateway']['production_activation_authorized_by_this_file']=true;},
      static function(array &$d):void{$d['offers'][0]['setup_total_cents']=99001;},
      static function(array &$d):void{$d['offers'][1]['monthly']['first_due_offset_days']=60;},
      static function(array &$d):void{$d['offers'][2]['management_fee_basis_points']=2500;},
      static function(array &$d):void{$d['offers'][2]['reference_period']='monthly';},
      static function(array &$d):void{unset($d['offers'][2]['minimum_media_investment_per_channel_cents']);},
      static function(array &$d):void{$d['offers'][]=['id'=>'unreviewed'];},
    ];
    foreach($cases as $mutation){
        $doc=$original;
        $mutation($doc);
        file_put_contents($tmp,json_encode($doc,JSON_THROW_ON_ERROR));
        $blocked=false;
        try {(new PreviewCommercialCatalog($tmp))->offers();}
        catch(RuntimeException){$blocked=true;}
        must($blocked,'rejected unapproved catalog drift');
    }
} finally { unlink($tmp); }
echo "PASS approved BRL offer preview (Onepage, Landing, 20% Performance with R700 media floor); unapproved changes denied, NO BILLING.\n";
