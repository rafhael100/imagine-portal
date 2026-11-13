#!/usr/bin/env node
/**
 * Review-only integration witness. Never merges, deploys, modifies a branch,
 * grants database access, or turns a CI success into production approval.
 * This tool belongs to dev, NOT the enormous draft candidate being audited.
 */
import { readFileSync } from 'node:fs';

export const manifestUrl = new URL('../integration/portal-candidate.json', import.meta.url);

function plainRecord(value) {
  return value !== null && typeof value === 'object' && !Array.isArray(value);
}

export function validateManifest(m) {
  if (!plainRecord(m) || m.schemaVersion !== 1 ||
      m.repository !== 'rafhael100/imagine-portal' ||
      m.targetBranch !== 'dev/fast-track-portal-2026-11-05' ||
      !plainRecord(m.candidate) ||
      !Number.isSafeInteger(m.candidate.pullRequest) ||
      m.candidate.pullRequest < 1 ||
      m.candidate.headBranch !== 'review/portal-int-ui-pg-f351-combined-ci-2026-11-12' ||
      m.candidate.baseBranch !== 'review/portal-laravel-f350-restored-contract-negative-tests-2026-11-12' ||
      !/^[a-f0-9]{40}$/.test(m.candidate.headSha ?? '') ||
      !Number.isSafeInteger(m.maxCommitsPerIntegrationLot) ||
      m.maxCommitsPerIntegrationLot < 1 ||
      m.maxCommitsPerIntegrationLot > 100 ||
      !Array.isArray(m.requiredWorkflows) ||
      m.requiredWorkflows.length !== 9 ||
      new Set(m.requiredWorkflows).size !== m.requiredWorkflows.length ||
      !m.requiredWorkflows.every(n => typeof n === 'string' && n.length > 2 && n.length < 160) ||
      !Array.isArray(m.reviewLots) || m.reviewLots.length !== 4 ||
      // Exact, immutable 4-stage review topology: first rooted in dev,
      // each following delta based ONLY on its immediately previous PR.
      // No skipping a stage, reusing a head, or retargeting to main/dev.
      m.reviewLots[0]?.baseBranch !== m.targetBranch ||
      m.reviewLots.some((lot, index) => index > 0 &&
        lot?.baseBranch !== m.reviewLots[index - 1]?.headBranch) ||
      new Set(m.reviewLots.map(l => l?.pullRequest)).size !== m.reviewLots.length ||
      new Set(m.reviewLots.map(l => l?.headBranch)).size !== m.reviewLots.length ||
      new Set(m.reviewLots.map(l => l?.headSha)).size !== m.reviewLots.length ||
      m.reviewLots.some(l => !plainRecord(l) ||
        !Number.isSafeInteger(l.pullRequest) || l.pullRequest < 1 ||
        typeof l.headBranch !== 'string' || !l.headBranch.startsWith('review/') ||
        typeof l.baseBranch !== 'string' || l.baseBranch.length < 3 ||
        !/^[a-f0-9]{40}$/.test(l.headSha ?? '') ||
        !Number.isSafeInteger(l.maxCommits) || l.maxCommits < 1 || l.maxCommits > 50 ||
        !Array.isArray(l.requiredWorkflows) || l.requiredWorkflows.length !== 2 ||
        new Set(l.requiredWorkflows).size !== 2 ||
        !l.requiredWorkflows.every(n => typeof n === 'string' && n.length > 2 && n.length < 160)) ||
      // Independent branches: never fold their SHAs into the four-stage
      // UI ancestry. They are separate, read-only review candidates.
      !Array.isArray(m.isolatedLots) || m.isolatedLots.length !== 8 ||
      m.isolatedLots[0]?.pullRequest !== 596 ||
      m.isolatedLots[0]?.headBranch !== 'feat/portal-private-login-ui-2026-11-12' ||
      m.isolatedLots[0]?.baseBranch !== 'ops/portal-private-auth-deployment-2026-11-12' ||
      m.isolatedLots[1]?.pullRequest !== 597 ||
      m.isolatedLots[1]?.headBranch !== 'review/portal-sec-r03-restore-fence-contract-2026-11-12' ||
      m.isolatedLots[1]?.baseBranch !== m.targetBranch ||
      m.isolatedLots[2]?.pullRequest !== 598 ||
      m.isolatedLots[2]?.headBranch !== 'review/portal-first-invitation-pg18-lab-2026-11-12' ||
      // Private first-invitation rehearsal sits ONLY atop the pinned
      // login candidate. It cannot be merged into dev independently.
      m.isolatedLots[2]?.baseBranch !== m.isolatedLots[0]?.headBranch ||
      m.isolatedLots[3]?.pullRequest !== 600 ||
      m.isolatedLots[3]?.headBranch !== 'review/portal-first-enrollment-pg18-lab-2026-11-12' ||
      // The enrollment rehearsal can only be reviewed atop the exact
      // first-invitation branch; it is not a standalone service or deploy.
      m.isolatedLots[3]?.baseBranch !== m.isolatedLots[2]?.headBranch ||
      m.isolatedLots[4]?.pullRequest !== 601 ||
      m.isolatedLots[4]?.headBranch !== 'review/portal-scoped-email-proof-core-2026-11-12' ||
      // First-party proof core must stay strictly atop the verified
      // enrollment lab, never retargeted to dev/main or the large candidate.
      m.isolatedLots[4]?.baseBranch !== m.isolatedLots[3]?.headBranch ||
      m.isolatedLots[4]?.requiredWorkflows?.length !== 6 ||
      m.isolatedLots[5]?.pullRequest !== 603 ||
      m.isolatedLots[5]?.headBranch !== 'review/portal-email-proof-ledger-pg18-2026-11-12' ||
      // Immutable default-deny ledger is strictly a child of scoped
      // email proof, never a main/dev credential issuer.
      m.isolatedLots[5]?.baseBranch !== m.isolatedLots[4]?.headBranch ||
      m.isolatedLots[5]?.requiredWorkflows?.length !== 7 ||
      m.isolatedLots[6]?.pullRequest !== 605 ||
      m.isolatedLots[6]?.headBranch !== 'review/portal-persisted-email-proof-pg18-lab-2026-11-12' ||
      // The proof issuer/consumer is only a disposable child of the
      // default-deny ledger; a green CI cannot turn it into an operator.
      m.isolatedLots[6]?.baseBranch !== m.isolatedLots[5]?.headBranch ||
      m.isolatedLots[6]?.requiredWorkflows?.length !== 5 ||
      m.isolatedLots[7]?.pullRequest !== 607 ||
      m.isolatedLots[7]?.headBranch !== 'review/portal-email-transport-default-deny-2026-11-12' ||
      // Default non-logging mail transport candidate must remain isolated
      // above offline proof issuance. No real mail, session, or account.
      m.isolatedLots[7]?.baseBranch !== m.isolatedLots[6]?.headBranch ||
      m.isolatedLots[7]?.requiredWorkflows?.length !== 7 ||
      [
        'Testes do portal',
        'Validacao isolada Laravel F1-01-A',
        'Portal GSC F102/F105/F107 — synthetic OAuth security contracts',
        'Portal F64 - Private Container Homologation Smoke',
        'Portal browser auth - private Docker rehearsal',
        'Portal F94 - Laravel ingress source NO-GO imports',
        'Portal private auth gateway - disposable deployment rehearsal',
      ].some(name => !m.isolatedLots[7]?.requiredWorkflows?.includes(name)) ||
      [
        'Testes do portal',
        'Validacao isolada Laravel F1-01-A',
        'Portal GSC F102/F105/F107 — synthetic OAuth security contracts',
        'Portal F64 - Private Container Homologation Smoke',
        'Portal browser identity - PostgreSQL 18 candidate',
      ].some(name => !m.isolatedLots[6]?.requiredWorkflows?.includes(name)) ||
      [
        'Testes do portal',
        'Validacao isolada Laravel F1-01-A',
        'Portal GSC F102/F105/F107 — synthetic OAuth security contracts',
        'Portal F64 - Private Container Homologation Smoke',
        'Portal browser identity - PostgreSQL 18 candidate',
        'Portal browser auth - private Docker rehearsal',
        'Portal private persistent database foundation',
      ].some(name => !m.isolatedLots[5]?.requiredWorkflows?.includes(name)) ||
      [
        'Testes do portal',
        'Validacao isolada Laravel F1-01-A',
        'Portal GSC F102/F105/F107 — synthetic OAuth security contracts',
        'Portal F64 - Private Container Homologation Smoke',
        'Portal browser identity - PostgreSQL 18 candidate',
        'Portal browser auth - private Docker rehearsal',
      ].some(name => !m.isolatedLots[4]?.requiredWorkflows?.includes(name)) ||
      m.isolatedLots.some(l => !plainRecord(l) ||
        !/^[a-f0-9]{40}$/.test(l.headSha ?? '') ||
        !Number.isSafeInteger(l.maxCommits) || l.maxCommits < 1 || l.maxCommits > 20 ||
        !Array.isArray(l.requiredWorkflows) || l.requiredWorkflows.length < 2 ||
        l.requiredWorkflows.length > 12 ||
        new Set(l.requiredWorkflows).size !== l.requiredWorkflows.length ||
        !l.requiredWorkflows.every(n => typeof n === 'string' && n.length > 2 && n.length < 160)) ||
      [...m.reviewLots, ...m.isolatedLots].some((l, i, all) =>
        all.findIndex(x => x.pullRequest === l.pullRequest ||
          x.headBranch === l.headBranch || x.headSha === l.headSha) !== i) ||
      !plainRecord(m.policy) ||
      Object.keys(m.policy).sort().join() !== [
        'antiRollbackResolved', 'deployApproved', 'independentAppSecDbaApproved',
        'mergeApproved', 'operationalRecoveryApproved'
      ].sort().join() ||
      Object.values(m.policy).some(value => value !== false)) {
    throw new Error('INVALID_REVIEW_MANIFEST');
  }
  return m;
}

