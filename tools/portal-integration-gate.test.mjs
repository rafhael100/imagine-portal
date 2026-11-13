import test from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import {
  manifestUrl, validateManifest, assessCandidate, printableDecision, comparisonBase,
} from './portal-integration-gate.mjs';

const manifest = validateManifest(JSON.parse(readFileSync(manifestUrl, 'utf8')));

function fixture() {
  const m = structuredClone(manifest);
  return {
    m,
    pr: {
      number: m.candidate.pullRequest, state: 'open', draft: true,
      merged_at: null,
      head: { ref: m.candidate.headBranch, sha: m.candidate.headSha,
        repo: { full_name: m.repository } },
      base: { ref: m.candidate.baseBranch, repo: { full_name: m.repository } },
    },
    runs: m.requiredWorkflows.map((name, i) => ({
      id: i + 10, run_attempt: 1, name, event: 'pull_request',
      head_sha: m.candidate.headSha,
      head_branch: m.candidate.headBranch,
      pull_requests: [{ number: m.candidate.pullRequest,
        head: { ref: m.candidate.headBranch }, base: { ref: m.candidate.baseBranch } }],
      status: 'completed', conclusion: 'success',
    })),
    comparison: { ahead_by: 32, behind_by: 0, status: 'ahead' },
  };
}

test('fixed, validated candidate manifest always has every release gate blocked', () => {
  assert.equal(manifest.requiredWorkflows.length, 9);
  assert.ok(Object.values(manifest.policy).every(v => v === false));
  const { m, pr, runs, comparison } = fixture();
  const result = assessCandidate(m, pr, runs, comparison);
  assert.equal(result.decision, 'REVIEW_CANDIDATE_ONLY');
  assert.equal(result.evidenceVerified, true);
  assert.equal(result.mergeApproved, false);
  assert.equal(result.deployApproved, false);
  assert.equal(result.customerAccessApproved, false);
  assert.equal(result.antiRollbackApproved, false);
  assert.equal(printableDecision(result).workflowsPassed, 9);
});

test('a green 1359-commit stacked candidate remains BLOCKED and needs splitting', () => {
  const f = fixture();
  f.comparison.ahead_by = 1359;
  const out = assessCandidate(f.m, f.pr, f.runs, f.comparison);
  assert.equal(out.evidenceVerified, true);
  assert.equal(out.splitRequired, true);
  assert.equal(out.decision, 'BLOCKED_SPLIT_INTEGRATION_LOTS');
  assert.equal(out.mergeApproved, false);
});

test('later failed attempt beats a prior success, not cherry-picked', () => {
  const f = fixture();
  f.runs.push({ ...f.runs[0], id: 999, conclusion: 'failure' });
  assert.equal(assessCandidate(f.m, f.pr, f.runs, f.comparison).evidenceVerified, false);
});

test('running or missing checks block review evidence', () => {
  const f = fixture();
  f.runs[2].status = 'in_progress';
  f.runs.pop();
  const out = assessCandidate(f.m, f.pr, f.runs, f.comparison);
  assert.equal(out.evidenceVerified, false);
  assert.equal(out.decision, 'BLOCKED_EVIDENCE');
  assert.equal(out.errors.filter(e => e.startsWith('MISSING_OR_FAILED_CI')).length, 2);
});

test('workflow for earlier commit or push event cannot substitute a PR run', () => {
  const f = fixture();
  f.runs[0].head_sha = 'a'.repeat(40);
  f.runs[1].event = 'push';
  assert.equal(assessCandidate(f.m, f.pr, f.runs, f.comparison).evidenceVerified, false);
});

test('a new candidate head SHA cannot be verified by previous green checks', () => {
  const f = fixture();
  f.pr.head.sha = 'b'.repeat(40);
  assert.equal(assessCandidate(f.m, f.pr, f.runs, f.comparison).evidenceVerified, false);
});

test('changed PR base, head or repo blocks a deceptive green result', () => {
  for (const mutate of [
    p => { p.base.ref = 'main'; },
    p => { p.head.ref = 'unknown'; },
    p => { p.head.repo.full_name = 'fork/portal'; },
    p => { p.draft = false; },
    p => { p.state = 'closed'; },
    p => { p.merged_at = '2026-11-12T15:00:00Z'; },
  ]) {
    const f = fixture();
    mutate(f.pr);
    assert.equal(assessCandidate(f.m, f.pr, f.runs, f.comparison).evidenceVerified, false);
  }
});

