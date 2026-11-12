import test from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import {
  manifestUrl, validateManifest, assessCandidate, printableDecision,
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
    head_sha: lot.headSha, status: 'completed', conclusion: 'success',
  }));
  f.comparison.ahead_by = 2 + index * 4;
  return { ...f, lot };
}

test('small dev-rooted UI lots are reviewable but never approved for deployment', () => {
  for (const i of [0, 1]) {
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
