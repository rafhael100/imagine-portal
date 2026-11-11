import { execFileSync, spawnSync } from 'node:child_process';
import { mkdirSync, writeFileSync } from 'node:fs';
import { join } from 'node:path';
import { pathToFileURL } from 'node:url';
import { classify, requireCandidateHead } from './inventory.mjs';

export const PINNED_SEO_HEAD = '500c7cbd2379a1869a52a8ad3133af5c62de830a';
const SEO_BRANCH = 'refs/remotes/origin/review/portal-gsc-f142-overlap-static-panel-2026-11-11';

function git(args, preserveNul = false) {
  const data = execFileSync('git',args,{
    encoding:'utf8',maxBuffer:24*1024*1024,
    stdio:['ignore','pipe','pipe'],
  });
  return preserveNul ? data : data.trimEnd();
}

export function readNulPaths(data) {
  if (typeof data !== 'string') throw new Error('NUL diff must be a string.');
  if (data.length && !data.endsWith('\0')) throw new Error('Truncated NUL-delimited diff.');
  const paths = data.split('\0');
  if (paths.at(-1)==='') paths.pop();
  if (paths.some(path=>!path || path.startsWith('/'))) {
    throw new Error('Unexpected Git diff path.');
  }
  return paths;
}

export function compareCandidatePaths(portalPaths, seoPaths) {
  if (!Array.isArray(portalPaths) || !Array.isArray(seoPaths)) {
    throw new Error('Invalid candidate path sets.');
  }
  const portal = new Set(portalPaths);
  const seo = new Set(seoPaths);
  if (portal.size !== portalPaths.length || seo.size !== seoPaths.length) {
    throw new Error('Duplicate changed paths in Git inventory.');
  }
  const overlap = [...portal].filter(path=>seo.has(path)).sort();
  const categories = {};
  for (const path of overlap) {
    const category = classify(path);
    categories[category]=(categories[category]??0)+1;
  }
  return {
    portal_changed_files:portal.size,
    seo_changed_files:seo.size,
    shared_changed_files:overlap.length,
    overlap_categories:categories,
    shared_paths:overlap,
  };
}

export function mergeAssessment({
  portalSha, seoSha, mergeBase, counts, paths, mergeStatus, mergeOutput,
}) {
  for (const sha of [portalSha,seoSha,mergeBase]) {
    if (!/^[0-9a-f]{40}$/.test(sha)) throw new Error('Invalid pinned commit identity.');
  }
  if (!/^(?:0|1)$/.test(String(mergeStatus))) {
    throw new Error('Git merge-tree did not return a definitive result.');
  }
  const [seoOnly,portalOnly]=String(counts).trim().split(/\s+/).map(Number);
  if (![seoOnly,portalOnly].every(x=>Number.isSafeInteger(x)&&x>=0)) {
    throw new Error('Invalid candidate divergence counts.');
  }
  return {
    schema:'imagine.portal.parallel-stacks-review.v1',
    portal_head:portalSha,
    seo_head:seoSha,
    common_ancestor:mergeBase,
    commits_only_seo:seoOnly,
    commits_only_portal:portalOnly,
    ...paths,
    simulated_merge_clean:mergeStatus===0,
    simulated_merge_conflicts:mergeStatus===1,
    // This is only a local merge-tree simulation; never a branch merge.
    merge_simulation_output:String(mergeOutput).slice(0,16000),
    decision:{
      integration_approved:false,
      may_merge:false,
      may_deploy:false,
      manual_conflict_review_required:true,
      independent_appsec_dba_required:true,
    },
  };
}