test('dev gained commits: divergence invalidates integration ancestry', () => {
  const f = fixture();
  f.comparison.behind_by = 1;
  assert.equal(assessCandidate(f.m, f.pr, f.runs, f.comparison).evidenceVerified, false);
});

test('missing or malformed compare response fails closed', () => {
  const f = fixture();
  for (const comparison of [null, {}, { status: 'behind', ahead_by: 1, behind_by: 0 },
    { status: 'ahead', ahead_by: '5', behind_by: 0 }]) {
    assert.equal(assessCandidate(f.m, f.pr, f.runs, comparison).evidenceVerified, false);
  }
});

test('manifest refuses any approval bit set true', () => {
  for (const key of Object.keys(manifest.policy)) {
    const m = structuredClone(manifest);
    m.policy[key] = true;
    assert.throws(() => validateManifest(m), /INVALID_REVIEW_MANIFEST/);
  }
});

test('manifest refuses missing, renamed or repeated workflow gates', () => {
  for (const mutate of [
    m => { m.requiredWorkflows.pop(); },
    m => { m.requiredWorkflows[0] = m.requiredWorkflows[1]; },
    m => { m.requiredWorkflows[4] = ''; },
  ]) {
    const m = structuredClone(manifest);
    mutate(m);
    assert.throws(() => validateManifest(m), /INVALID_REVIEW_MANIFEST/);
  }
});

test('manifest cannot silently target production or a mutable branch instead of SHA', () => {
  for (const mutate of [
    m => { m.targetBranch = 'main'; },
    m => { m.candidate.headSha = 'main'; },
    m => { m.candidate.baseBranch = 'main'; },
    m => { m.maxCommitsPerIntegrationLot = 99999; },
  ]) {
    const m = structuredClone(manifest);
    mutate(m);
    assert.throws(() => validateManifest(m), /INVALID_REVIEW_MANIFEST/);
  }
});

function smallLot(index = 0) {
  const f = fixture();
  const lot = f.m.reviewLots[index];
  f.pr.number = lot.pullRequest;
  f.pr.head.ref = lot.headBranch;
  f.pr.head.sha = lot.headSha;
  f.pr.base.ref = lot.baseBranch;
  f.runs = lot.requiredWorkflows.map((name, i) => ({
    id: 1000 + i, run_attempt: 1, name, event: 'pull_request',
    head_sha: lot.headSha, head_branch: lot.headBranch,
    pull_requests: [{ number: lot.pullRequest,
      head: { ref: lot.headBranch }, base: { ref: lot.baseBranch } }],
    status: 'completed', conclusion: 'success',
  }));
  f.comparison.ahead_by = 2 + index * 4;
  return { ...f, lot };
}

test('small dev-rooted UI lots are reviewable but never approved for deployment', () => {
  for (const i of [0, 1, 2, 3]) {
    const f = smallLot(i);
    const result = assessCandidate(f.m, f.pr, f.runs, f.comparison, f.lot);
    assert.equal(result.evidenceVerified, true);
    assert.equal(result.splitRequired, false);
    assert.equal(result.decision, 'REVIEW_CANDIDATE_ONLY');
    assert.equal(result.mergeApproved, false);
    assert.equal(result.deployApproved, false);
    assert.equal(printableDecision(result).workflowsPassed, 2);
  }
});

test('a failed test or changed SHA blocks only its own pilot evidence', () => {
  const f = smallLot();
  f.runs[1].conclusion = 'failure';
  assert.equal(assessCandidate(f.m, f.pr, f.runs, f.comparison, f.lot).evidenceVerified, false);
  f.runs[1].conclusion = 'success';
  f.pr.head.sha = 'a'.repeat(40);
  assert.equal(assessCandidate(f.m, f.pr, f.runs, f.comparison, f.lot).evidenceVerified, false);
});

test('unexpected large pilot must be split even if its checks are green', () => {
  const f = smallLot(1);
  f.comparison.ahead_by = 21;
  const result = assessCandidate(f.m, f.pr, f.runs, f.comparison, f.lot);
  assert.equal(result.evidenceVerified, true);
  assert.equal(result.decision, 'BLOCKED_SPLIT_INTEGRATION_LOTS');
  assert.equal(result.deployApproved, false);
});

test('reviewLots cannot be redefined to main or a missing workflow list', () => {
  for (const mutate of [
    m => { m.reviewLots[0].baseBranch = 'main'; },
    m => { m.reviewLots[0].headSha = 'dev'; },
    m => { m.reviewLots[1].maxCommits = 1000; },
    m => { m.reviewLots[0].requiredWorkflows = []; },
    m => { m.reviewLots[1].pullRequest = m.reviewLots[0].pullRequest; },
  ]) {
    const m = structuredClone(manifest);
    mutate(m);
    assert.throws(() => validateManifest(m), /INVALID_REVIEW_MANIFEST/);
  }
});

