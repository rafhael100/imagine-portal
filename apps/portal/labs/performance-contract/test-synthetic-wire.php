<?php

declare(strict_types=1);

require_once __DIR__.'/SyntheticWireEnvelope.php';

use Imagine\Protocol\Lab\SyntheticWireEnvelope as Wire;

$sample = __DIR__.'/perf-revocation-event-example-v1.json';
$body = file_get_contents($sample);
if (!is_string($body)) {
    throw new RuntimeException('Missing exact Portal PERF-F11 mock event.');
}
$key = random_bytes(32);
$now = 1800000000;
$nonce = 'nonce-demo-wire-source001';
$h = Wire::signForLab($body, $key, $now, $nonce);
$verify = static fn (
    string $method = Wire::METHOD,
    string $path = Wire::PATH,
    string $data = '',
    array $headers = [],
    string $testKey = '',
    int $clock = 1800000000
): string => Wire::verifyForLab(
    $method, $path, $data === '' ? $body : $data,
    $headers === [] ? $h : $headers, $testKey === '' ? $key : $testKey, $clock,
);
$count = 0;
$check = static function (string $label, mixed $actual, mixed $wanted) use (&$count): void {
    if ($actual !== $wanted) {
        throw new RuntimeException('FAIL '.$label.' actual='.var_export($actual, true)
            .' expected='.var_export($wanted, true));
    }
    ++$count;
    echo "PASS $count $label\n";
};

$check('01 valid signed Portal event', $verify(), 'wire_valid_demo');
$check('02 correct simulated HTTP method', Wire::METHOD, 'POST');
$check('03 correct simulated audience path', Wire::PATH, '/_synthetic/perf/revocation');
$check('04 version is pinned', $h['X-Imagine-Lab-Version'], 'perf.wire.synthetic.v1');
$check('05 test-only key id is pinned', $h['X-Imagine-Lab-Key-Id'], 'kid-demo-portal-1');
$check('06 payload is SHA-256 bound', $h['X-Imagine-Lab-Body-SHA256'], hash('sha256', $body));
$check('07 unsigned GET fails', $verify(method: 'GET'), 'wire_wrong_target_demo');
$check('08 wrong target fails', $verify(path: '/_synthetic/other'), 'wire_wrong_target_demo');
$check('09 body padding fails', $verify(data: $body.' '), 'wire_body_modified_demo');
$check('10 different secret fails', $verify(testKey: random_bytes(32)), 'wire_bad_signature_demo');
$check('11 expiry fails', $verify(clock: $now + 121), 'wire_expired_demo');
$check('12 tolerated synthetic 120-second window', $verify(clock: $now + 120), 'wire_valid_demo');
$check('13 synthetic future beyond 15-second skew fails', $verify(clock: $now - 16), 'wire_expired_demo');
$check('14 15-second tolerance at edge', $verify(clock: $now - 15), 'wire_valid_demo');

$mutate = static function (string $field, string $v) use ($h): array {
    $new = $h;
    $new[$field] = $v;
    return $new;
};
$check('15 wrong key id refused',
    $verify(headers: $mutate('X-Imagine-Lab-Key-Id', 'kid-live')), 'wire_wrong_identity_demo');
$check('16 wrong protocol version refused',
    $verify(headers: $mutate('X-Imagine-Lab-Version', 'v2')), 'wire_wrong_identity_demo');
$check('17 nonce mutation breaks MAC',
    $verify(headers: $mutate('X-Imagine-Lab-Nonce', 'nonce-demo-wire-variant002')), 'wire_bad_signature_demo');
$check('18 malformed digest refused',
    $verify(headers: $mutate('X-Imagine-Lab-Body-SHA256', 'foobar')), 'wire_invalid_headers_demo');
$check('19 malformed MAC refused',
    $verify(headers: $mutate('X-Imagine-Lab-Signature', 'xx')), 'wire_invalid_headers_demo');
$check('20 short test key denied',
    $verify(testKey: 'weak'), 'wire_missing_key_demo');
$check('21 empty payload denied',
    Wire::verifyForLab('POST',Wire::PATH,'',$h,$key,$now), 'wire_invalid_size_demo');
$check('22 oversized payload denied',
    $verify(data: str_repeat('x', Wire::MAX_BYTES + 1)), 'wire_invalid_size_demo');
$missing = $h;
unset($missing['X-Imagine-Lab-Nonce']);
$check('23 missing header denied',
    $verify(headers: $missing), 'wire_invalid_headers_demo');
$extra = $h;
$extra['X-Imagine-Lab-Authorization'] = 'Bearer fake';
$check('24 injected header denied',
    $verify(headers: $extra), 'wire_invalid_headers_demo');
$duplicate = $h;
$duplicate['x-imagine-lab-nonce'] = 'nonce-demo-wire-other003';
$check('25 duplicate case-folded nonce denied',
    $verify(headers: $duplicate), 'wire_invalid_headers_demo');
$invalid = $mutate('X-Imagine-Lab-Timestamp', '1800000000x');
$check('26 invalid timestamp format denied',
    $verify(headers: $invalid), 'wire_invalid_headers_demo');
try {
    Wire::signForLab('not-json', $key, $now, $nonce);
    throw new RuntimeException('Invalid JSON was signed');
} catch (InvalidArgumentException) {
    $check('27 signer refuses invalid JSON', true, true);
}
try {
    Wire::signForLab($body, 'short', $now, $nonce);
    throw new RuntimeException('Weak key was accepted');
} catch (InvalidArgumentException) {
    $check('28 signer refuses weak key', true, true);
}
try {
    Wire::signForLab($body, $key, $now, 'invalid-nonce');
    throw new RuntimeException('Invalid nonce accepted');
} catch (InvalidArgumentException) {
    $check('29 signer refuses unknown nonce syntax', true, true);
}
try {
    Wire::signForLab(str_repeat('X', Wire::MAX_BYTES + 1), $key, $now, $nonce);
    throw new RuntimeException('Oversize signed');
} catch (InvalidArgumentException) {
    $check('30 signer enforces body size', true, true);
}
$check('31 exact 6 signed headers', count($h), 6);
$check('32 no user session/identity fields in transport headers',
    array_key_exists('Authorization', $h) || array_key_exists('Cookie', $h), false);
echo "PASS $count SyntheticWireEnvelope protocol checks (no operational identity)\n";
