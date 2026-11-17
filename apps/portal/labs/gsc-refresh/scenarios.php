<?php
declare(strict_types=1);

/** Included only inside verify-http-kernel.php, with its real auth/HTTP helpers. */
use Imagine\Portal\GscRefreshLab\RefreshFlow;
use App\Domain\SeoReview\GoogleSearchConsoleEncryptedTokenVault as RefreshVault;

labCheck($refreshMode && $tlsMode && RefreshFlow::enabled(),'guarded renewal scenarios');
$metricsPath=getenv('PORTAL_GSC_TLS_METRICS');
labCheck(is_string($metricsPath) && is_file($metricsPath),'renewal TLS request counters required');
$renewMetrics=static function()use($metricsPath):array {
    $raw=file_get_contents($metricsPath);
    labCheck(is_string($raw) && strlen($raw)<4096,'bounded renewal metrics');
    $m=json_decode($raw,true,8,JSON_THROW_ON_ERROR);$keys=array_keys($m);sort($keys);
    labCheck($keys===['redirect_target','sites','token','total','unexpected','violations'],'six numeric TLS counters');
    foreach($m as $v)labCheck(is_int($v) && $v>=0,'numeric renewal metrics');
    return $m;
};
$renewDelta=static function(array $before,int $token,int $sites)use($renewMetrics):void {
    $after=$renewMetrics();
    labCheck($after['token']-$before['token']===$token && $after['sites']-$before['sites']===$sites &&
        $after['total']-$before['total']===$token+$sites,'exact renewal requests without retry');
    foreach(['redirect_target','unexpected','violations'] as $key)
        labCheck($after[$key]===$before[$key],'no renewal redirect or fixture violation');
};
$forbidden[]='synthetic-refreshed-access-token-1234567890';
$forbidden[]='synthetic-rotated-refresh-token-1234567890';
foreach(['rotate','invalid-grant','timeout','temporary-consent','sites-missing-property'] as $case)
    $forbidden[]='synthetic-refresh-'.$case.'-token-1234567890';
