<?php
declare(strict_types=1);

/**
 * F394 PHP verifies exact Worker F393 ACK signature AFTER a separate
 * restricted PG18 status check; neither observation grants serving.
 */
if(PHP_SAPI!=='cli'||$argc!==1
  ||getenv('GITHUB_ACTIONS')!=='true'
  ||getenv('RUNNER_ENVIRONMENT')!=='github-hosted'
  ||getenv('PORTAL_F394_CI')!=='DISPOSABLE_ONLY')exit(64);
try {
  require '/var/www/html/vendor/autoload.php';
  $dir='/opt/f390';
  $read=static function(string $name)use($dir):array {
    $path=$dir.'/'.$name;
    if(!is_file($path)||is_link($path)||filesize($path)<2||filesize($path)>4096)throw new RuntimeException();
    $v=json_decode((string)file_get_contents($path),true,8,JSON_THROW_ON_ERROR);
    if(!is_array($v))throw new RuntimeException();
    return $v;
  };
  $seed=$read('seed.json');
  $receipt=$read('ack-receipt.json');
  $expected=$read('ack-expected.json');
  $pub=$read('public.json')['hex']??'';
  $digest=$seed['session_sha256'];
  $org=$seed['organization'];
  $instance=$seed['instance'];
  if($expected['organization']!==$org||$expected['instance']!==$instance
      ||$expected['session_sha256']!==$digest
      ||array_key_exists('session_reference',$seed))throw new RuntimeException();

  $verify=new App\Infrastructure\Recovery\SignedRevocationAckClaimCandidateVerifier();
  $result=$verify->verify($receipt,$org,$instance,
      $expected['intent_id'],$digest,$expected['nonce'],$pub,time());
  $checks=0;
  $check=static function(bool $yes)use(&$checks):void{
    if(!$yes)throw new RuntimeException('F394 signed ACK invariant');
    $checks++;
  };
  $check(($result['reasonCode']??null)==='ACK_CLAIM_RECORDED_NOT_POSTGRES_PROOF_NO_SERVING');
  foreach(['canAuthorizeHttp','canAuthorizeBusiness','canIssueSession',
    'canRecoverFromRestore','canDeploy','canClaimPostgresRevoked',
    'canClaimQueueFullyProcessed']as$flag){
    $check(($result[$flag]??null)===false);
  }
  $bad=$verify->verify($receipt,$org,$instance,
      $expected['intent_id'],$digest,str_repeat('0',64),$pub,time());
  $check(($bad['reasonCode']??null)==='ACK_REQUEST_BINDING_DENIED');

  $keys=json_decode((string)file_get_contents('/run/secrets/runtime.json'),
    true,8,JSON_THROW_ON_ERROR);
  $dsn='pgsql:host=127.0.0.1;port=5432;dbname=portal_identity_dev';
  $options=[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_EMULATE_PREPARES=>false];
  $runtime=new PDO($dsn,'portal_browser_runtime',$keys['identity_password'],$options);
  $auth=new App\Infrastructure\Identity\PostgresPortalBrowserAuthentication(
    $runtime,'portal_identity_dev',$keys['proof_key'],
    (string)base64_decode($keys['totp_key'],true),$keys['scope_pepper']);
  unset($keys);
  $check($auth->inspectSessionDigestStatus($digest,$org)==='revoked');
  $check($auth->inspectSessionDigestStatus(hash('sha256','f394-nonexistent'),$org)==='missing');

  echo "PASS F394 receipt: {$checks} real Worker F393 Ed25519 ACK verified alongside separate PG18 revoked status, NO SERVING.\n";
}catch(Throwable){
  fwrite(STDERR,"FAIL F394: disposable Worker ACK/PG18 final readback\n");
  exit(1);
}