export function renderMergeAssessment(report) {
  const safePaths=report.shared_paths.slice(0,35).map(p =>
    '- '+JSON.stringify(p)
  ).join('\n');
  return [
    '# Portal x SEO/GSC — prova de sobreposição e simulação de merge',
    '',
    'Portal (commit real): `'+report.portal_head+'`',
    'SEO/GSC (commit fixado): `'+report.seo_head+'`',
    'Ancestral comum: `'+report.common_ancestor+'`',
    '',
    '| Métrica | Total |',
    '| --- | ---: |',
    '| Commits exclusivos de SEO/GSC | '+report.commits_only_seo+' |',
    '| Commits exclusivos do Portal | '+report.commits_only_portal+' |',
    '| Arquivos tocados no Portal | '+report.portal_changed_files+' |',
    '| Arquivos tocados em SEO/GSC | '+report.seo_changed_files+' |',
    '| Caminhos tocados por ambas | '+report.shared_changed_files+' |',
    '| Merge-tree encontrou conflito? | '+(report.simulated_merge_conflicts?'SIM':'não')+' |',
    '',
    '## Primeiros caminhos que precisam de revisão dos dois responsáveis',
    '',
    safePaths || 'Nenhum caminho compartilhado (ainda requer revisão).',
    '',
    '**NO-GO:** sobreposição não significa conflito e merge-tree limpo não',
    'significa segurança ou integração aprovada. A simulação só escreve objetos',
    'Git descartáveis no runner; não altera dev, main, VPS nem produção.',
    'Ver issues #341, #315 e #251.',
    '',
  ].join('\n');
}

/** F147 deliberately requires clean merge-tree while F146 records conflicts as data. */
export function requireCleanConvergence(report, requested) {
  if (requested && (!report?.simulated_merge_clean || report.simulated_merge_conflicts)) {
    throw new Error('F147 permission convergence still has unresolved Git conflicts.');
  }
  return true;
}

function main() {
  if (process.env.GITHUB_ACTIONS !== 'true') {
    throw new Error('Parallel-stack assessment is CI-only.');
  }
  const folder=process.env.PORTAL_REVIEW_INVENTORY_DIR;
  if (!folder) throw new Error('Review artifact folder is required.');
  const portalSha=requireCandidateHead(
    git(['rev-parse','HEAD']),process.env.PORTAL_EXPECTED_HEAD??'',
  );
  if (git(['rev-parse',SEO_BRANCH]) === '') {
    throw new Error('SEO comparison branch was not checked out.');
  }
  git(['cat-file','-e',PINNED_SEO_HEAD+'^{commit}']);
  const isAncestor=spawnSync('git',[
    'merge-base','--is-ancestor',PINNED_SEO_HEAD,SEO_BRANCH,
  ],{encoding:'utf8'});
  if (isAncestor.status!==0) {
    throw new Error('Pinned SEO SHA is not reachable in the fetched SEO review branch.');
  }

  const mergeBase=git(['merge-base',portalSha,PINNED_SEO_HEAD]);
  const portalPaths=readNulPaths(git([
    'diff','--name-only','-z',mergeBase,portalSha,
  ],true));
  const seoPaths=readNulPaths(git([
    'diff','--name-only','-z',mergeBase,PINNED_SEO_HEAD,
  ],true));
  const paths=compareCandidatePaths(portalPaths,seoPaths);
  const counts=git(['rev-list','--left-right','--count',
    PINNED_SEO_HEAD+'...'+portalSha]);
  const merge=spawnSync('git',[
    'merge-tree','--write-tree','--name-only','--messages',
    portalSha,PINNED_SEO_HEAD,
  ],{
    encoding:'utf8',
    maxBuffer:24*1024*1024,
  });
  const report=mergeAssessment({
    portalSha,seoSha:PINNED_SEO_HEAD,mergeBase,counts,paths,
    mergeStatus:merge.status,mergeOutput:merge.stdout??'',
  });
  mkdirSync(folder,{recursive:true,mode:0o700});
  writeFileSync(join(folder,'parallel-stacks.json'),
    JSON.stringify(report,null,2)+'\n',{mode:0o600});
  const summary=renderMergeAssessment(report);
  writeFileSync(join(folder,'parallel-stacks.md'),summary,{mode:0o600});
  if (process.env.GITHUB_STEP_SUMMARY) {
    writeFileSync(process.env.GITHUB_STEP_SUMMARY,summary,{flag:'a'});
  }
  process.stdout.write(summary);
  requireCleanConvergence(
    report,
    process.env.PORTAL_REQUIRE_CLEAN_MERGE === 'true',
  );
}

if (process.argv[1] && import.meta.url===pathToFileURL(process.argv[1]).href) {
  main();
}