export function assessCandidate(m, pull, runs, comparison, lot = null) {
  validateManifest(m);
  const errors = [];
  const c = lot ?? m.candidate;
  const required = lot?.requiredWorkflows ?? m.requiredWorkflows;
  const commitLimit = lot?.maxCommits ?? m.maxCommitsPerIntegrationLot;
  if (!plainRecord(pull) || pull.number !== c.pullRequest ||
      pull.state !== 'open' || pull.draft !== true || pull.merged_at !== null ||
      pull.head?.ref !== c.headBranch ||
      pull.head?.sha !== c.headSha ||
      pull.head?.repo?.full_name !== m.repository ||
      pull.base?.ref !== c.baseBranch ||
      pull.base?.repo?.full_name !== m.repository) {
    errors.push('DRAFT_PR_SHA_OR_ANCESTRY_CHANGED');
  }
  if (!Array.isArray(runs)) {
    errors.push('WORKFLOW_EVIDENCE_MISSING');
  }
  const latest = new Map();
  for (const run of Array.isArray(runs) ? runs : []) {
    // GitHub may show newer PUSH runs for the same SHA and workflow name.
    // They cannot supersede the PR-specific check, nor certify a different
    // PR or base. Filter first, then choose the latest PR run by ID/attempt.
    if (!plainRecord(run) || run.head_sha !== c.headSha ||
        run.event !== 'pull_request' || run.head_branch !== c.headBranch ||
        !Array.isArray(run.pull_requests) ||
        !run.pull_requests.some(p => p?.number === c.pullRequest &&
          p.head?.ref === c.headBranch && p.base?.ref === c.baseBranch) ||
        typeof run.name !== 'string' || !Number.isSafeInteger(run.id)) continue;
    const old = latest.get(run.name);
    if (!old || run.id > old.id ||
        (run.id === old.id && run.run_attempt > old.run_attempt)) {
      latest.set(run.name, run);
    }
  }
  const workflowEvidence = {};
  for (const name of required) {
    const run = latest.get(name);
    const valid = !!run && run.status === 'completed' &&
      run.conclusion === 'success' &&
      run.head_sha === c.headSha &&
      run.event === 'pull_request';
    workflowEvidence[name] = valid ? 'PASSED' : 'MISSING_OR_FAILED';
    if (!valid) errors.push('MISSING_OR_FAILED_CI:' + name);
  }

  if (!plainRecord(comparison) ||
      !Number.isSafeInteger(comparison.ahead_by) ||
      !Number.isSafeInteger(comparison.behind_by) ||
      comparison.ahead_by < 0 || comparison.behind_by !== 0 ||
      !['ahead', 'identical'].includes(comparison.status)) {
    errors.push('UNVERIFIED_OR_DIVERGED_DEV_ANCESTRY');
  }
  // The GitHub compare API truncates its file listing. Only use aggregate
  // ahead/behind counts; NEVER claim a full file review from its first page.
  const commitsAhead = Number.isSafeInteger(comparison?.ahead_by) ?
    comparison.ahead_by : null;
  const splitRequired = commitsAhead === null ||
    commitsAhead > commitLimit;
  const evidenceVerified = errors.length === 0;
  return {
    decision: !evidenceVerified ? 'BLOCKED_EVIDENCE' :
      splitRequired ? 'BLOCKED_SPLIT_INTEGRATION_LOTS' :
      'REVIEW_CANDIDATE_ONLY',
    evidenceVerified,
    splitRequired,
    comparisonBase: lot === null ? m.targetBranch : c.baseBranch,
    commitsAheadOfBase: commitsAhead,
    requiredWorkflowCount: required.length,
    workflowEvidence,
    errors,
    // Intentionally immutable irrespective of CI outcome.
    mergeApproved: false,
    deployApproved: false,
    customerAccessApproved: false,
    antiRollbackApproved: false,
  };
}

