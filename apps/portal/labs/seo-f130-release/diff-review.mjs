/**
 * Portal SEO F130: complete diff inventory and prioritization only.
 * Never claims that file content was reviewed or production was authorized.
 */
const sha=v=>typeof v==='string'&&/^[a-f0-9]{40}$/.test(v);
const count=v=>Number.isSafeInteger(v)&&v>=0&&v<=1000000;
function invalid(){throw Error('F130: invalid bounded Git review input');}

export function parseNameStatusZ(raw){
  if(!Buffer.isBuffer(raw)||raw.length===0||raw.length>30*1024*1024)
    invalid();
  const str=raw.toString('utf8');
  if(!str.endsWith('\0'))invalid();
  const fields=str.slice(0,-1).split('\0');
  if(fields.length%2!==0||fields.length>40000)invalid();
  const out=[];
  for(let i=0;i<fields.length;i+=2){
    const status=fields[i],path=fields[i+1];
    if(!/^[AMDT]$/.test(status)||
       !path||path.length>2048||
       path.startsWith('/')||path.includes('\\')||
       path.split('/').some(x=>x===''||x==='.'||x==='..')||
       /[\x00-\x1f\x7f]/.test(path))invalid();
    out.push({status,path});
  }
  return out;
}
export function categorizeReviewPath(path){
  if(typeof path!=='string'||!path||path.length>2048)invalid();
  const p=path.toLowerCase();
  if(/(^|\/)(\.env($|\.)|id_rsa|id_ed25519|secrets?($|[/.])|private[_-]?key)/.test(p))
    return {area:'sensitive-configuration',risk:'critical'};
  if(/(^|\/)(database\/migrations|database\/seeders)\//.test(p))
    return {area:'database-migrations',risk:'critical'};
  if(/(^|\/)(domain\/seoreview|domain\/portalaccess|authorization|auth|middleware)\//.test(p))
    return {area:'authentication-and-authorization',risk:'critical'};
  if(/(^|\/)routes\//.test(p))
    return {area:'http-routes',risk:'critical'};
  if(/(^|\/)(oauth|billing|payments?|webhooks?|token[-_]?vault)(\/|[._-])/.test(p))
    return {area:'external-services-and-tokens',risk:'critical'};
  if(p.startsWith('.github/workflows/'))
    return {area:'ci-and-release-workflows',risk:'high'};
  if(/(^|\/)(composer\.(json|lock)|package(-lock)?\.json|pnpm-lock\.yaml|dockerfile|compose\.ya?ml)$/.test(p))
    return {area:'dependencies-and-infrastructure',risk:'high'};
  if(/(^|\/)(config|infra|deploy|scripts)\//.test(p))
    return {area:'configuration-and-operations',risk:'high'};
  if(/(^|\/)(tests?|specs?|labs)\//.test(p))
    return {area:'tests-and-laboratories',risk:'medium'};
  if(/(^|\/)(resources\/views|assets|components)\//.test(p))
    return {area:'frontend-and-ui',risk:'medium'};
  if(/(^|\/)docs?\//.test(p)||p.endsWith('.md'))
    return {area:'documentation',risk:'low'};
  return {area:'unclassified-code-or-assets',risk:'high'};
}
export function makeDiffReviewManifest(args){
  const {baseSha,headSha,commitsAhead,changes}=args??{};
  if(!sha(baseSha)||!sha(headSha)||baseSha===headSha||
     !count(commitsAhead)||commitsAhead===0||
     !Array.isArray(changes)||changes.length===0||
     changes.length>20000)invalid();
  const seen=new Set(),riskCounts={},areaCounts={};
  const files=changes.map(entry=>{
    if(!entry||typeof entry!=='object'||
       !['A','M','D','T'].includes(entry.status)||
       typeof entry.path!=='string'||seen.has(entry.path))
      invalid();
    seen.add(entry.path);
    const classification=categorizeReviewPath(entry.path);
    riskCounts[classification.risk]=(riskCounts[classification.risk]??0)+1;
    areaCounts[classification.area]=(areaCounts[classification.area]??0)+1;
    return {
      status:entry.status,path:entry.path,
      area:classification.area,risk:classification.risk,
      independentlyReviewed:false
    };
  });
  files.sort((a,b)=>
    ['critical','high','medium','low'].indexOf(a.risk)-
    ['critical','high','medium','low'].indexOf(b.risk)||
    a.path.localeCompare(b.path));
  return {
    contractVersion:'portal-seo-f130-full-repository-diff-review-v1',
    comparison:{
      exactBaseCommitSha:baseSha,
      exactHeadCommitSha:headSha,
      commitsAhead,changedFiles:files.length
    },
    riskCounts,areaCounts,
    priorityReviewPaths:files.filter(x=>
      x.risk==='critical'||x.risk==='high').map(x=>x.path),
    files,
    completeGitDiffInventoryProduced:true,
    independentReviewPerformed:false,
    mainMergeAuthorized:false,
    productionDeploymentAuthorized:false,
    customerDataAccessAuthorized:false,
    releaseStatus:'HOLD_INDEPENDENT_CODE_SECURITY_AND_OPERATIONS_REVIEW'
  };
}
export function markdownReviewSummary(report){
  if(report?.contractVersion!=='portal-seo-f130-full-repository-diff-review-v1')
    invalid();
  const s=report.comparison;
  const lines=[
    '## Imagine Portal — F130 complete local Git diff inventory','',
    '**NO-GO / NO-MERGE / NO-DEPLOY.** An inventory is not a code review.','',
    '- Exact base: '+s.exactBaseCommitSha,
    '- Exact head: '+s.exactHeadCommitSha,
    '- Commits ahead: '+s.commitsAhead,
    '- Changed files: '+s.changedFiles,
    '- Critical paths: '+(report.riskCounts.critical??0),
    '- High-risk paths: '+(report.riskCounts.high??0),'',
    '### High priority: review every path',''
  ];
  const tick='\x60';
  for(const file of report.files){
    if(file.risk==='critical'||file.risk==='high')
      lines.push('- [ ] '+tick+file.path.replaceAll(tick,'')+tick+
        ' ('+file.risk+', '+file.area+')');
  }
  lines.push('','### Remaining paths','');
  for(const file of report.files){
    if(file.risk!=='critical'&&file.risk!=='high')
      lines.push('- [ ] '+tick+file.path.replaceAll(tick,'')+tick+
        ' ('+file.risk+', '+file.area+')');
  }
  lines.push('','**All checkboxes intentionally unchecked.** CI is not approval.');
  return lines.join('\n')+'\n';
}
