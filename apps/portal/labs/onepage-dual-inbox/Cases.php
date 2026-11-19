<?php
declare(strict_types=1);

namespace PrivateD0DualDbCases;

use Closure;
use RuntimeException;

/**
 * This is an assertion group, not an installer or entry point.
 * The actual supervisor and DualDbGate must supply this fixture.
 */
interface Fixture
{
    /** Distinct fixture rows per case in verified DBs; no deletion of historical rows. */
    public function seed(string $scenario): void;
    /** A from portal_asaas_ci holds row lock; B executes callback before A rolls back. */
    public function withInboxLockA(string $event, Closure $onHeld): void;
    /**
     * Invoke unchanged Consumer v2 on worker B, using its real #962 FinancialPort
     * and synthetic payment reader. An allowed test hook runs inside fake GET;
     * commitLossOnce wraps the real successful financial return once, then throws.
     */
    public function runB(string $event, ?Closure $duringSyntheticGet=null, bool $commitLossOnce=false): string;
    /** Expire only this exact fixture's claimed lease through a separate test session; COMMIT and read back. */
    public function expireCommittedLease(string $event): void;
    /** Independent observed values. No buyer/session/credentials in output. */
    public function observe(): array;
    /** Test-only counts of fake GETs, per scenario. */
    public function gets(): int;
}

function yes(bool $condition, string $label): void
{
    if (!$condition) throw new RuntimeException('DUALDB_CASE_REFUSED: '.$label);
}

/** Call only from the gated runner. Expected keys defined in README. */
function cases(Fixture $fixture): array
{
    $fixture->seed('scoped_lock');
    $before=$fixture->observe();
    $fixture->withInboxLockA('target',static function() use($fixture,$before):void {
        // Covers REAP: an expired attempt-8 target is locked in A; B must return
        // within a tight timeout without selecting other eligible rows.
        yes($fixture->runB('target')==='not_claimable','locked exhausted row skipped');
        yes($fixture->observe()===$before && $fixture->gets()===0,'no write or provider read while locked');
    });
    yes($fixture->runB('target')==='terminal_review_financial_unresolved','reap after lock released');
    $after=$fixture->observe();
    yes($after['target']['state']==='manual_review' && $after['target']['attempts']===8
        && $after['neighbor']===$before['neighbor'] && $after['postings']===[] && $after['requests']===[],
        'only exact expired row reaped');

    $fixture->seed('ordinary_claim_lock');
    $before=$fixture->observe();
    $fixture->withInboxLockA('target',static function() use($fixture,$before):void {
        yes($fixture->runB('target')==='not_claimable','claim skips row locked by worker A');
        yes($fixture->observe()===$before && $fixture->gets()===0,'no provider or write with owned lock');
    });
    yes($fixture->runB('target')==='completed','unlocked claim completes');
    $after=$fixture->observe();
    yes($after['target']['state']==='observed_psp_received' && $after['target']['attempts']===1
        && $after['neighbor']===$before['neighbor'] && count($after['postings'])===1
        && count($after['requests'])===1 && $after['entry_confirmations']===0,'one exact posting and ACK');

    $fixture->seed('ack_lease_expired');
    $before=$fixture->observe();
    $hook=static function() use($fixture):void { $fixture->expireCommittedLease('target'); };
    yes($fixture->runB('target',$hook)==='financial_committed_ack_pending','exact ACK refuses expired lease');
    $pending=$fixture->observe();
    yes($pending['target']['state']==='claimed' && count($pending['postings'])===1
        && count($pending['requests'])===1 && $pending['neighbor']===$before['neighbor']
        && $pending['entry_confirmations']===0,'commit durable independently of ACK');
    yes($fixture->runB('target')==='completed','expired lease reclaims with same request');
    $after=$fixture->observe();
    yes($after['target']['state']==='observed_psp_received' && $after['target']['attempts']===2
        && $after['postings']===$pending['postings'] && $after['requests']===$pending['requests'],
        'recovery keeps one posting, same request and original timestamp');
    yes($fixture->runB('alias')==='completed','second event on identical payment can ACK');
    $alias=$fixture->observe();
    yes($alias['alias']['state']==='observed_psp_received' && $alias['target']===$after['target']
        && $alias['postings']===$pending['postings'] && count($alias['requests'])===2
        && array_intersect_key($alias['requests'],$pending['requests'])===$pending['requests']
        && count(array_diff_key($alias['requests'],$pending['requests']))===1
        && $alias['neighbor']===$before['neighbor'] && $alias['entry_confirmations']===0,
        'different event IDs, two requests and one unchanged posting');

    $fixture->seed('lost_financial_result');
    yes($fixture->runB('target',null,true)==='financial_outcome_indeterminate','lost financial return is indeterminate');
    $unknown=$fixture->observe();
    yes($unknown['target']['state']==='claimed' && count($unknown['postings'])===1
        && count($unknown['requests'])===1 && $unknown['entry_confirmations']===0,'no FINISH on unknown financial outcome');
    $fixture->expireCommittedLease('target');
    yes($fixture->runB('target')==='completed','same request after unknown financial result');
    $after=$fixture->observe();
    yes($after['postings']===$unknown['postings'] && $after['requests']===$unknown['requests']
        && $after['target']['state']==='observed_psp_received' && $after['target']['attempts']===2,
        'one posting and one request after unknown result');
    return ['reap_skip_lock','claim_skip_lock','expired_ack_and_alias','lost_financial_result'];
}