export function printableDecision(decision) {
  const { workflowEvidence, ...summary } = decision;
  return {
    ...summary,
    workflowsPassed: Object.values(workflowEvidence).filter(s => s === 'PASSED').length,
  };
}

async function githubGet(path, token) {
  const response = await fetch('https://api.github.com' + path, {
    headers: {
      Accept: 'application/vnd.github+json',
      Authorization: 'Bearer ' + token,
      'X-GitHub-Api-Version': '2022-11-28',
      'User-Agent': 'imagine-portal-readonly-integration-review',
    },
    signal: AbortSignal.timeout(25000),
  });
  if (!response.ok) throw new Error('GITHUB_READ_FAILED_' + response.status);
  return {
    data: await response.json(),
    paginated: (response.headers.get('link') || '').includes('rel="next"'),
  };
}

// The stacked candidate is always measured against dev, NOT its nested PR
// base. Only a separately pinned second-stage lot may compare to its parent.
export function comparisonBase(m, lot = null) {
  validateManifest(m);
  return lot === null ? m.targetBranch : lot.baseBranch;
}

export async function fetchEvidence(m, token, lot = null) {
  validateManifest(m);
  if (typeof token !== 'string' || token.length < 20) {
    throw new Error('MISSING_READ_ONLY_GITHUB_TOKEN');
  }
  const candidate = lot ?? m.candidate;
  const root = '/repos/' + m.repository;
  const pr = await githubGet(root + '/pulls/' + candidate.pullRequest, token);
  const runs = await githubGet(root + '/actions/runs?head_sha=' +
    candidate.headSha + '&per_page=100', token);
  const comparison = await githubGet(root + '/compare/' +
    encodeURIComponent(comparisonBase(m, lot)) + '...' +
    candidate.headSha, token);
  if (pr.paginated || runs.paginated ||
      !Array.isArray(runs.data?.workflow_runs) ||
      runs.data.total_count > runs.data.workflow_runs.length) {
    // Never silently accept the first 100 workflow runs. GitHub's
    // comparison commit/file listing MAY be paginated; its aggregate
    // ahead_by and behind_by values are the only fields consumed.
    throw new Error('INCOMPLETE_GITHUB_EVIDENCE');
  }
  return { pull: pr.data, runs: runs.data.workflow_runs, comparison: comparison.data };
}