$renewSnapshot=static function(array $s)use($owner):array {
    $q=$owner->prepare('SELECT t.sealed_tokens,t.issued_at,t.expires_at,t.revoked_at,
        g.grant_status,g.verified_at,g.expires_at AS grant_expires,g.revoked_at AS grant_revoked
        FROM public.portal_gsc_oauth_token_candidates t FULL JOIN public.portal_gsc_property_grants g
        USING(tenant_ref,actor_ref,property_fingerprint)
        WHERE COALESCE(t.tenant_ref,g.tenant_ref)=? AND COALESCE(t.actor_ref,g.actor_ref)=?');
    $q->execute(['porg-'.str_replace('-','',$s['organization']),'pusr-'.str_replace('-','',$s['identity'])]);
    $r=$q->fetch(PDO::FETCH_ASSOC);return is_array($r)?$r:[];
};
$renewRead=function(array $s,string $ref)use($db,$vault,$setContext):array {
    $db->beginTransaction();$setContext($s,$ref);
    $secret=$vault->inspectSyntheticCandidate('porg-'.str_replace('-','',$s['organization']),
        'pusr-'.str_replace('-','',$s['identity']),hash('sha256',LAB_SITE),time());
    labCheck($secret!==null,'original pinned vault decrypts the actual renewed candidate');
    $data=$secret->takeForTrustedBackend();$db->commit();return $data;
};
$renewSetup=function(string $code='synthetic-good-with-refresh')use($subject,$login,$bind,$start,$http):array {
    $s=$subject();$jar=[];$ref=$login($jar,$s);$bind($s);$callback=$start($jar);$callback['code']=$code;
    labCheck($http($jar,'GET','callback',$callback)->getStatusCode()===200,'renewal seed comes from real TLS callback');
    return [$s,$jar,$ref,$callback];
};
$renewBind=function(array $s,?Closure $afterClaim=null,?Closure $after=null,?Closure $locked=null,
    ?Closure $completed=null)use($app,$db,$vault,$https):RefreshFlow {
    config(['portal_development_auth.organization'=>$s['organization']]);
    $flow=new RefreshFlow($db,$vault,$https,$s['organization'],LAB_SITE,$afterClaim,$after,$locked,$completed);
    $app->instance(RefreshFlow::class,$flow);return $flow;
};
$renewCall=function(array &$jar)use($http,$csrf) {
    return $http($jar,'POST','renew',[],$csrf($jar));
};
$claimCount=static function(array $s,string $status='claimed')use($owner):int {
    $q=$owner->prepare('SELECT count(*) FROM public.portal_gsc_refresh_lab_claims
        WHERE tenant_ref=? AND actor_ref=? AND status=?');
    $q->execute(['porg-'.str_replace('-','',$s['organization']),
        'pusr-'.str_replace('-','',$s['identity']),$status]);return (int)$q->fetchColumn();
};

// Keep the runtime's old revoke-only DML surface. New authority is confined
// to named functions and inaccessible metadata, never to caller-supplied GUCs.
foreach(['portal_gsc_oauth_token_candidates','portal_gsc_property_grants'] as $table) {
    $q=$owner->prepare("SELECT has_table_privilege('portal_gsc_f114_runtime',?,'DELETE')");
    $q->execute([$table]);labCheck(in_array($q->fetchColumn(),[false,0,'0','f'],true),'runtime still has no DELETE');
}
$q=$owner->query("SELECT has_column_privilege('portal_gsc_f114_runtime','portal_gsc_oauth_token_candidates',
    'sealed_tokens','UPDATE'),has_column_privilege('portal_gsc_f114_runtime','portal_gsc_property_grants',
    'expires_at','UPDATE'),has_table_privilege('portal_gsc_f114_runtime','portal_gsc_refresh_lab_claims','SELECT')");
foreach($q->fetch(PDO::FETCH_NUM) as $allowed)
    labCheck(in_array($allowed,[false,0,'0','f'],true),'no ciphertext/expiry DML or claim-ledger reads');

// Positive: durable claim visible to another connection BEFORE network.
[$s,$jar,$ref,$originalCallback]=$renewSetup();$old=$renewSnapshot($s);
$owner->prepare('DELETE FROM portal_gsc_f115_state_session_bindings WHERE state_hash=?')
    ->execute([hash('sha256',$originalCallback['state'])]);
$owner->prepare('DELETE FROM portal_gsc_oauth_states WHERE state_hash=?')
    ->execute([hash('sha256',$originalCallback['state'])]);
$second=new PDO($dsn,'portal_gsc_f114_runtime','f114_disposable_runtime_only',$options);
$secondVault=new RefreshVault($second,$vaultKey);
$loser=new RefreshFlow($second,$secondVault,$https,$s['organization']);
$afterClaim=function()use($db,$s,$ref,$claimCount,$loser):bool {
    labCheck(!$db->inTransaction() && $claimCount($s)===1,'claim committed before any TLS attempt');
    labCheck(!$loser->renew($ref) && $loser->tokenAttempts===0,'second worker loses before provider I/O');
    return true;
};
$renewLockAttempts=0;
$locked=function()use($revoker,$ref,$s,&$renewLockAttempts):bool {
    foreach([
        ["UPDATE portal_auth_sessions SET status='revoked',revoked_at=now() WHERE session_sha256=?",hash('sha256',$ref)],
        ['DELETE FROM portal_membership_grants WHERE id=?',$s['grant']],
    ] as [$sql,$arg]) {
        $revoker->beginTransaction();
        try {$revoker->exec("SET LOCAL lock_timeout='150ms'");$revoker->prepare($sql)->execute([$arg]);
            $revoker->rollBack();return false;
        }catch(PDOException $e){if($revoker->inTransaction())$revoker->rollBack();
            if($e->getCode()!=='55P03')return false;$renewLockAttempts++;}
    }
    return true;
};
$flow=$renewBind($s,$afterClaim,null,$locked);$before=$renewMetrics();
$r=$renewCall($jar);labCheck($r->getStatusCode()===200 && $json($r)['labRenewed']===true,'authenticated early renewal succeeds');
$renewDelta($before,1,1);$new=$renewSnapshot($s);$tokens=$renewRead($s,$ref);
labCheck($tokens['accessToken']==='synthetic-refreshed-access-token-1234567890' &&
    $tokens['refreshToken']==='synthetic-only-refresh-token-1234567890','omitted refresh retains exact predecessor material');
labCheck($new['sealed_tokens']!==$old['sealed_tokens'] && (int)$new['expires_at']>(int)$old['expires_at'] &&
    (int)$new['expires_at']===(int)$new['grant_expires'],'new ciphertext and lifetime commit with exact grant');
labCheck($claimCount($s,'completed')===1 && $renewLockAttempts===2,'one completed generation holds both revocation locks');
labCheck(!$preflight->hasCandidateGrant($ref,$s['organization'],'seo.gsc.view'),'renewal still cannot confer view');
unset($tokens);
// A later generation can renew only after its predecessor completed.
$flow=$renewBind($s);$before=$renewMetrics();labCheck($renewCall($jar)->getStatusCode()===200,'completed generation permits next early renewal');
$renewDelta($before,1,1);labCheck($claimCount($s,'completed')===2,'distinct completed generation metadata retained');

// Rotation is supplied by an actual token response, never by HTTP input.
[$s,$jar,$ref]=$renewSetup('synthetic-initial-refresh-rotate');$flow=$renewBind($s);$before=$renewMetrics();
labCheck($renewCall($jar)->getStatusCode()===200,'provider replacement refresh accepted');$renewDelta($before,1,1);
$tokens=$renewRead($s,$ref);labCheck($tokens['refreshToken']==='synthetic-rotated-refresh-token-1234567890',
    'pinned vault decrypts the newly supplied refresh material');unset($tokens);

// Browser authority injection, foreign sessions, CSRF and missing material
// cannot claim or reach the provider. These calls use the real Kernel guard.
[$s,$jar,$ref]=$renewSetup();$flow=$renewBind($s);$before=$renewMetrics();$anonymous=[];
labCheck($renewCall($anonymous)->getStatusCode()===403,'anonymous renewal denied');
labCheck($http($jar,'POST','renew')->getStatusCode()===419,'renewal requires CSRF');
labCheck($http($jar,'POST','renew',[],$csrf($jar),['HTTP_ORIGIN'=>'https://crm.example.test'])->getStatusCode()===419,
    'renewal requires exact origin');
labCheck($http($jar,'POST','renew',['refresh_token'=>'synthetic-browser-token'],$csrf($jar))->getStatusCode()===403,
    'browser cannot supply credential or authority');
labCheck($renewCall($jarB)->getStatusCode()===403,'another organization cannot renew this property');
$renewDelta($before,0,0);labCheck($claimCount($s)===0,'denied HTTP requests create no claim');
[$s,$jar,$ref]=$renewSetup('synthetic-good-without-refresh');$flow=$renewBind($s);$before=$renewMetrics();
labCheck($renewCall($jar)->getStatusCode()===403 && $flow->tokenAttempts===0,'missing refresh rejected before network');
$renewDelta($before,0,0);labCheck($claimCount($s)===0,'missing material rolls back preliminary claim');

// Ambiguous network failure or rejected response keeps the predecessor and
// its unfinished claim. This proof implements no automatic retry policy.
foreach(['invalid-grant','timeout','temporary-consent','sites-missing-property'] as $case) {
    [$s,$jar,$ref]=$renewSetup('synthetic-initial-refresh-'.$case);$old=$renewSnapshot($s);
    $flow=$renewBind($s);$before=$renewMetrics();
    labCheck($renewCall($jar)->getStatusCode()===403,'rejected renewal never becomes success');
    $renewDelta($before,1,$case==='sites-missing-property'?1:0);
    labCheck($renewSnapshot($s)===$old && $claimCount($s)===1,'failed response preserves exact prior pair and durable claim');
    $before=$renewMetrics();labCheck($renewCall($jar)->getStatusCode()===403,'unfinished version cannot automatically retry');
    $renewDelta($before,0,0);
}

// Revoke/change/remove during the network interval: no resurrection and no
// stale response may replace the state observed after that intervention.
foreach(['session','connect','membership','candidate-revoke','grant-revoke','expired','removed','key-rotation'] as $race) {
    [$s,$jar,$ref]=$renewSetup();$intervened=null;
    $after=function()use($race,$s,$ref,$owner,$auth,$vaultKey,$renewSnapshot,&$intervened):bool {
        $tenant='porg-'.str_replace('-','',$s['organization']);
        if($race==='session')labCheck($auth->revoke($ref),'race revokes actual DB session');
        elseif($race==='connect')$owner->prepare('DELETE FROM portal_membership_grants WHERE id=?')->execute([$s['grant']]);
        elseif($race==='membership')$owner->prepare('UPDATE portal_memberships SET membership_revision=membership_revision+1 WHERE id=?')->execute([$s['membership']]);
        elseif($race==='candidate-revoke')$owner->prepare('UPDATE portal_gsc_oauth_token_candidates SET revoked_at=? WHERE tenant_ref=?')->execute([time(),$tenant]);
        elseif($race==='grant-revoke')$owner->prepare("UPDATE portal_gsc_property_grants SET grant_status='revoked',revoked_at=? WHERE tenant_ref=?")->execute([time(),$tenant]);
        elseif($race==='expired')$owner->prepare('UPDATE portal_gsc_oauth_token_candidates SET issued_at=?,expires_at=? WHERE tenant_ref=?')->execute([time()-301,time()-1,$tenant]);
        elseif($race==='removed')$owner->prepare('DELETE FROM portal_gsc_oauth_token_candidates WHERE tenant_ref=?')->execute([$tenant]);
        else {
            $rotator=new RefreshVault($owner,random_bytes(32),2,$vaultKey);
            labCheck($rotator->rotateSyntheticCandidate($tenant,'pusr-'.str_replace('-','',$s['identity']),
                hash('sha256',LAB_SITE),time()),'independent key rotation changes version');
        }
        $intervened=$renewSnapshot($s);return true;
    };
    $flow=$renewBind($s,null,$after);$before=$renewMetrics();
    labCheck($renewCall($jar)->getStatusCode()===403 && $flow->tokenAttempts===1 && $flow->propertyAttempts===1,
        'post-response authority or generation race denies replacement');
    $renewDelta($before,1,1);
    labCheck($renewSnapshot($s)===$intervened && $claimCount($s)===1,'stale response cannot resurrect or overwrite changed predecessor');
    if($race==='key-rotation') {
        $flow=$renewBind($s);$before=$renewMetrics();
        labCheck($renewCall($jar)->getStatusCode()===403 && $flow->tokenAttempts===0,
            'key rotation cannot bypass unfinished tuple claim');$renewDelta($before,0,0);
    }
}

// Failure after both successor writes rolls back old ciphertext + old grant;
// the PRE-network claim remains durable outside the replacement transaction.
[$s,$jar,$ref]=$renewSetup();$old=$renewSnapshot($s);
$flow=$renewBind($s,null,null,static fn():bool=>false);$before=$renewMetrics();
labCheck($renewCall($jar)->getStatusCode()===403,'failure during replacement is denied');$renewDelta($before,1,1);
labCheck($renewSnapshot($s)===$old && $claimCount($s)===1,'replacement rollback restores byte-exact predecessor pair');

// Database-only attacks: committing the internal delete, completing without
// a successor, or using another authenticated session cannot bypass the fence.
[$s,$jar,$ref]=$renewSetup();$old=$renewSnapshot($s);$before=$renewMetrics();
$db->beginTransaction();$setContext($s,$ref);
$q=$db->prepare('SELECT * FROM public.portal_gsc_refresh_claim(?,CAST(? AS uuid),?)');
$q->execute([hash('sha256',$ref),$s['organization'],hash('sha256',LAB_SITE)]);$attackClaim=$q->fetch(PDO::FETCH_ASSOC);
labCheck(is_array($attackClaim),'direct claim exists for negative commit proof');$db->commit();
$replacementJar=[];$replacementRef=$login($replacementJar,$s,1);
$db->beginTransaction();$setContext($s,$replacementRef);
$q=$db->prepare('SELECT * FROM public.portal_gsc_refresh_begin_replace(CAST(? AS uuid),?,CAST(? AS uuid))');
$q->execute([$attackClaim['claim_id'],hash('sha256',$replacementRef),$s['organization']]);
labCheck($q->fetch(PDO::FETCH_ASSOC)===false,'same actor replacement session cannot steal original claim');$db->commit();
$db->beginTransaction();$setContext($s,$ref);
$q->execute([$attackClaim['claim_id'],hash('sha256',$ref),$s['organization']]);
labCheck(is_array($q->fetch(PDO::FETCH_ASSOC)),'internal replacement stage reached for attack proof');
foreach(['portal_gsc_oauth_token_candidates','portal_gsc_property_grants'] as $table)
    labCheck($insertDenied($table,$s),'live writing claim cannot insert another property');
$complete=$db->prepare('SELECT public.portal_gsc_refresh_complete(CAST(? AS uuid),?,CAST(? AS uuid))');
$complete->execute([$attackClaim['claim_id'],hash('sha256',$ref),$s['organization']]);
labCheck(in_array($complete->fetchColumn(),[false,0,'0','f'],true),'complete refuses missing successor pair');
$denied=false;
try{$db->commit();}catch(PDOException $e){$denied=$e->getCode()==='23514';if($db->inTransaction())$db->rollBack();}
labCheck($denied && $renewSnapshot($s)===$old,'deferred database constraint rejects delete-only commit');
$renewDelta($before,0,0);

// A caller can force deferred constraints early. Mutation AFTER completed
// must still be rejected, even after that early constraint queue flush.
[$s,$jar,$ref]=$renewSetup();$guardAttempts=0;
$completed=function()use($db,&$guardAttempts):bool {
    $db->exec('SET CONSTRAINTS ALL IMMEDIATE');
    foreach(['portal_gsc_oauth_token_candidates','portal_gsc_property_grants'] as $table) {
        $db->exec('SAVEPOINT completed_pair_attack');$denied=false;
        try {
            $sql=$table==='portal_gsc_oauth_token_candidates'
                ? 'UPDATE public.'.$table.' SET revoked_at=extract(epoch FROM clock_timestamp())::bigint'
                : "UPDATE public.$table SET revoked_at=extract(epoch FROM clock_timestamp())::bigint,grant_status='revoked'";
            $db->exec($sql);
        }catch(PDOException $e){$denied=$e->getCode()==='23514';}
        $db->exec('ROLLBACK TO SAVEPOINT completed_pair_attack');$db->exec('RELEASE SAVEPOINT completed_pair_attack');
        if(!$denied)return false;$guardAttempts++;
    }
    return true;
};
$flow=$renewBind($s,completed:$completed);$before=$renewMetrics();
labCheck($renewCall($jar)->getStatusCode()===200 && $guardAttempts===2,'final pair survives early constraint flush attacks');
$renewDelta($before,1,1);
$bind($s);labCheck($http($jar,'POST','revoke',[],$csrf($jar))->getStatusCode()===200,
    'ordinary local disconnect still works in the next transaction');
$flow=$renewBind($s);$before=$renewMetrics();
labCheck($renewCall($jar)->getStatusCode()===403 && $flow->tokenAttempts===0,'revoked successor cannot renew');$renewDelta($before,0,0);
labCheck(!$second->inTransaction(),'competing worker transaction closed');$second=null;
echo "GSC early renewal: durable one-attempt claim; real TLS response; exact sites evidence; ".
    "atomic successor/grant; revocation and generation races; commit guards verified; LABORATORY ONLY\n";
