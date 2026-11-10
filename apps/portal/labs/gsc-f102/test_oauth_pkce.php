<?php

declare(strict_types=1);

require __DIR__.'/PortalF102OfflineGscOAuth.php';

function check(bool $ok, string $message): void {
    if (!$ok) { throw new RuntimeException($message); }
}
putenv('APP_ENV=testing');
putenv('PORTAL_F102_GSC_LAB=1');
$actor = '123e4567-e89b-42d3-a456-426614174000';
$otherActor = '123e4567-e89b-42d3-a456-426614174099';
$org = '123e4567-e89b-42d3-a456-426614174001';
$otherOrg = '123e4567-e89b-42d3-a456-426614174002';
$property = str_repeat('a', 64);
$callback = 'https://portal.imaginecomunicacao.com.br/oauth/google/callback';
$now = 1794330000;
$flow = new PortalF102OfflineGscOAuth();
$first = $flow->begin($actor, $org, $property, $callback, $now);
check(is_array($first), 'valid synthetic transaction');
check($first['requiredScope'] === 'https://www.googleapis.com/auth/webmasters.readonly', 'readonly scope');
check($first['pkceMethod'] === 'S256', 'PKCE S256');
check(strlen($first['state']) === 43 && strlen($first['pkceVerifier']) === 43, '256 bit random tokens');
check($first['pkceChallenge'] === rtrim(strtr(base64_encode(hash('sha256', $first['pkceVerifier'], true)), '+/', '-_'), '='), 'PKCE digest');
check($first['authorization'] === 'HOLD' && $first['networkPerformed'] === false, 'HOLD/no network');
check(!$flow->consume($first['state'], $actor, $otherOrg, $property, $callback, $now), 'cross tenant denied');
check(!$flow->consume($first['state'], $actor, $org, $property, $callback, $now), 'replay after failed consume denied');
$second = $flow->begin($actor, $org, $property, $callback, $now);
check(is_array($second) && $second['state'] !== $first['state'], 'unique state');
check(!$flow->consume($second['state'], $otherActor, $org, $property, $callback, $now), 'cross actor denied');
$third = $flow->begin($actor, $org, $property, $callback, $now);
check(!$flow->consume($third['state'], $actor, $org, $property, $callback, $now + 301), 'expired state denied');
$fourth = $flow->begin($actor, $org, $property, $callback, $now);
check($flow->consume($fourth['state'], $actor, $org, $property, $callback, $now + 20), 'matching synthetic transaction');
check(!$flow->consume($fourth['state'], $actor, $org, $property, $callback, $now + 20), 'state single use');
foreach ([
    'http://portal.imaginecomunicacao.com.br/oauth/google/callback',
    'https://evil.example/oauth/google/callback',
    'https://portal.imaginecomunicacao.com.br/oauth/google/callback?next=evil',
    'https://portal.imaginecomunicacao.com.br/oauth/google/callback#frag',
] as $invalid) {
    check($flow->begin($actor, $org, $property, $invalid, $now) === null, 'callback denylist');
}
check($flow->begin($actor, 'other-tenant', $property, $callback, $now) === null, 'invalid tenant UUID');
check($flow->begin($actor, $org, 'not-a-fingerprint', $callback, $now) === null, 'bad property digest');
putenv('PORTAL_F102_GSC_LAB=0');
check($flow->begin($actor, $org, $property, $callback, $now) === null, 'disabled lab');
check(!$flow->consume($first['state'], $actor, $org, $property, $callback, $now), 'disabled consume');
check(!PortalF102OfflineGscOAuth::authorizesGoogleAccess(), 'never grants Google');
check(!PortalF102OfflineGscOAuth::authorizesPortalHttp(), 'never grants HTTP');
echo "PORTAL-F102 synthetic OAuth/PKCE contract: PASS\n";