test('second UI lot is strictly based on the exact first review branch, not dev', () => {
  const f = smallLot(1);
  assert.equal(f.lot.baseBranch, f.m.reviewLots[0].headBranch);
  assert.equal(f.lot.pullRequest, 592);
  const out = assessCandidate(f.m, f.pr, f.runs, f.comparison, f.lot);
  assert.equal(out.comparisonBase, f.m.reviewLots[0].headBranch);
  assert.equal(out.evidenceVerified, true);
  assert.equal(out.mergeApproved, false);
});

test('silently retargeting second lot to dev fails manifest validation', () => {
  const m = structuredClone(manifest);
  m.reviewLots[1].baseBranch = m.targetBranch;
  assert.throws(() => validateManifest(m), /INVALID_REVIEW_MANIFEST/);
  const m2 = structuredClone(manifest);
  m2.reviewLots[0].headBranch = 'review/unrelated-parent';
  assert.throws(() => validateManifest(m2), /INVALID_REVIEW_MANIFEST/);
});

test('an outdated second lot fails even when earlier parent checks were green', () => {
  const f = smallLot(1);
  f.comparison.behind_by = 1;
  const result = assessCandidate(f.m, f.pr, f.runs, f.comparison, f.lot);
  assert.equal(result.decision, 'BLOCKED_EVIDENCE');
  assert.equal(result.mergeApproved, false);
  assert.equal(result.deployApproved, false);
});

test('second lot is limited by the incremental 20-commit comparison not cumulative 50', () => {
  const f = smallLot(1);
  f.comparison.ahead_by = 21;
  const result = assessCandidate(f.m, f.pr, f.runs, f.comparison, f.lot);
  assert.equal(result.decision, 'BLOCKED_SPLIT_INTEGRATION_LOTS');
  assert.equal(result.commitsAheadOfBase, 21);
  assert.equal(result.comparisonBase, f.lot.baseBranch);
  assert.equal(result.antiRollbackApproved, false);
});

test('stacked candidate always compares to dev, while second lot uses parent', () => {
  assert.equal(comparisonBase(manifest), manifest.targetBranch);
  assert.notEqual(comparisonBase(manifest), manifest.candidate.baseBranch);
  assert.equal(comparisonBase(manifest, manifest.reviewLots[0]), manifest.targetBranch);
  assert.equal(comparisonBase(manifest, manifest.reviewLots[1]), manifest.reviewLots[0].headBranch);
});

test('newer push job cannot shadow valid pull-request checks on the same SHA', () => {
  const f = smallLot(0);
  f.runs.push({ ...f.runs[0], id: 90000, event: 'push', conclusion: 'failure' });
  assert.equal(assessCandidate(f.m, f.pr, f.runs, f.comparison, f.lot).evidenceVerified, true);
  f.runs.push({ ...f.runs[0], id: 90001, event: 'pull_request', conclusion: 'failure' });
  assert.equal(assessCandidate(f.m, f.pr, f.runs, f.comparison, f.lot).evidenceVerified, false);
});

test('a passing run from a different PR or base cannot certify a shared SHA', () => {
  const f = smallLot(1);
  f.runs[0].pull_requests = [{ number: 999,
    head: { ref: f.lot.headBranch }, base: { ref: f.lot.baseBranch } }];
  assert.equal(assessCandidate(f.m, f.pr, f.runs, f.comparison, f.lot).evidenceVerified, false);
  f.runs[0].pull_requests = [{ number: f.lot.pullRequest,
    head: { ref: f.lot.headBranch }, base: { ref: 'main' } }];
  assert.equal(assessCandidate(f.m, f.pr, f.runs, f.comparison, f.lot).evidenceVerified, false);
});

test('all four small lots form one parent-chained review topology', () => {
  assert.equal(manifest.reviewLots.length, 4);
  assert.deepEqual(manifest.reviewLots.map(l => l.pullRequest), [588, 592, 594, 595]);
  for (let i = 0; i < manifest.reviewLots.length; i++) {
    const f = smallLot(i);
    const expectedBase = i === 0 ? manifest.targetBranch :
      manifest.reviewLots[i - 1].headBranch;
    assert.equal(f.lot.baseBranch, expectedBase);
    assert.equal(comparisonBase(f.m, f.lot), expectedBase);
    const verdict = assessCandidate(f.m, f.pr, f.runs, f.comparison, f.lot);
    assert.equal(verdict.evidenceVerified, true);
    assert.equal(verdict.splitRequired, false);
    assert.equal(verdict.mergeApproved, false);
    assert.equal(verdict.deployApproved, false);
    assert.equal(verdict.customerAccessApproved, false);
  }
});

