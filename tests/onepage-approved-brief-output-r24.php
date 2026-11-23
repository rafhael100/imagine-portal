<?php
declare(strict_types=1);

// R24: trusted, injectable private source adapters must not leak accidental
// output on success, denial or exception. Reuse the real R23 gate, real
// candidate encoder and same synthetic order/site/brief; no new authority.
require __DIR__.'/onepage-approved-brief-export-gate-offline.php';

$r24Checks = 0;
$verifyR24 = static function (bool $condition, string $label) use (&$r24Checks): void {
    if (!$condition) throw new RuntimeException('ONEPAGE PORTAL OUTPUT R24 FAILED: '.$label);
    ++$r24Checks;
};
$probeR24 = static function () use ($gate, $contract, $site): array {
    $level = ob_get_level();
    ob_start(); // caller-owned buffer must survive the gate
    $value = null;
    $error = null;
    try {
        $value = $gate->prepare($contract->orderRef, $site, 12);
    } catch (Throwable $caught) {
        $error = $caught;
    }
    $levelRestored = ob_get_level() === $level + 1;
    // If an old implementation left nested adapter buffers behind, clean up
    // only inside the test after recording the drift; never send their bytes.
    $fragments = [];
    while (ob_get_level() > $level) {
        $fragments[] = ob_get_clean();
    }
    return [
        'value' => $value,
        'error' => $error,
        'levelRestored' => $levelRestored,
        'output' => implode('', $fragments),
        'callerRestored' => ob_get_level() === $level,
    ];
};
$closedR24 = static function (array $probe): bool {
    return $probe['value'] === null &&
        $probe['error'] instanceof RuntimeException &&
        $probe['error']->getMessage() === 'ONEPAGE_APPROVED_BRIEF_EXPORT_DENIED' &&
        $probe['error']->getPrevious() === null;
};

$marker = 'SYNTHETIC_PRIVATE_BRIEF_MARKER_NEVER_EXPOSE';
// First prove a successful candidate cannot inadvertently echo private source.
$reset();
$briefs->onSecondRead = static function () use ($marker): void { echo $marker; };
$positive = $probeR24();
$verifyR24($positive['error'] === null && $positive['value'] === $expected,
    'accidental source echo does not change an otherwise valid exact candidate');
$verifyR24($positive['output'] === '' && $positive['levelRestored'] && $positive['callerRestored'],
    'successful private export discards accidental output and restores caller buffer');
$verifyR24($contracts->calls === 3 && $briefs->calls === 2 && $witness->calls === 3,
    'output guard preserves all independent contract/source/approval reads');
foreach (['authorityVerified','clientAccepted','canPublish','canAcceptRealOrders'] as $flag) {
    $verifyR24($positive['value'][$flag] === false,
        'accidental output does not promote authority '.$flag);
}

// Revocation during the second private read denies uniformly and discards the
// content that would otherwise be emitted BEFORE the exception to the caller.
$reset();
$briefs->onSecondRead = static function () use ($marker, $witness): void {
    echo $marker;
    $witness->approved = false;
};
$revoked = $probeR24();
$verifyR24($closedR24($revoked), 'revocation during echoed private read denies uniformly');
$verifyR24($revoked['output'] === '' && $revoked['levelRestored'] && $revoked['callerRestored'],
    'revoked source cannot leak accidentally echoed private bytes');
$verifyR24($contracts->calls === 2 && $briefs->calls === 2 && $witness->calls === 3,
    'revocation stops before final contract read, without another private read');

// A trusted adapter can open an additional buffer. Both gate-owned and
// adapter-owned buffers must be removed without touching the caller's buffer.
$reset();
$briefs->onSecondRead = static function () use ($marker): void {
    ob_start();
    echo $marker;
};
$nested = $probeR24();
$verifyR24($nested['error'] === null && $nested['value'] === $expected,
    'nested adapter buffer does not change valid candidate');
$verifyR24($nested['output'] === '' && $nested['levelRestored'] && $nested['callerRestored'],
    'nested adapter buffer is discarded, preserving caller buffer');

// Even exception paths must clean every nested buffer and return the uniform
// error, without the exception message, reference or private marker.
$reset();
$briefs->onSecondRead = static function () use ($marker): void {
    ob_start();
    echo $marker;
    throw new RuntimeException('synthetic private adapter internal exception');
};
$thrown = $probeR24();
$verifyR24($closedR24($thrown), 'adapter exception is sanitized');
$verifyR24($thrown['output'] === '' && $thrown['levelRestored'] && $thrown['callerRestored'],
    'adapter exception cannot leak output or corrupt caller buffer');
$verifyR24($contracts->calls === 2 && $briefs->calls === 2 && $witness->calls === 2,
    'adapter exception stops before further approval/contract reads');

echo "ONEPAGE PORTAL OUTPUT R24 PASS: {$r24Checks} synthetic checks; NO-GO.\n";
