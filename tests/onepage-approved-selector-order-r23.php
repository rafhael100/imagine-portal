<?php
declare(strict_types=1);

// R23: A versioned selector is a typed associative record, not an ordered
// sequence. Reuse the existing fake immutable OnePage order and real encoder;
// do not create a new approval, buyer authority or operational data source.
require __DIR__.'/onepage-approved-brief-export-gate-offline.php';

$r23Checks = 0;
$verifyR23 = static function (bool $condition, string $label) use (&$r23Checks): void {
    if (!$condition) throw new RuntimeException('ONEPAGE PORTAL SELECTION R23 FAILED: '.$label);
    ++$r23Checks;
};

$reset();
$briefs->next = $makeRecord(selector: array_reverse($selection, true));
$reordered = $gate->prepare($contract->orderRef, $site, 12);
$verifyR23($reordered === $expected && $reordered['sourceBytes'] === $source &&
    $reordered['payloadSha256'] === hash('sha256', $source),
    'same seven typed selection fields in different insertion order must preserve original source bytes');
$verifyR23($contracts->calls === 3 && $briefs->calls === 2 && $witness->calls === 3,
    'reordered selection still requires two exact private reads and independent current witnesses');
foreach (['authorityVerified','clientAccepted','canPublish','canAcceptRealOrders'] as $flag) {
    $verifyR23($reordered[$flag] === false,
        'reordered selection never promotes commercial authority '.$flag);
}

$expectClosedR23 = static function (array $changed, string $label)
    use ($reset, $briefs, $makeRecord, $gate, $contract, $site, $verifyR23, $contracts, $witness): void {
    $reset();
    $briefs->next = $makeRecord(selector: $changed);
    $closed = false;
    try {
        $gate->prepare($contract->orderRef, $site, 12);
    } catch (RuntimeException $error) {
        $closed = $error->getMessage() === 'ONEPAGE_APPROVED_BRIEF_EXPORT_DENIED' &&
            $error->getPrevious() === null;
    }
    $verifyR23($closed, $label.' must deny with the uniform error');
    $verifyR23($briefs->calls === 2 && $contracts->calls === 2 && $witness->calls === 2,
        $label.' must stop before any final witness or further private source reads');
};

// Do not accidentally change === to ==. The string "0" is not the
// integer 0, even when other selector fields and the payload digest match.
$expectClosedR23(
    array_replace($selection, ['expectedBriefRevision' => '0']),
    'numeric-looking selector type drift',
);
$expectClosedR23(
    array_replace($selection, ['payloadRef' => 'synthetic-other-payload']),
    'actual payload reference drift',
);
$expectClosedR23(
    array_replace($selection, ['idempotencyKey' => 'synthetic-other-key']),
    'idempotency reference drift',
);

echo "ONEPAGE PORTAL SELECTION R23 PASS: {$r23Checks} new synthetic checks; NO-GO.\n";