test('retargeting visual-brand or portable-preview lots must fail closed', () => {
  for (const change of [
    m => { m.reviewLots[2].baseBranch = m.targetBranch; },
    m => { m.reviewLots[3].baseBranch = m.reviewLots[1].headBranch; },
    m => { m.reviewLots[3].headBranch = m.reviewLots[2].headBranch; },
    m => { m.reviewLots[2].headSha = m.reviewLots[1].headSha; },
    m => { m.reviewLots[3].pullRequest = m.reviewLots[0].pullRequest; },
    m => { m.reviewLots.pop(); },
  ]) {
    const mutated = structuredClone(manifest);
    change(mutated);
    assert.throws(() => validateManifest(mutated), /INVALID_REVIEW_MANIFEST/);
  }
});

test('a push-only success cannot replace a missing PR check in preview lot 595', () => {
  const f = smallLot(3);
  f.runs[0].event = 'push';
  const result = assessCandidate(f.m, f.pr, f.runs, f.comparison, f.lot);
  assert.equal(result.evidenceVerified, false);
  assert.equal(result.mergeApproved, false);
  assert.equal(result.antiRollbackApproved, false);
});

test('visual-preview lot still cannot approve merge after all checks passed', () => {
  const f = smallLot(3);
  const result = assessCandidate(f.m, f.pr, f.runs, f.comparison, f.lot);
  assert.equal(result.evidenceVerified, true);
  assert.equal(result.decision, 'REVIEW_CANDIDATE_ONLY');
  assert.equal(result.mergeApproved, false);
  assert.equal(result.deployApproved, false);
  assert.equal(result.customerAccessApproved, false);
  assert.equal(result.antiRollbackApproved, false);
});

test('final visual-browser lot allows at most 25 committed steps, never auto-releases', () => {
  const f = smallLot(3);
  assert.equal(f.lot.pullRequest, 595);
  assert.equal(f.lot.maxCommits, 25);
  f.comparison.ahead_by = 25;
  const exact = assessCandidate(f.m, f.pr, f.runs, f.comparison, f.lot);
  assert.equal(exact.evidenceVerified, true);
  assert.equal(exact.splitRequired, false);
  assert.equal(exact.decision, 'REVIEW_CANDIDATE_ONLY');
  assert.equal(exact.mergeApproved, false);
  assert.equal(exact.deployApproved, false);
  f.comparison.ahead_by = 26;
  const oversized = assessCandidate(f.m, f.pr, f.runs, f.comparison, f.lot);
  assert.equal(oversized.decision, 'BLOCKED_SPLIT_INTEGRATION_LOTS');
  assert.equal(oversized.deployApproved, false);
});


function isolatedReview(index) {
  const f = fixture();
  const lot = f.m.isolatedLots[index];
  f.pr.number = lot.pullRequest;
  f.pr.head.ref = lot.headBranch;
  f.pr.head.sha = lot.headSha;
  f.pr.base.ref = lot.baseBranch;
  f.runs = lot.requiredWorkflows.map((name, i) => ({
    id: 3000 + i, run_attempt: 1, name, event: 'pull_request',
    head_sha: lot.headSha, head_branch: lot.headBranch,
    pull_requests: [{ number: lot.pullRequest,
      head: { ref: lot.headBranch }, base: { ref: lot.baseBranch } }],
    status: 'completed', conclusion: 'success',
  }));
  f.comparison.ahead_by = 15;
  return { ...f, lot };
}

test('login and restore review lots remain independent of the UI ancestry and deny release', () => {
  assert.deepEqual(manifest.isolatedLots.map(l => l.pullRequest), [596, 597, 598, 600, 601, 603, 605, 607, 611, 614, 615, 617, 618, 619, 620]);
  assert.equal(manifest.reviewLots.length, 4);
  for (const i of [0, 1, 2, 3, 4, 5, 6, 7, 8, 9, 10, 11, 12, 13, 14]) {
    const f = isolatedReview(i);
    const result = assessCandidate(f.m, f.pr, f.runs, f.comparison, f.lot);
    assert.equal(result.comparisonBase, f.lot.baseBranch);
    assert.equal(result.evidenceVerified, true);
    assert.equal(result.decision, 'REVIEW_CANDIDATE_ONLY');
    assert.equal(result.mergeApproved, false);
    assert.equal(result.deployApproved, false);
    assert.equal(result.customerAccessApproved, false);
    assert.equal(result.antiRollbackApproved, false);
  }
});

