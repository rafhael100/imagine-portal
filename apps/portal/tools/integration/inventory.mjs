import { execFileSync } from 'node:child_process';
import { mkdirSync, writeFileSync } from 'node:fs';
import { join } from 'node:path';
import { pathToFileURL } from 'node:url';

const BASE = 'refs/remotes/origin/dev/fast-track-portal-2026-11-05';

export function classify(path) {
  if (path.startsWith('apps/portal/database/migrations/')) return 'database_migration';
  if (/^apps\/portal\/(app|routes|bootstrap)\//.test(path)
      && /(Identity|Authorization|PortalAccess|CommercialDeliveryReview|Sessions?|Permissions?|Auth|Guard)/i.test(path)) {
    return 'identity_security';
  }
  if (/^apps\/portal\/app\/Domain\//.test(path)) return 'domain';
  if (path.startsWith('.github/workflows/')) return 'ci_workflow';
  if (/^apps\/portal\/app\/Presentation\//.test(path)
      || path.startsWith('apps/portal/resources/views/')) return 'presentation';
  if (/^apps\/portal\/(app|routes|bootstrap|config)\//.test(path)) return 'application_runtime';
  if (path.startsWith('apps/portal/labs/')) return 'isolated_lab';
  if (path.startsWith('apps/portal/tests/')) return 'automated_tests';
  if (path.startsWith('apps/portal/docs/') || path.startsWith('docs/')) return 'documentation';
  return 'other';
}

/**
 * Parse git diff --name-status -z, including rename/copy pairs. Paths remain
 * opaque data and are never passed back through a shell.
 */
export function parseNameStatusZ(raw) {
  if (typeof raw !== 'string') throw new TypeError('Expected NUL-delimited git diff.');
  const parts = raw.split('\0');
  if (parts.at(-1) === '') parts.pop();
  const entries = [];
  for (let i = 0; i < parts.length;) {
    const status = parts[i++];
    if (!/^(?:[AMDTUXB]|[RC][0-9]{1,3})$/.test(status)) {
      throw new Error('Unexpected diff status.');
    }
    if (i >= parts.length) throw new Error('Incomplete changed-path record.');
    const first = parts[i++];
    const renamed = status.startsWith('R') || status.startsWith('C');
    const path = renamed ? parts[i++] : first;
    if (!path || path.includes('\0') || path.startsWith('/') || path.includes('..\\')) {
      throw new Error('Invalid changed file path.');
    }
    if (renamed && (!first || !path)) throw new Error('Incomplete rename/copy path.');
    entries.push({
      status,
      ...(renamed ? { previous_path: first } : {}),
      path,
      category: classify(path),
    });
  }
  return entries;
}

function git(args) {
  return execFileSync('git', args, {
    encoding: 'utf8', maxBuffer: 12 * 1024 * 1024, stdio: ['ignore','pipe','pipe'],
  }).trimEnd();
}

function gitZ(args) {
  return execFileSync('git', args, {
    encoding: 'utf8', maxBuffer: 12 * 1024 * 1024, stdio: ['ignore','pipe','pipe'],
  });
}

export function buildReport({ base, head, mergeBase, leftRight, records }) {
  const counts = {};
  for (const row of records) counts[row.category] = (counts[row.category] ?? 0) + 1;
  const [baseOnly, headOnly] = leftRight.split(/\s+/).map(Number);
  if (!Number.isSafeInteger(baseOnly) || !Number.isSafeInteger(headOnly)) {
    throw new Error('Invalid revision counts.');
  }
  return {
    schema: 'imagine.portal.integration-inventory.v1',
    source: 'git name-status / merge-base / rev-list, GitHub-hosted CI only',
    base,
    head,
    merge_base: mergeBase,
    commits_only_on_dev: baseOnly,
    commits_only_on_candidate: headOnly,
    changed_files_total: records.length,
    categories: counts,
    changed_paths: records,
    decision: {
      integration_approved: false,
      independent_appsec_dba_review_required: true,
      staging_homologation_required: true,
      may_merge: false,
      may_deploy: false,
    },
  };
}

export function markdownReport(report) {
  const categoryRows = Object.entries(report.categories)
    .sort(([a],[b]) => a.localeCompare(b))
    .map(([name,count]) => '| '+name+' | '+count+' |')
    .join('\n');
  return [
    '## Inventário de integração — SOMENTE LEITURA',
    '',
    'Base de referência: `'+report.base+'`',
    '',
    'HEAD da revisão: `'+report.head+'`',
    '',
    'Commits exclusivos da candidata: **'+report.commits_only_on_candidate+'**',
    '',
    'Commits exclusivos da base dev: **'+report.commits_only_on_dev+'**',
    '',
    'Arquivos alterados: **'+report.changed_files_total+'**',
    '',
    '| Categoria de risco/área | Arquivos |',
    '| --- | ---: |',
    categoryRows || '| Nenhum arquivo alterado | 0 |',
    '',
    '**NO-GO:** esta análise lista caminhos e dependências, mas não revisa segurança,',
    'não confirma homologação e não autoriza merge, credenciais ou deploy.',
    'Ver issue #341 e revisões independentes #315 / #251.',
    '',
  ].join('\n');
}

/**
 * Partition each changed path into exactly one reviewer packet. These files
 * are a work queue, NOT proof that any human checked their contents.
 */
export function reviewPackets(report) {
  const accepted = new Set([
    'database_migration', 'identity_security', 'domain',
    'ci_workflow', 'presentation', 'application_runtime',
    'isolated_lab', 'automated_tests', 'documentation', 'other',
  ]);
  const instructions = {
    database_migration: 'Revisar compatibilidade, backup, rollback e RLS no PostgreSQL descartável antes de staging.',
    identity_security: 'Requer parecer AppSec/DBA independente: origem de sessão, MFA, permissões, tenant e revogação.',
    domain: 'Conferir regras de negócio aprovadas, transições e decisões sem autorização operacional implícita.',
    ci_workflow: 'Confirmar ações somente de leitura, dependências fixadas, CI real e nenhuma etapa de deploy.',
    presentation: 'Verificar dados fictícios, acessibilidade, noindex, CSP, sem rotas/ações reais.',
    application_runtime: 'Auditar fronteiras HTTP, default-deny, ausência de API de cliente ou migração não aprovada.',
    isolated_lab: 'Provar isolamento dos laboratórios e nenhuma referência por runtime de produção.',
    automated_tests: 'Conferir casos negativos, critérios de cobertura e evidências de execução por SHA.',
    documentation: 'Verificar atualização de decisões e distinção entre propostas e autorizações.',
    other: 'Classificação manual obrigatória antes de aceitar alterações fora dos módulos conhecidos.',
  };
  const packets = {};
  let observed = 0;
  for (const [category, count] of Object.entries(report.categories)) {
    if (!accepted.has(category) || !Number.isSafeInteger(count) || count < 1) {
      throw new Error('Unexpected review category; cannot write reviewer packet.');
    }
    const files = report.changed_paths.filter(row => row.category === category);
    if (files.length !== count) throw new Error('Reviewer packet count mismatch.');
    observed += files.length;
    const lines = [
      '# Lote de revisão — '+category,
      '',
      '**NO-GO — arquivos NÃO revisados.** '+instructions[category],
      '',
      'Head candidato: '+report.head,
      '',
      'Total de caminhos: '+files.length,
      '',
      'Cada entrada abaixo é um caminho/estado Git, nunca prova de aprovação.',
      '',
      ...files.map(row => '- '+row.status+' '+JSON.stringify(row.path)
        +(row.previous_path ? ' (origem: '+JSON.stringify(row.previous_path)+')' : '')),
      '',
    ];
    packets[category] = lines.join('\n');
  }
  if (observed !== report.changed_files_total) {
    throw new Error('Reviewer packet partition is incomplete.');
  }
  return packets;
}

/**
 * The GitHub pull_request event's GITHUB_SHA points at a temporary merge ref.
 * Never silently report that commit as the candidate PR head.
 */
export function requireCandidateHead(actualHead, expectedHead) {
  if (!/^[0-9a-f]{40}$/.test(actualHead)
      || !/^[0-9a-f]{40}$/.test(expectedHead)
      || actualHead !== expectedHead) {
    throw new Error('Inventory checkout does not match the actual candidate HEAD.');
  }
  return actualHead;
}

function main() {
  if (process.env.GITHUB_ACTIONS !== 'true') {
    throw new Error('F144 inventory must run in GitHub Actions only.');
  }
  const outputDir = process.env.PORTAL_REVIEW_INVENTORY_DIR;
  if (!outputDir) throw new Error('Missing CI-only artifact output directory.');
  const head = requireCandidateHead(
    git(['rev-parse', 'HEAD']),
    process.env.PORTAL_EXPECTED_HEAD ?? '',
  );
  const mergeBase = git(['merge-base', BASE, 'HEAD']);
  const leftRight = git(['rev-list','--left-right','--count',BASE+'...HEAD']);
  const records = parseNameStatusZ(
    gitZ(['diff','--name-status','-z','--find-renames',mergeBase,'HEAD'])
  );
  const report = buildReport({
    base: BASE, head, mergeBase, leftRight, records,
  });
  mkdirSync(outputDir, { recursive: true, mode: 0o700 });
  writeFileSync(join(outputDir,'inventory.json'), JSON.stringify(report,null,2)+'\n',{mode:0o600});
  const markdown = markdownReport(report);
  writeFileSync(join(outputDir,'review-summary.md'), markdown, {mode:0o600});
  const packetDir = join(outputDir, 'packets');
  mkdirSync(packetDir, {recursive:true,mode:0o700});
  for (const [category, body] of Object.entries(reviewPackets(report))) {
    writeFileSync(join(packetDir,category+'.md'),body,{mode:0o600});
  }
  if (process.env.GITHUB_STEP_SUMMARY) {
    writeFileSync(process.env.GITHUB_STEP_SUMMARY, markdown, {flag:'a'});
  }
  process.stdout.write(markdown);
}

if (process.argv[1] && import.meta.url === pathToFileURL(process.argv[1]).href) {
  main();
}
