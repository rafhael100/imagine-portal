<?php
declare(strict_types=1);

/**
 * Portal F66: export day-level BRL centavos to a semicolon-delimited,
 * UTF-8 BOM CSV compatible with Brazilian spreadsheet applications.
 *
 * Strict offline validation by Portal F64, no user/advertiser identity,
 * external Ads, billing or web route. No free text can become a formula.
 */
if (PHP_SAPI !== 'cli'
    || getenv('APP_ENV') !== 'testing'
    || getenv('PORTAL_F64_BUDGET_PREVIEW_LAB') !== '1'
    || getenv('PORTAL_F66_BUDGET_CSV_LAB') !== '1') {
    fwrite(STDERR, "F66 CSV export is available in isolated tests only\n");
    exit(2);
}
$input=$argv[1]??null;
if(!is_string($input) || $input==='' || !is_file($input)
    || is_link($input) || !is_readable($input)
    || filesize($input)>128000) {
    fwrite(STDERR, "F66 rejected invalid local budget JSON\n");
    exit(2);
}
require_once dirname(__DIR__)
    .'/app/Domain/PerformanceDashboard/CredentialBoundBudgetPreview.php';
try {
    $bytes=file_get_contents($input);
    if(!is_string($bytes))throw new RuntimeException('Invalid input');
    $dto=json_decode($bytes,true,64,JSON_THROW_ON_ERROR);
    $data=\App\Domain\PerformanceDashboard\CredentialBoundBudgetPreview::fromDto($dto);
    if($data===null || $data->canExecuteMedia() || $data->canBill()
        || $data->hasHumanApproval() || $data->isAuthenticatedPortalSession()) {
        throw new RuntimeException('Unsafe or operational input');
    }
} catch (Throwable) {
    fwrite(STDERR,"F66 denied unsafe or invalid budget preview\n");
    exit(2);
}
$out=fopen('php://temp/maxmemory:1048576','w+b');
if($out===false) {
    fwrite(STDERR,"F66 could not start local export\n");
    exit(2);
}
try {
    // Spreadsheet import defaults commonly rely on BOM for UTF-8; all money
    // fields are plain integer centavos, never float/R$ textual formulas.
    fwrite($out,"\xEF\xBB\xBF");
    $emit=static function(array $row)use($out):void {
        if(fputcsv($out,$row,';','"','',"\r\n")===false) {
            throw new RuntimeException('CSV output failed');
        }
    };
    $emit([
        'cenario_demo','data_utc','google_ads_centavos',
        'meta_ads_centavos','total_centavos',
    ]);
    foreach($data->scenarios as $scenario) {
        foreach($scenario['daily'] as $day) {
            $emit([
                $scenario['scenario_ref'],
                $day['date'],
                $day['google_ads_centavos'],
                $day['meta_ads_centavos'],
                $day['total_centavos'],
            ]);
        }
    }
    rewind($out);
    $csv=stream_get_contents($out);
    if(!is_string($csv))throw new RuntimeException('CSV read failed');
    // No customer tokens, formulas, currency conversion, real account IDs.
    echo $csv;
} catch(Throwable) {
    fwrite(STDERR,"F66 failed to create safe CSV\n");
    exit(2);
} finally {
    fclose($out);
}