test('independent login/recovery lots reject stale SHA, missing CI or retargeting', () => {
  for (const i of [0, 1, 2, 3, 4, 5, 6, 7, 8, 9, 10, 11, 12, 13, 14]) {
    const f = isolatedReview(i);
    f.pr.head.sha = 'a'.repeat(40);
    assert.equal(assessCandidate(f.m, f.pr, f.runs, f.comparison, f.lot).evidenceVerified, false);
    f.pr.head.sha = f.lot.headSha;
    f.runs[0].conclusion = 'failure';
    assert.equal(assessCandidate(f.m, f.pr, f.runs, f.comparison, f.lot).evidenceVerified, false);
    f.runs[0].conclusion = 'success';
    f.pr.base.ref = 'main';
    assert.equal(assessCandidate(f.m, f.pr, f.runs, f.comparison, f.lot).evidenceVerified, false);
    f.pr.base.ref = f.lot.baseBranch;
    f.comparison.behind_by = 1;
    assert.equal(assessCandidate(f.m, f.pr, f.runs, f.comparison, f.lot).evidenceVerified, false);
  }
});

test('independent lot manifest blocks any duplicate, retarget, missing workflow or too-large limit', () => {
  for (const change of [
    m => { m.isolatedLots.pop(); },
    m => { m.isolatedLots[0].baseBranch = m.targetBranch; },
    m => { m.isolatedLots[1].baseBranch = m.isolatedLots[0].baseBranch; },
    m => { m.isolatedLots[0].pullRequest = m.reviewLots[0].pullRequest; },
    m => { m.isolatedLots[1].headSha = m.reviewLots[3].headSha; },
    m => { m.isolatedLots[0].requiredWorkflows.pop(); m.isolatedLots[0].requiredWorkflows = []; },
    m => { m.isolatedLots[1].requiredWorkflows.push(m.isolatedLots[1].requiredWorkflows[0]); },
    m => { m.isolatedLots[0].maxCommits = 21; },
    m => { m.isolatedLots[2].baseBranch = m.targetBranch; },
    m => { m.isolatedLots[2].headBranch = m.isolatedLots[0].headBranch; },
    m => { m.isolatedLots[2].pullRequest = m.isolatedLots[1].pullRequest; },
    m => { m.isolatedLots[2].requiredWorkflows = []; },
    m => { m.isolatedLots[3].baseBranch = m.targetBranch; },
    m => { m.isolatedLots[3].pullRequest = m.isolatedLots[2].pullRequest; },
    m => { m.isolatedLots[3].headSha = m.isolatedLots[0].headSha; },
    m => { m.isolatedLots[3].requiredWorkflows = []; },
    m => { m.isolatedLots[4].baseBranch = m.isolatedLots[2].headBranch; },
    m => { m.isolatedLots[4].pullRequest = m.isolatedLots[3].pullRequest; },
    m => { m.isolatedLots[4].headBranch = m.isolatedLots[3].headBranch; },
    m => { m.isolatedLots[4].headSha = m.isolatedLots[3].headSha; },
    m => { m.isolatedLots[4].requiredWorkflows.pop(); },
    m => { m.isolatedLots[4].requiredWorkflows = []; },
    m => { m.isolatedLots[5].baseBranch = m.targetBranch; },
    m => { m.isolatedLots[5].pullRequest = m.isolatedLots[4].pullRequest; },
    m => { m.isolatedLots[5].headSha = m.isolatedLots[4].headSha; },
    m => { m.isolatedLots[5].requiredWorkflows.pop(); },
    m => { m.isolatedLots[5].requiredWorkflows = []; },
    m => { m.isolatedLots[6].baseBranch = m.targetBranch; },
    m => { m.isolatedLots[6].pullRequest = m.isolatedLots[5].pullRequest; },
    m => { m.isolatedLots[6].headBranch = m.isolatedLots[5].headBranch; },
    m => { m.isolatedLots[6].headSha = m.isolatedLots[5].headSha; },
    m => { m.isolatedLots[6].requiredWorkflows = []; },
    m => { m.isolatedLots[7].baseBranch = m.targetBranch; },
    m => { m.isolatedLots[7].pullRequest = m.isolatedLots[6].pullRequest; },
    m => { m.isolatedLots[7].headSha = m.isolatedLots[6].headSha; },
    m => { m.isolatedLots[7].headBranch = m.isolatedLots[6].headBranch; },
    m => { m.isolatedLots[7].requiredWorkflows = []; },
    m => { m.isolatedLots[8].baseBranch = m.targetBranch; },
    m => { m.isolatedLots[8].baseBranch = m.isolatedLots[0].headBranch; },
    m => { m.isolatedLots[8].pullRequest = m.isolatedLots[1].pullRequest; },
    m => { m.isolatedLots[8].headBranch = m.isolatedLots[1].headBranch; },
    m => { m.isolatedLots[8].headSha = m.isolatedLots[1].headSha; },
    m => { m.isolatedLots[8].requiredWorkflows = []; },
    m => { m.isolatedLots[9].baseBranch = m.targetBranch; },
    m => { m.isolatedLots[9].pullRequest = m.isolatedLots[8].pullRequest; },
    m => { m.isolatedLots[9].headSha = m.isolatedLots[8].headSha; },
    m => { m.isolatedLots[9].requiredWorkflows = []; },
    m => { m.isolatedLots[10].baseBranch = m.targetBranch; },
    m => { m.isolatedLots[10].baseBranch = m.isolatedLots[8].headBranch; },
    m => { m.isolatedLots[10].pullRequest = m.isolatedLots[9].pullRequest; },
    m => { m.isolatedLots[10].headSha = m.isolatedLots[9].headSha; },
    m => { m.isolatedLots[10].headBranch = m.isolatedLots[9].headBranch; },
    m => { m.isolatedLots[10].requiredWorkflows = []; },
    m => { m.isolatedLots[11].baseBranch = m.targetBranch; },
    m => { m.isolatedLots[11].pullRequest = m.isolatedLots[10].pullRequest; },
    m => { m.isolatedLots[11].headSha = m.isolatedLots[10].headSha; },
    m => { m.isolatedLots[12].baseBranch = m.isolatedLots[10].headBranch; },
    m => { m.isolatedLots[12].requiredWorkflows = []; },
    m => { m.isolatedLots[13].headBranch = m.isolatedLots[12].headBranch; },
    m => { m.isolatedLots[13].baseBranch = m.targetBranch; },
    m => { m.isolatedLots[14].pullRequest = m.isolatedLots[13].pullRequest; },
    m => { m.isolatedLots[14].headSha = m.isolatedLots[13].headSha; },
    m => { m.isolatedLots[14].requiredWorkflows.pop(); },
  ]) {
    const m = structuredClone(manifest);
    change(m);
    assert.throws(() => validateManifest(m), /INVALID_REVIEW_MANIFEST/);
  }
});

