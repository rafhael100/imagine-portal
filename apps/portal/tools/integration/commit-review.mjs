import { execFileSync } from 'node:child_process';
import { mkdirSync, writeFileSync } from 'node:fs';
import { join } from 'node:path';
import { pathToFileURL } from 'node:url';
import {
  classify, parseNameStatusZ, requireCandidateHead, selectReviewBase,
} from './inventory.mjs';

const CRITICAL = new Set([
  'identity_security', 'database_migration', 'application_runtime',
  'node_identity_security', 'node_sensitive_export', 'node_application_runtime',
  'integration_review_tooling', 'ci_workflow', 'other',
]);

function git(args, nul = false) {
  const result = execFileSync('git', args, {
    encoding: 'utf8',
    maxBuffer: 24 * 1024 * 1024,
    stdio: ['ignore', 'pipe', 'pipe'],
  });
  return nul ? result : result.trimEnd();
}

export function assessCommit({ sha, parents, subject, changedPaths }) {
  if (!/^[a-f0-9]{40}$/.test(sha)
      || !Number.isSafeInteger(parents) || parents < 0
      || !Array.isArray(changedPaths) || !Array.isArray(changedPaths)) {
    throw new Error('Invalid commit metadata.');
  }
  const categories = Array.from(
    new Set(changedPaths.map(row => {
      if (!row || typeof row.path !== 'string'
          || row.category !== classify(row.path)) {
        throw new Error('Untrusted commit file classification.');
      }
      return row.category;
    })),
  ).sort();
  const critical = categories.some(category => CRITICAL.has(category));
  const touchesTests = categories.includes('automated_tests');
  return {
    sha,
    parents,
    merge_commit: parents > 1,
    // Subjects are untrusted review hints, never commands or approvals.
    subject: String(subject).replace(/[\r\n\t]+/g, ' ').slice(0, 140),
    risk: critical ? 'requires_security_or_runtime_review'
      : categories.includes('domain') ? 'requires_business_review'
      : 'requires_standard_review',
    mixed_areas: categories.length > 1,
    changed_file_count: changedPaths.length,
    categories,
    touches_tests_in_same_commit: touchesTests,
    changed_paths: changedPaths,
    approved: false,
    may_merge: false,
    may_deploy: false,
  };
}

export function buildCommitReview({ base, head, commits }) {
  if (!Array.isArray(commits) || commits.length > 5000
      || new Set(commits.map(item => item.sha)).size !== commits.length) {
    throw new Error('Invalid or duplicated commit review history.');
  }
  const stats = {
    commits_total: commits.length,
    security_or_runtime_review: 0,
    business_review: 0,
    standard_review: 0,
    mixed_area_commits: 0,
    merge_commits: 0,
    commits_without_tests_in_same_commit: 0,
  };
  for (const commit of commits) {
    if (commit.risk === 'requires_security_or_runtime_review') {
      ++stats.security_or_runtime_review;
    } else if (commit.risk === 'requires_business_review') {
      ++stats.business_review;
    } else if (commit.risk === 'requires_standard_review') {
      ++stats.standard_review;
    } else {
      throw new Error('Unexpected commit risk classification.');
    }
    if (commit.mixed_areas) ++stats.mixed_area_commits;
    if (commit.merge_commit) ++stats.merge_commits;
    // This is only a signal to reviewer, not proof tests are absent.
    if (!commit.touches_tests_in_same_commit) {
      ++stats.commits_without_tests_in_same_commit;
    }
  }
  return {
    schema: 'imagine.portal.integration-commit-review.v1',
    base,
    head,
    note: 'Every commit is unreviewed; historical tests can live in other commits.',
    stats,
    commits,
    decision: {
      approved: false,
      may_merge: false,
      may_deploy: false,
      independent_appsec_dba_required: true,
      integration_review_required: true,
    },
  };
}

export function markdownCommitReview(report) {
  const flagged = report.commits.filter(
    row => row.risk === 'requires_security_or_runtime_review',
  );
  const top = flagged.slice(0, 60);
  const lines = [
    '# Mapa de risco por commit — REVISÃO HUMANA PENDENTE',
    '',
    'Base: `' + report.base + '`',
    'Candidata: `' + report.head + '`',
    '',
    '| Indicador | Total |',
    '| --- | ---: |',
    '| Commits no intervalo | ' + report.stats.commits_total + ' |',
    '| Alterações envolvendo IAM/runtime/migrations/CI/outros | ' +
      report.stats.security_or_runtime_review + ' |',
    '| Revisão de domínio sem mudanças críticas na mesma revisão | ' +
      report.stats.business_review + ' |',
    '| Revisão padrão | ' + report.stats.standard_review + ' |',
    '| Commits que tocam mais de uma área | ' +
      report.stats.mixed_area_commits + ' |',
    '| Commits de merge | ' + report.stats.merge_commits + ' |',
    '',
    '## Primeiros commits com revisão prioritária',
    '',
    'Os primeiros ' + top.length + ' commits estão listados abaixo; o JSON contém **todos**.',
    '',
    '| SHA curto | Categorias | Arquivos | Merge? |',
    '| --- | --- | ---: | --- |',
    ...top.map(item => '| `' + item.sha.slice(0, 12) + '` | ' +
      item.categories.join(', ') + ' | ' + item.changed_file_count +
      ' | ' + (item.merge_commit ? 'sim' : 'não') + ' |'),
    '',
    '**NO-GO**: este relatório classifica mudanças, não faz revisão de código',
    'ou auditoria independente. Nenhum commit é automaticamente liberado.',
    'Use os pacotes F144 e a issue #341 antes de planejar qualquer integração.',
    '',
  ];
  return lines.join('\n');
}

function main() {
  if (process.env.GITHUB_ACTIONS !== 'true') {
    throw new Error('F145 requires GitHub Actions read-only execution.');
  }
  const folder = process.env.PORTAL_REVIEW_INVENTORY_DIR;
  if (!folder) throw new Error('Missing private review output directory.');
  const head = requireCandidateHead(
    git(['rev-parse','HEAD']),
    process.env.PORTAL_EXPECTED_HEAD ?? '',
  );
  const base=selectReviewBase(process.env.PORTAL_REVIEW_BASE ?? '');
  const shas = git(['rev-list','--reverse','--topo-order',base+'..HEAD'])
    .split('\n').filter(Boolean);
  if (shas.length > 5000) throw new Error('Candidate history exceeds review bound.');

  const commits = shas.map(sha => {
    const parents = git(['rev-list','--parents','-n','1',sha]).split(' ').length - 1;
    const subject = git(['show','-s','--format=%s',sha]);
    const paths = parseNameStatusZ(git([
      'diff-tree','--no-commit-id','--name-status','-r','-z',
      '--first-parent','--find-renames',sha,
    ], true));
    return assessCommit({ sha, parents, subject, changedPaths: paths });
  });

  const report = buildCommitReview({
    base, head, commits,
  });
  mkdirSync(folder, {recursive:true,mode:0o700});
  writeFileSync(join(folder,'commit-review.json'),
    JSON.stringify(report,null,2)+'\n',{mode:0o600});
  const summary = markdownCommitReview(report);
  writeFileSync(join(folder,'commit-review.md'),summary,{mode:0o600});
  if (process.env.GITHUB_STEP_SUMMARY) {
    writeFileSync(process.env.GITHUB_STEP_SUMMARY,summary,{flag:'a'});
  }
  process.stdout.write(summary);
}

if (process.argv[1] && import.meta.url === pathToFileURL(process.argv[1]).href) {
  main();
}
