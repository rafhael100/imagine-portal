/**
 * F130: enumerate ENTIRE git diff in a checked-out GitHub Actions runner
 * (unlike GitHub compare API's file-list truncation). Public path metadata
 * only; no file contents, no external network, no release authorization.
 */
import {execFileSync,spawnSync} from 'node:child_process';
import {join} from 'node:path';
import {writeFile,appendFile} from 'node:fs/promises';
import {parseNameStatusZ,makeDiffReviewManifest,markdownReviewSummary}
  from './diff-review.mjs';

const SHA=/^[a-f0-9]{40}$/;
const base=process.env.F130_BASE_SHA,head=process.env.F130_HEAD_SHA;
const runner=process.env.RUNNER_TEMP,summary=process.env.GITHUB_STEP_SUMMARY;
if(process.env.CI!=='true'||process.env.GITHUB_ACTIONS!=='true'||
   !SHA.test(base??'')||!SHA.test(head??'')||
   base===head||!runner||!summary||process.argv.length!==2)
  throw Error('F130: trusted GitHub Actions base/head required');

const run=(args,options={})=>execFileSync('git',args,{
  cwd:process.cwd(),maxBuffer:32*1024*1024,...options
});
const actual=run(['rev-parse','HEAD'],{encoding:'utf8'}).trim();
if(actual!==head)
  throw Error('F130: checkout does not match exact reviewed SHA');
const ancestor=spawnSync('git',['merge-base','--is-ancestor',base,head],{
  cwd:process.cwd(),encoding:'utf8'
});
if(ancestor.status!==0)
  throw Error('F130: base is not an ancestor of head');

const commitsAhead=Number(run(
  ['rev-list','--count',base+'..'+head],{encoding:'utf8'}
).trim());
const diff=run([
  'diff','--name-status','--no-renames','-z',base,head
]);
const changes=parseNameStatusZ(diff);
const report=makeDiffReviewManifest({
  baseSha:base,headSha:head,commitsAhead,changes
});
const output=join(runner,'portal-seo-f130-full-diff-review.json');
await writeFile(output,JSON.stringify(report,null,2)+'\n',{
  flag:'wx',mode:0o600
});
await appendFile(summary,markdownReviewSummary(report));
console.log(JSON.stringify({
  contractVersion:report.contractVersion,
  changedFiles:report.comparison.changedFiles,
  commitsAhead:report.comparison.commitsAhead,
  riskCounts:report.riskCounts,
  releaseStatus:report.releaseStatus,
  reviewApproved:false
}));