test('an independent review lot of 21 commits is split, never folded into UI or merged', () => {
  const f = isolatedReview(0);
  f.comparison.ahead_by = 21;
  const decision = assessCandidate(f.m, f.pr, f.runs, f.comparison, f.lot);
  assert.equal(decision.decision, 'BLOCKED_SPLIT_INTEGRATION_LOTS');
  assert.equal(decision.mergeApproved, false);
  assert.equal(decision.deployApproved, false);
});


test('third isolated lab cannot be detached from private login or mistaken for operator enrollment', () => {
  const f = isolatedReview(2);
  assert.equal(f.lot.pullRequest, 598);
  assert.equal(f.lot.baseBranch, f.m.isolatedLots[0].headBranch);
  assert.equal(f.lot.requiredWorkflows.length, 5);
  const result = assessCandidate(f.m, f.pr, f.runs, f.comparison, f.lot);
  assert.equal(result.evidenceVerified, true);
  assert.equal(result.mergeApproved, false);
  assert.equal(result.customerAccessApproved, false);
  assert.equal(result.antiRollbackApproved, false);
  f.runs[4].status = 'in_progress';
  assert.equal(assessCandidate(f.m, f.pr, f.runs, f.comparison, f.lot).evidenceVerified, false);
});

test('fourth isolated lab enforces invitation ancestry and all five workflows', () => {
  const f = isolatedReview(3);
  assert.equal(f.lot.pullRequest, 600);
  assert.equal(f.lot.baseBranch, f.m.isolatedLots[2].headBranch);
  assert.equal(f.lot.requiredWorkflows.length, 5);
  let result = assessCandidate(f.m, f.pr, f.runs, f.comparison, f.lot);
  assert.equal(result.evidenceVerified, true);
  assert.equal(result.decision, 'REVIEW_CANDIDATE_ONLY');
  assert.equal(result.customerAccessApproved, false);
  assert.equal(result.mergeApproved, false);
  assert.equal(result.deployApproved, false);
  f.runs[4].status = 'in_progress';
  result = assessCandidate(f.m, f.pr, f.runs, f.comparison, f.lot);
  assert.equal(result.evidenceVerified, false);
  assert.equal(result.mergeApproved, false);
  assert.equal(result.deployApproved, false);
});

