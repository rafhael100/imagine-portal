import test from 'node:test';
import assert from 'node:assert/strict';
import {spawnSync} from 'node:child_process';
import {parseNameStatusZ,categorizeReviewPath,makeDiffReviewManifest,
  markdownReviewSummary} from './diff-review.mjs';

const A='a'.repeat(40),B='b'.repeat(40);

test('F130 parses exact null-delimited name/status entries including >300 files',()=>{
  const values=Array.from({length:355},(_,i)=>[
    i%2?'M':'A',
    'apps/portal/resources/views/components/portal/item-'+i+'.blade.php'
  ]);
  const binary=Buffer.from(values.flat().join('\0')+'\0','utf8');
  const parsed=parseNameStatusZ(binary);
  assert.equal(parsed.length,355);
  assert.equal(parsed[354].status,'A');
  const report=makeDiffReviewManifest({
    baseSha:A,headSha:B,commitsAhead:322,changes:parsed
  });
  assert.equal(report.comparison.changedFiles,355);
  assert.equal(report.comparison.commitsAhead,322);
  assert.equal(report.files.length,355);
  assert.equal(report.completeGitDiffInventoryProduced,true);
  assert.equal(report.independentReviewPerformed,false);
  assert.equal(report.mainMergeAuthorized,false);
  assert.equal(report.productionDeploymentAuthorized,false);
  assert.equal(report.customerDataAccessAuthorized,false);
  assert.match(report.releaseStatus,/^HOLD_/);
  const md=markdownReviewSummary(report);
  assert.match(md,/Changed files: 355/);
  assert.equal(md.split('- [ ] ').length-1,355);
  assert.ok(!md.includes('- [x]'));
});

test('F130 never downgrades critical auth/db/security or unknown code',()=>{
  const cases=[
    ['apps/portal/database/migrations/2026_11_15_000009_create_events.php','critical'],
    ['apps/portal/app/Domain/SeoReview/PortalSeoPgSigningKeyLock.php','critical'],
    ['apps/portal/app/Domain/Authorization/Permissions.php','critical'],
    ['apps/portal/routes/web.php','critical'],
    ['.github/workflows/release.yml','high'],
    ['apps/portal/composer.lock','high'],
    ['apps/portal/resources/views/seo/demo.blade.php','medium'],
    ['apps/portal/docs/PORTAL-SEO-RELEASE.md','low'],
    ['other/unclassified/new-module.ts','high'],
    ['.env.production','critical'],
  ];
  for(const [path,risk] of cases)
    assert.equal(categorizeReviewPath(path).risk,risk,path);
});

test('F130 reports all changed paths without marking any reviewed',()=>{
  const r=makeDiffReviewManifest({
    baseSha:A,headSha:B,commitsAhead:2,
    changes:[
      {status:'A',path:'apps/portal/database/migrations/new.php'},
      {status:'D',path:'apps/portal/routes/web.php'},
      {status:'M',path:'.github/workflows/deploy.yml'},
      {status:'M',path:'apps/portal/docs/CHANGELOG.md'},
    ]
  });
  assert.equal(r.comparison.changedFiles,4);
  assert.equal(r.riskCounts.critical,2);
  assert.equal(r.riskCounts.high,1);
  assert.equal(r.riskCounts.low,1);
  assert.equal(r.priorityReviewPaths.length,3);
  assert.ok(r.files.every(x=>x.independentlyReviewed===false));
  const summary=markdownReviewSummary(r);
  assert.ok(summary.includes('Mandatory')||summary.includes('High priority'));
  assert.ok(summary.includes('NO-GO'));
});

test('F130 rejects tampered null-delimited input and unsafe file names',()=>{
  for(const bytes of [
    Buffer.from('M\0file'),Buffer.from('R100\0path\0'),
    Buffer.from('M\0../password\0'),
    Buffer.from('M\0web/public\ntrick\0'),
    Buffer.from('M\0/absolute/private\0'),
    Buffer.from('M\0folder//double\0'),
    Buffer.alloc(0)
  ])assert.throws(()=>parseNameStatusZ(bytes),/F130/);
  for(const path of ['../secret','.env\nM','file\\evil','/srv/private']){
    assert.throws(()=>categorizeReviewPath(path),/F130/);
  }
});

test('F130 rejects duplicate, invalid commit, empty and unsafe manifests',()=>{
  const one={status:'A',path:'src/file.txt'};
  for(const payload of [
    {baseSha:A,headSha:A,commitsAhead:1,changes:[one]},
    {baseSha:'main',headSha:B,commitsAhead:1,changes:[one]},
    {baseSha:A,headSha:B,commitsAhead:0,changes:[one]},
    {baseSha:A,headSha:B,commitsAhead:1,changes:[]},
    {baseSha:A,headSha:B,commitsAhead:1,changes:[one,one]},
    {baseSha:A,headSha:B,commitsAhead:1,
      changes:[{status:'U',path:'src/file.txt'}]}
  ])assert.throws(()=>makeDiffReviewManifest(payload),/F130/);
});

test('F130 collection CLI refuses to run outside an actual GitHub Actions job',()=>{
  const script=new URL('./collect-real-diff.mjs',import.meta.url);
  const r=spawnSync(process.execPath,[script.pathname],{
    encoding:'utf8',timeout:10000,maxBuffer:128*1024,
    env:{...process.env,CI:'true',GITHUB_ACTIONS:'false',
      F130_BASE_SHA:A,F130_HEAD_SHA:B}
  });
  assert.notEqual(r.status,0);
  assert.match(r.stderr,/trusted GitHub Actions base\/head required/);
});