export async function main(args = process.argv.slice(2)) {
  if (args.length !== 1 || args[0] !== '--verify-github') {
    process.stderr.write('Usage: node tools/portal-integration-gate.mjs --verify-github\n');
    return 64;
  }
  try {
    const manifest = validateManifest(JSON.parse(readFileSync(manifestUrl, 'utf8')));
    const { pull, runs, comparison } =
      await fetchEvidence(manifest, process.env.GITHUB_TOKEN);
    const result = assessCandidate(manifest, pull, runs, comparison);
    const lots = [];
    for (const lot of manifest.reviewLots) {
      const proof = await fetchEvidence(manifest, process.env.GITHUB_TOKEN, lot);
      lots.push({
        pullRequest: lot.pullRequest,
        ...printableDecision(
          assessCandidate(manifest, proof.pull, proof.runs, proof.comparison, lot)
        ),
      });
    }
    const isolatedLots = [];
    for (const lot of manifest.isolatedLots) {
      const proof = await fetchEvidence(manifest, process.env.GITHUB_TOKEN, lot);
      isolatedLots.push({
        pullRequest: lot.pullRequest,
        ...printableDecision(
          assessCandidate(manifest, proof.pull, proof.runs, proof.comparison, lot)
        ),
      });
    }
    const output = {
      decision: 'NO_GO_RELEASE_INTEGRATION_REVIEW_ONLY',
      stackedCandidate: printableDecision(result),
      smallLots: lots,
      isolatedLots,
      mergeApproved: false,
      deployApproved: false,
    };
    process.stdout.write(JSON.stringify(output, null, 2) + '\n');
    return result.evidenceVerified &&
      lots.every(item => item.evidenceVerified && !item.splitRequired) &&
      isolatedLots.every(item => item.evidenceVerified && !item.splitRequired) ? 0 : 65;
  } catch (error) {
    process.stderr.write(JSON.stringify({
      decision: 'BLOCKED_UNVERIFIED',
      reason: error instanceof Error ? error.message : 'UNKNOWN',
      mergeApproved: false,
      deployApproved: false,
    }) + '\n');
    return 65;
  }
}

if (process.argv[1] && import.meta.url === new URL('file://' + process.argv[1]).href) {
  process.exitCode = await main();
}