test('fifth isolated proof core is pinned to first enrollment and six green checks', () => {
  const f = isolatedReview(4);
  assert.equal(f.lot.pullRequest, 601);
  assert.equal(f.lot.baseBranch, f.m.isolatedLots[3].headBranch);
  assert.equal(f.lot.requiredWorkflows.length, 6);
  const verified = assessCandidate(f.m, f.pr, f.runs, f.comparison, f.lot);
  assert.equal(verified.evidenceVerified, true);
  assert.equal(verified.decision, 'REVIEW_CANDIDATE_ONLY');
  assert.equal(verified.mergeApproved, false);
  assert.equal(verified.deployApproved, false);
  assert.equal(verified.customerAccessApproved, false);
  assert.equal(verified.antiRollbackApproved, false);

  // A green prior Docker check cannot certify the pinned SHA if the
  // current PR-specific run is not yet complete.
  f.runs[5].status = 'in_progress';
  const pending = assessCandidate(f.m, f.pr, f.runs, f.comparison, f.lot);
  assert.equal(pending.evidenceVerified, false);
  assert.equal(pending.mergeApproved, false);
  assert.equal(pending.deployApproved, false);
});

test('sixth isolated database ledger stays after scoped proof and requires seven checks', () => {
  const f = isolatedReview(5);
  assert.equal(f.lot.pullRequest, 603);
  assert.equal(f.lot.baseBranch, f.m.isolatedLots[4].headBranch);
  assert.equal(f.lot.requiredWorkflows.length, 7);
  let result = assessCandidate(f.m, f.pr, f.runs, f.comparison, f.lot);
  assert.equal(result.evidenceVerified, true);
  assert.equal(result.decision, 'REVIEW_CANDIDATE_ONLY');
  assert.equal(result.mergeApproved, false);
  assert.equal(result.deployApproved, false);
  assert.equal(result.antiRollbackApproved, false);
  assert.equal(result.customerAccessApproved, false);
  f.runs[6].conclusion = 'failure';
  result = assessCandidate(f.m, f.pr, f.runs, f.comparison, f.lot);
  assert.equal(result.evidenceVerified, false);
  assert.equal(result.mergeApproved, false);
  assert.equal(result.deployApproved, false);
});

test('seventh offline proof lifecycle is reviewed as a child of the immutable ledger, not as production', () => {
  const f = isolatedReview(6);
  assert.equal(f.lot.pullRequest, 605);
  assert.equal(f.lot.baseBranch, f.m.isolatedLots[5].headBranch);
  assert.equal(f.lot.requiredWorkflows.length, 5);
  const reviewed = assessCandidate(f.m, f.pr, f.runs, f.comparison, f.lot);
  assert.equal(reviewed.evidenceVerified, true);
  assert.equal(reviewed.decision, 'REVIEW_CANDIDATE_ONLY');
  assert.equal(reviewed.mergeApproved, false);
  assert.equal(reviewed.deployApproved, false);
  assert.equal(reviewed.antiRollbackApproved, false);
  assert.equal(reviewed.customerAccessApproved, false);
  f.runs[4].status = 'in_progress';
  const incomplete = assessCandidate(f.m, f.pr, f.runs, f.comparison, f.lot);
  assert.equal(incomplete.evidenceVerified, false);
  assert.equal(incomplete.mergeApproved, false);
  assert.equal(incomplete.deployApproved, false);
});

test('eighth isolated mail-safety candidate never certifies real invitation delivery', () => {
  const f = isolatedReview(7);
  assert.equal(f.lot.pullRequest, 607);
  assert.equal(f.lot.baseBranch, f.m.isolatedLots[6].headBranch);
  assert.equal(f.lot.requiredWorkflows.length, 7);
  const allowed = assessCandidate(f.m, f.pr, f.runs, f.comparison, f.lot);
  assert.equal(allowed.evidenceVerified, true);
  assert.equal(allowed.decision, 'REVIEW_CANDIDATE_ONLY');
  assert.equal(allowed.mergeApproved, false);
  assert.equal(allowed.deployApproved, false);
  assert.equal(allowed.customerAccessApproved, false);
  assert.equal(allowed.antiRollbackApproved, false);
  f.runs[6].conclusion = 'failure';
  const blocked = assessCandidate(f.m, f.pr, f.runs, f.comparison, f.lot);
  assert.equal(blocked.evidenceVerified, false);
  assert.equal(blocked.mergeApproved, false);
  assert.equal(blocked.deployApproved, false);
});

