<?php
declare(strict_types=1);

/** Only run in disposable Actions job bound to 127.0.0.1. */
if (PHP_SAPI!=='cli-server' ||
    getenv('APP_ENV')!=='testing' ||
    getenv('PORTAL_GSC_F116_LOOPBACK_LAB')!=='1' ||
    ($_SERVER['REMOTE_ADDR']??null)!=='127.0.0.1' ||
    ($_SERVER['REQUEST_URI']??null)!=='/token' ||
    ($_SERVER['REQUEST_METHOD']??null)!=='POST') {
    http_response_code(404);
    exit;
}
header('Content-Type: application/json');
header('Cache-Control: no-store');
$length=(int)($_SERVER['CONTENT_LENGTH']??0);
if($length<1||$length>8192){
    http_response_code(413);echo '{}';exit;
}
$body=file_get_contents('php://input');
parse_str((string)$body,$form);
$fields=['client_id','client_secret','code','code_verifier',
    'redirect_uri','grant_type'];
if(!is_array($form) || array_keys($form)!==$fields ||
    ($form['client_id']??null)!==
        '1234567890-f116syntheticonly.apps.googleusercontent.com' ||
    ($form['client_secret']??null)!=='synthetic-secret-f116-private-only' ||
    ($form['redirect_uri']??null)!==
        'https://portal.example.test/integrations/google/search-console/callback' ||
    ($form['grant_type']??null)!=='authorization_code' ||
    !is_string($form['code_verifier']??null) ||
    preg_match('/\A[A-Za-z0-9_-]{43}\z/D',
        $form['code_verifier'])!==1) {
    http_response_code(401);echo '{"error":"invalid_client"}';exit;
}
$scenario=$form['code'];
$valid=[
 'access_token'=>'synthetic-only-access-token-1234567890',
 'expires_in'=>300,
 'scope'=>'https://www.googleapis.com/auth/webmasters.readonly',
 'token_type'=>'Bearer',
 'refresh_token'=>'synthetic-only-refresh-token-1234567890',
];
switch($scenario){
    case 'synthetic-good-with-refresh':
        echo json_encode($valid,JSON_THROW_ON_ERROR);
        break;
    case 'synthetic-good-without-refresh':
        unset($valid['refresh_token']);
        echo json_encode($valid,JSON_THROW_ON_ERROR);
        break;
    case 'synthetic-redirect':
        http_response_code(302);
        header('Location: http://127.0.0.1:18765/never-follow');
        echo '{"error":"redirect"}';
        break;
    case 'synthetic-denied-scope':
        $valid['scope']='https://www.googleapis.com/auth/webmasters';
        echo json_encode($valid,JSON_THROW_ON_ERROR);
        break;
    case 'synthetic-bad-expiry':
        $valid['expires_in']=86400;
        echo json_encode($valid,JSON_THROW_ON_ERROR);
        break;
    case 'synthetic-bad-type':
        $valid['token_type']='Basic';
        echo json_encode($valid,JSON_THROW_ON_ERROR);
        break;
    case 'synthetic-extra-field':
        $valid['id_token']='synthetic-not-allowed';
        echo json_encode($valid,JSON_THROW_ON_ERROR);
        break;
    case 'synthetic-invalid-refresh':
        $valid['refresh_token']='short';
        echo json_encode($valid,JSON_THROW_ON_ERROR);
        break;
    case 'synthetic-bad-json':
        echo '{"access_token":';
        break;
    case 'synthetic-oversize':
        echo str_repeat('a',20000);
        break;
    case 'synthetic-http-500':
        http_response_code(500);
        echo '{"error":"temporarily_unavailable"}';
        break;
    case 'synthetic-unauthenticated':
        http_response_code(401);
        echo '{"error":"invalid_client"}';
        break;
    case 'synthetic-content-type':
        header('Content-Type: text/plain');
        echo json_encode($valid,JSON_THROW_ON_ERROR);
        break;
    case 'synthetic-empty':
        echo '';
        break;
    case 'synthetic-malformed-token':
        $valid['access_token']="\nINVALID";
        echo json_encode($valid,JSON_THROW_ON_ERROR);
        break;
    case 'synthetic-noninteger-expiry':
        $valid['expires_in']='300';
        echo json_encode($valid,JSON_THROW_ON_ERROR);
        break;
    default:
        http_response_code(400);
        echo '{"error":"invalid_request"}';
}
