<?php

declare(strict_types=1);

require __DIR__.'/PortalF105OfflineGscTokenReview.php';

function expect(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}
putenv('APP_ENV=testing');
putenv('PORTAL_F105_GSC_LAB=1');

$valid = [
    'access_token' => str_repeat('A', 32),
    'expires_in' => 3600,
    'scope' => 'https://www.googleapis.com/auth/webmasters.readonly',
    'token_type' => 'Bearer',
];

$accepted = PortalF105OfflineGscTokenReview::inspect($valid);
expect($accepted['readonlyScopeVerifiedOffline'] === true, 'readonly synthetic scope');
expect($accepted['tokenShapeReviewedOffline'] === true, 'shape recognized offline');
expect($accepted['productionReadAuthorized'] === false, 'never grants production access');
expect($accepted['tokensStored'] === false && $accepted['networkPerformed'] === false, 'no token storage/network');
expect(!str_contains(json_encode($accepted), $valid['access_token']), 'never return token');
expect($accepted['authorization'] === 'HOLD_AUTHENTICATED_BACKEND_AND_TENANT_VERIFICATION', 'hold required');

$withRefresh = $valid + ['refresh_token' => str_repeat('R', 48)];
expect(PortalF105OfflineGscTokenReview::inspect($withRefresh)['tokenShapeReviewedOffline'], 'optional refresh shape');
foreach ([
    [...$valid, 'scope' => 'https://www.googleapis.com/auth/webmasters'],
    [...$valid, 'scope' => $valid['scope'].' https://www.googleapis.com/auth/webmasters'],
    [...$valid, 'scope' => 'https://www.googleapis.com/auth/drive.readonly'],
    [...$valid, 'token_type' => 'bearer'],
    [...$valid, 'expires_in' => '3600'],
    [...$valid, 'expires_in' => 0],
    [...$valid, 'expires_in' => 3601],
    [...$valid, 'refresh_token' => 'short'],
    [...$valid, 'access_token' => 'x'],
    [...$valid, 'id_token' => str_repeat('I', 40)],
    [...$valid, 'oauthVerified' => true],
    ['access_token' => str_repeat('A', 32)],
    null, [], 'not-an-object',
] as $bad) {
    $result = PortalF105OfflineGscTokenReview::inspect($bad);
    expect($result['tokenShapeReviewedOffline'] === false, 'reject forged/incomplete response');
    expect($result['productionReadAuthorized'] === false, 'never grants access on failure');
    expect(!str_contains(json_encode($result), str_repeat('A', 32)), 'never echo tokens');
}
putenv('PORTAL_F105_GSC_LAB=0');
expect(PortalF105OfflineGscTokenReview::inspect($valid)['tokenShapeReviewedOffline'] === false, 'disabled lab denies');
expect(PortalF105OfflineGscTokenReview::authorizesRealGoogleAccess() === false, 'never authorizes Google');
echo "PORTAL-GSC-F105 offline token response review: PASS\n";
