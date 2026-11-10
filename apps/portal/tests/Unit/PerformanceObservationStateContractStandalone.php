<?php
declare(strict_types=1);
require_once dirname(__DIR__,2).'/app/Domain/PerformanceDashboard/PerformanceCampaignObservationState.php';
require_once dirname(__DIR__,2).'/app/Domain/PerformanceDashboard/PerformanceReviewObservationState.php';

use App\Domain\PerformanceDashboard\PerformanceCampaignObservationState as Campaign;
use App\Domain\PerformanceDashboard\PerformanceReviewObservationState as Review;

$n=0;
$check=static function(bool $value,string $label)use(&$n):void{
    if(!$value)throw new RuntimeException('PORTAL-F61 '.$label);
    ++$n;
};
$check(array_column(Campaign::cases(),'value')===['draft_demo','paused_demo','archived_demo'],'exact campaign vocabulary');
$check(array_column(Review::cases(),'value')===['pending_human_demo','approved_demo_no_execution','rejected_demo'],'exact review vocabulary');
foreach(Campaign::cases() as $s) {
    $check(str_contains($s->label(),'DEMO'),'campaign explicitly DEMO');
    $check(!$s->externallyConfirmed(),'no provider confirmation');
    $check(!$s->canExecute(),'no actions');
    $check(Campaign::from($s->value)===$s,'roundtrip campaign');
}
foreach(Review::cases() as $s) {
    $check(str_contains($s->label(),'DEMO'),'review explicitly DEMO');
    $check(!$s->authorizesExternalOperation(),'not authorization');
    $check(!$s->isRealApproval(),'no real approval');
    $check(Review::from($s->value)===$s,'roundtrip review');
}
foreach(['active','published','serving','running','mock_review','mock_draft','approved_internal','paused','', 'META'] as $invalid){
    $check(Campaign::tryFrom($invalid)===null,'reject unsanctioned campaign state');
    $check(Review::tryFrom($invalid)===null,'reject unsanctioned review state');
}
echo "PASS PORTAL-F61 {$n} display-only status assertions\n";