test('ninth isolated signed-witness rehearsal remains a child of the prior rollback lab', () => {
  const f = isolatedReview(8);
  assert.equal(f.lot.pullRequest, 611);
  assert.equal(f.lot.baseBranch, f.m.isolatedLots[1].headBranch);
  assert.equal(f.lot.requiredWorkflows.length, 2);
  const reviewed = assessCandidate(f.m, f.pr, f.runs, f.comparison, f.lot);
  assert.equal(reviewed.evidenceVerified, true);
  assert.equal(reviewed.decision, 'REVIEW_CANDIDATE_ONLY');
  assert.equal(reviewed.mergeApproved, false);
  assert.equal(reviewed.deployApproved, false);
  assert.equal(reviewed.customerAccessApproved, false);
  assert.equal(reviewed.antiRollbackApproved, false);
  // A signed observation test is not permission to publish to customers.
  f.runs[1].status = 'in_progress';
  const blocked = assessCandidate(f.m, f.pr, f.runs, f.comparison, f.lot);
  assert.equal(blocked.evidenceVerified, false);
  assert.equal(blocked.antiRollbackApproved, false);
  assert.equal(blocked.mergeApproved, false);
});

test('first-party signature verifier stays on synthetic signer ancestry and never unlocks customers', () => {
  const f = isolatedReview(9);
  assert.equal(f.lot.pullRequest, 614);
  assert.equal(f.lot.baseBranch, f.m.isolatedLots[8].headBranch);
  assert.equal(f.lot.requiredWorkflows.length, 2);
  const reviewed = assessCandidate(f.m, f.pr, f.runs, f.comparison, f.lot);
  assert.equal(reviewed.evidenceVerified, true);
  assert.equal(reviewed.decision, 'REVIEW_CANDIDATE_ONLY');
  assert.equal(reviewed.mergeApproved, false);
  assert.equal(reviewed.deployApproved, false);
  assert.equal(reviewed.antiRollbackApproved, false);
  assert.equal(reviewed.customerAccessApproved, false);
  f.runs[1].status = 'in_progress';
  assert.equal(assessCandidate(f.m, f.pr, f.runs, f.comparison, f.lot).evidenceVerified, false);
});

test('deny-by-default Laravel witness binding is not a recovery or login permission', () => {
  const f = isolatedReview(10);
  assert.equal(f.lot.pullRequest, 615);
  assert.equal(f.lot.baseBranch, f.m.isolatedLots[9].headBranch);
  assert.equal(f.lot.requiredWorkflows.length, 2);
  let reviewed = assessCandidate(f.m, f.pr, f.runs, f.comparison, f.lot);
  assert.equal(reviewed.evidenceVerified, true);
  assert.equal(reviewed.decision, 'REVIEW_CANDIDATE_ONLY');
  assert.equal(reviewed.mergeApproved, false);
  assert.equal(reviewed.deployApproved, false);
  assert.equal(reviewed.antiRollbackApproved, false);
  assert.equal(reviewed.customerAccessApproved, false);
  f.runs[0].conclusion = 'failure';
  reviewed = assessCandidate(f.m, f.pr, f.runs, f.comparison, f.lot);
  assert.equal(reviewed.evidenceVerified, false);
  assert.equal(reviewed.deployApproved, false);
});

test('four revocation publication/recheck/persistence lots remain an independent NO-GO chain', () => {
  for (const [index, id] of [[11,617],[12,618],[13,619],[14,620]]) {
    const f = isolatedReview(index);
    assert.equal(f.lot.pullRequest, id);
    assert.equal(f.lot.baseBranch, f.m.isolatedLots[index-1].headBranch);
    assert.equal(f.lot.requiredWorkflows.length, 2);
    const result = assessCandidate(f.m, f.pr, f.runs, f.comparison, f.lot);
    assert.equal(result.evidenceVerified, true);
    assert.equal(result.decision, 'REVIEW_CANDIDATE_ONLY');
    assert.equal(result.mergeApproved, false);
    assert.equal(result.deployApproved, false);
    assert.equal(result.customerAccessApproved, false);
    assert.equal(result.antiRollbackApproved, false);
    f.runs[1].conclusion = 'failure';
    const blocked = assessCandidate(f.m, f.pr, f.runs, f.comparison, f.lot);
    assert.equal(blocked.evidenceVerified, false);
    assert.equal(blocked.mergeApproved, false);
  }
});
