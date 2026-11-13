<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'||$argc!==1||getenv('GITHUB_ACTIONS')!=='true'
    ||getenv('RUNNER_ENVIRONMENT')!=='github-hosted'
    ||getenv('PORTAL_F373_CI')!=='DISPOSABLE_ONLY'
    ||getenv('PORTAL_F374_CI')!=='DISPOSABLE_ONLY')exit(64);

try {
    require '/opt/portal-recovery/test-f373-pg18.php';

    $db->exec('SET ROLE portal_recovery_epoch_reader');
    $local=new \App\Infrastructure\Recovery\PostgresPortalRecoveryEpochCandidateReader(
        $db,'portal_identity_dev',$oid,'f373_ci'
    );
    $snapshot=$local->currentEpoch();
    if($snapshot['organization']!==$oid||$snapshot['instance']!=='f373_ci'
        ||$snapshot['generation']!==1||$snapshot['session_revision']!==4
        ||$snapshot['membership_revision']!==7||$snapshot['witnessed_sequence']!==13) {
        throw new RuntimeException('F374 local epoch mismatch');
    }

    $now=time();
    $kp=sodium_crypto_sign_keypair();
    $secret=sodium_crypto_sign_secretkey($kp);
    $pub=bin2hex(sodium_crypto_sign_publickey($kp));
    $statement=[
        'schema'=>\App\Infrastructure\Recovery\IndependentWitnessCandidateVerifier::SCHEMA,
        'organization'=>$oid,'instance'=>'f373_ci',
        'generation_floor'=>1,'session_floor'=>4,'membership_floor'=>7,
        'sequence'=>13,'issued_at'=>$now,'expires_at'=>$now+60,
        'signature'=>'',
    ];
    $sign=static function(array $value)use(&$secret):array{
        $value['signature']=bin2hex(sodium_crypto_sign_detached(
            \App\Infrastructure\Recovery\IndependentWitnessCandidateVerifier::canonical($value),
            $secret
        ));
        return $value;
    };
    $statement=$sign($statement);
    $remote=new class($statement)implements \App\Infrastructure\Recovery\IndependentSignedWitnessTransport {
        public bool $offline=false;
        public function __construct(public array $receipt){}
        public function latestSignedWitness():array {
            if($this->offline)throw new RuntimeException('Synthetic remote witness unavailable');
            return $this->receipt;
        }
    };
    $source=new \App\Infrastructure\Recovery\LiveRecoveryWitnessSourceCandidate($local,$remote);
    $fence=new \App\Infrastructure\Recovery\OnlineWitnessFenceCandidate(
        $source,
        new \App\Infrastructure\Recovery\IndependentWitnessCandidateVerifier(),
        $pub,$oid,'f373_ci'
    );
    $check=static function(array $value,string $status):void{
        if(($value['status']??null)!==$status)throw new RuntimeException('F374 status mismatch');
        foreach(['canAuthorizeHttp','canAuthorizeBusiness','canIssueSession',
                 'canReissueCredential','canRecoverFromRestore','canDeploy']as$key){
            if(($value[$key]??null)!==false)throw new RuntimeException('F374 accidentally authorized');
        }
    };
    $check($fence->inspectReadOnly(static fn():bool=>true),'LAB_READ_OBSERVED_STILL_NO_SERVING');

    $remote->offline=true;
    $check($fence->inspectReadOnly(static fn():bool=>true),'WITNESS_PRECHECK_DENIED');
    $remote->offline=false;
    $remote->receipt['signature']=str_repeat('0',128);
    $check($fence->inspectReadOnly(static fn():bool=>true),'WITNESS_PRECHECK_DENIED');
    $remote->receipt=$statement;

    $db->exec('RESET ROLE');
    // The local epoch changes in a separate authorized (CI admin only)
    // transaction; a still-old external signature MUST be rejected.
    $db->exec("UPDATE public.portal_recovery_epoch_candidates
                 SET session_revision=5,witnessed_sequence=14
               WHERE instance='f373_ci'");
    $db->exec('SET ROLE portal_recovery_epoch_reader');
    $check($fence->inspectReadOnly(static fn():bool=>true),'WITNESS_PRECHECK_DENIED');

    $updated=$statement;
    $updated['session_floor']=5;
    $updated['sequence']=14;
    $remote->receipt=$sign($updated);
    $check($fence->inspectReadOnly(static fn():bool=>true),'LAB_READ_OBSERVED_STILL_NO_SERVING');

    // Reader must instantly fail if role identity is switched away,
    // even though its connection was initially legitimate.
    $db->exec('RESET ROLE');
    $check($fence->inspectReadOnly(static fn():bool=>true),'WITNESS_PRECHECK_DENIED');

    sodium_memzero($secret);
    echo "PASS F374: real quarantined Portal PG18 epoch read using NOLOGIN reader; stale, forged, missing and role-drift witness NO-GO; no serving.\n";
}catch(Throwable $e){
    fwrite(STDERR,"FAIL F374: isolated PostgreSQL recovery readback contract\n");
    exit(1);
}
