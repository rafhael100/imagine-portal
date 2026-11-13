<?php
declare(strict_types=1);

// F381 integration: real restricted Portal PG18 SQL readers and the real
// signed F376 Worker HTTPS v2 in one DISPOSABLE CI network namespace.
if(PHP_SAPI!=='cli'||$argc!==1
    ||getenv('GITHUB_ACTIONS')!=='true'
    ||getenv('RUNNER_ENVIRONMENT')!=='github-hosted'
    ||getenv('PORTAL_F381_CI')!=='DISPOSABLE_ONLY'
    ||getenv('PORTAL_F374_CI')!=='DISPOSABLE_ONLY')exit(64);

use App\Infrastructure\Identity\PostgresPortalBrowserAuthentication;
use App\Infrastructure\Identity\PostgresPortalPermissionPreflight;
use App\Infrastructure\Recovery\ChallengeBoundWitnessCandidateVerifier;
use App\Infrastructure\Recovery\NonceBoundHttpsWitnessReadCandidate;
use App\Infrastructure\Recovery\NonceBoundPortalIdentityReadCandidate;
use App\Infrastructure\Recovery\PostgresPortalRecoveryEpochCandidateReader;

try {
    $runtime=json_decode((string)file_get_contents('/run/secrets/runtime.json'),
        true,8,JSON_THROW_ON_ERROR);
    if(!is_array($runtime)
        ||($runtime['database']??null)!=='portal_identity_dev'
        ||!is_string($runtime['proof_key']??null)
        ||!is_string($runtime['identity_password']??null)
        ||!is_string($runtime['scope_pepper']??null)
        ||!is_string($runtime['totp_key']??null))throw new RuntimeException();

    $dsn='pgsql:host=127.0.0.1;port=5432;dbname=portal_identity_dev';
    $pdoOptions=[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_EMULATE_PREPARES=>false];
    $runtimeDb=new PDO($dsn,'portal_browser_runtime',
        $runtime['identity_password'],$pdoOptions);
    $auth=new PostgresPortalBrowserAuthentication($runtimeDb,'portal_identity_dev',
        $runtime['proof_key'],
        (string)base64_decode($runtime['totp_key'],true),
        $runtime['scope_pepper']
    );
    $permissions=new PostgresPortalPermissionPreflight($runtimeDb,'portal_identity_dev');

    $adminPassword=trim((string)file_get_contents('/run/secrets/database-admin'));
    $admin=new PDO($dsn,'postgres',$adminPassword,$pdoOptions);
    unset($adminPassword,$runtime);
    $oid=$admin->query('SELECT organization_id FROM public.portal_recovery_epoch_candidates')
        ->fetchColumn();
    if(!is_string($oid)||!preg_match('/\A[0-9a-f-]{36}\z/D',$oid)) {
        throw new RuntimeException();
    }
    $admin->exec('SET ROLE portal_recovery_epoch_reader');
    $local=new PostgresPortalRecoveryEpochCandidateReader(
        $admin,'portal_identity_dev',$oid,'f373_ci'
    );
    $state=$local->currentEpoch();
    if($state['organization']!==$oid||$state['instance']!=='f373_ci'
        ||$state['generation']!==1||$state['session_revision']!==4
        ||$state['membership_revision']!==7||$state['witnessed_sequence']!==13) {
        throw new RuntimeException();
    }

    $dir='/opt/f381';
    $public=trim((string)file_get_contents($dir.'/ed-public.hex'));
    $pin=trim((string)file_get_contents($dir.'/tls-pin.txt'));
    $reader=new NonceBoundHttpsWitnessReadCandidate(
        $local,new ChallengeBoundWitnessCandidateVerifier(),
        'https://witness.f380.test.invalid:18446/v2/portal/recovery-witness',
        $pin,$public,$oid,'f373_ci',
        $dir.'/leaf.crt','witness.f380.test.invalid:18446:127.0.0.1',
    );
    $identity=new NonceBoundPortalIdentityReadCandidate(
        $auth,$permissions,$reader,$oid
    );
    $reference=str_repeat('c',64);
    if(!$auth->isActiveBusinessSession($reference)){
        throw new RuntimeException('Real Portal session was not active');
    }
    $checks=0;
    $check=static function(bool $ok)use(&$checks):void{
        if(!$ok)throw new RuntimeException('F381 isolated assertion denied');
        $checks++;
    };
    $verify=static function(array $result,string $status)use($check):void{
        $check(($result['status']??null)===$status);
        foreach(['canAuthorizeHttp','canAuthorizeBusiness','canIssueSession',
                 'canRecoverFromRestore','canDeploy'] as $flag) {
            $check(($result[$flag]??null)===false);
        }
    };
    $scenario=static function(string $value)use($dir):void{
        if(file_put_contents($dir.'/scenario',$value."\n")===false)
            throw new RuntimeException();
    };
    $scenario('ok');
    $verify($identity->inspectBusinessSession($reference),
        'LAB_CHALLENGED_READ_STILL_NO_SERVING');
    $verify($identity->inspectBusinessSession(str_repeat('d',64)),
        'LAB_CHALLENGED_DENIAL_STILL_NO_SERVING');
    $verify($identity->inspectTenantPermission($reference,$oid,'services.view'),
        'LAB_CHALLENGED_DENIAL_STILL_NO_SERVING');
    $verify($identity->inspectTenantPermission($reference,$oid,'services.manage'),
        'LAB_CHALLENGED_DENIAL_STILL_NO_SERVING');

    // Invalid inputs and another tenant are rejected BEFORE any network call.
    $before=file_get_contents($dir.'/requests.jsonl');
    $verify($identity->inspectBusinessSession('wrong-session'),
        'INVALID_CANDIDATE_REFERENCE');
    $verify($identity->inspectTenantPermission($reference,
        '11111111-1111-4111-8111-111111111111','services.view'),
        'CANDIDATE_SCOPE_OR_PERMISSION_DENIED');
    $verify($identity->inspectTenantPermission($reference,$oid,'not.a.grant'),
        'CANDIDATE_SCOPE_OR_PERMISSION_DENIED');
    $check(file_get_contents($dir.'/requests.jsonl')===$before);

    $scenario('replay');
    $verify($identity->inspectBusinessSession($reference),
        'WITNESS_PRECHECK_DENIED');
    $scenario('tamper');
    $verify($identity->inspectBusinessSession($reference),
        'WITNESS_PRECHECK_DENIED');
    $scenario('unavailable');
    $verify($identity->inspectBusinessSession($reference),
        'WITNESS_PRECHECK_DENIED');
    $scenario('advanced');
    $verify($identity->inspectBusinessSession($reference),
        'WITNESS_PRECHECK_DENIED');

    // Local epoch moves forward in isolated CI (admin only). The old
    // independent witness remains behind and MUST be refused.
    $scenario('ok');
    $admin->exec('RESET ROLE');
    $admin->exec("UPDATE public.portal_recovery_epoch_candidates
                    SET session_revision=5,witnessed_sequence=14
                  WHERE organization_id='$oid'");
    $admin->exec('SET ROLE portal_recovery_epoch_reader');
    $verify($identity->inspectBusinessSession($reference),
        'WITNESS_PRECHECK_DENIED');
    $scenario('advanced');
    $verify($identity->inspectBusinessSession($reference),
        'LAB_CHALLENGED_READ_STILL_NO_SERVING');

    // A live, now-revoked session cannot be authorized by a valid receipt.
    $check($auth->revoke($reference));
    $check(!$auth->isActiveBusinessSession($reference));
    $verify($identity->inspectBusinessSession($reference),
        'LAB_CHALLENGED_DENIAL_STILL_NO_SERVING');

    // Any privilege drift in the actual reader's DB connection denies
    // before requesting an externally signed witness.
    $admin->exec('RESET ROLE');
    $verify($identity->inspectBusinessSession($reference),
        'WITNESS_PRECHECK_DENIED');
    $requests=file($dir.'/requests.jsonl',FILE_IGNORE_NEW_LINES);
    $check(is_array($requests)&&count($requests)>=18);
    $nonces=[];
    foreach($requests as $line) {
        $r=json_decode($line,true,8,JSON_THROW_ON_ERROR);
        $check(is_string($r['nonce']??null)
            &&preg_match('/\A[a-f0-9]{64}\z/D',$r['nonce'])===1);
        $nonces[]=$r['nonce'];
    }
    $check(count($nonces)===count(array_unique($nonces)));
    echo "PASS F381: {$checks} real PostgreSQL18 restricted sessions/grants + signed nonce HTTPS Worker; stale/replay/revoked/role drift denied, NO SERVING.\n";
} catch(Throwable) {
    fwrite(STDERR,"FAIL F381: real PG18 + Worker v2 challenged identity rehearsal\n");
    exit(1);
}
