<?php
declare(strict_types=1);
require_once dirname(__DIR__,2).'/app/Domain/SeoReview/OfflineGscWorkflowPreview.php';

use App\Domain\SeoReview\OfflineGscWorkflowPreview as Preview;

if (PHP_SAPI!=='cli' || count($argv)!==2
    || getenv('APP_ENV')!=='testing'
    || getenv('PORTAL_GSC_WORKFLOW_LAB')!=='1') {
    fwrite(STDERR,"GSC-F136: test-only execution required\n");
    exit(2);
}
$path=$argv[1];
if (!is_file($path) || is_link($path) || filesize($path)===false || filesize($path)>32768) {
    fwrite(STDERR,"GSC-F136: invalid synthetic output file\n");
    exit(2);
}
try {
    $content=file_get_contents($path);
    if ($content===false) throw new RuntimeException();
    $dto=json_decode($content,true,64,JSON_THROW_ON_ERROR);
} catch (Throwable) {
    fwrite(STDERR,"GSC-F136: malformed synthetic DTO\n");
    exit(2);
}
$preview=Preview::fromDto($dto);
if ($preview===null || $preview->status!=='OFFLINE_REVIEW_ONLY'
    || ($preview->summary['redactedRows']??null)!==1
    || ($preview->summary['clicks']??null)!==2
    || ($preview->pagination['rowsReceived']??null)!==2
    || $preview->isCustomerDisplayAuthorized()
    || $preview->canQueryGoogle() || $preview->canStoreTokens()
    || $preview->canPublish()) {
    fwrite(STDERR,"GSC-F136: contract parity failed\n");
    exit(1);
}
echo "GSC-F136 SEO Node to Portal PHP: synthetic HOLD contract valid\n";
