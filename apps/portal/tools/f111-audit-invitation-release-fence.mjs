#!/usr/bin/env node
// PORTAL-F111: static NO-GO review fence, not live authentication/AppSec proof.
// Fail if a pending F110 identity source or verdict can accidentally be wired
// to HTTP/claims. Real releases require independent security review & runtime.
import {lstatSync,readFileSync,readdirSync,existsSync} from 'node:fs';
import {join,resolve,dirname,extname} from 'node:path';
import {fileURLToPath} from 'node:url';

const deny=()=>{
  process.stderr.write('PORTAL-F111 DENIED unsafe invitation identity release surface\n');
  process.exit(2);
};
if(process.env.APP_ENV!=='testing' ||
   process.env.PORTAL_F111_RELEASE_FENCE_LAB!=='1' ||
   process.argv.length>3) deny();

const root=resolve(process.argv[2]||
  join(dirname(fileURLToPath(import.meta.url)),'../../..'));
const read=(rel,max=96*1024)=>{
  const path=join(root,rel);
  const stat=lstatSync(path);
  if(!stat.isFile() || stat.isSymbolicLink() || stat.size<=0 ||
     stat.size>max) throw Error('bad repository file');
  return readFileSync(path,'utf8');
};
const must=(yes)=>{if(!yes)throw Error('F111 invariant mismatch');};
const count=(src,regex)=>[...src.matchAll(regex)].length;
const prefix='apps/portal/';
const identity='app/Domain/Identity/Invitations/';

try {
  const provider=read(prefix+'app/Providers/AppServiceProvider.php');
  const denying=read(prefix+identity+'DenyingPortalInvitationIdentitySource.php');
  const source=read(prefix+identity+'PortalInvitationIdentitySource.php');
  const preflight=read(prefix+identity+'PortalInvitationPreflight.php');
  const verdict=read(prefix+identity+'PortalInvitationPreflightVerdict.php');
  const evidence=read(prefix+identity+'PortalInvitationActorEvidence.php');
  const bootstrap=read(prefix+'bootstrap/app.php');

  // The production container may resolve ONLY the deny-all Portal source.
  must(count(provider,/^\s*\$this->app->singleton\(DenyingPortalInvitationIdentitySource::class\);/gm)===1);
  must(count(provider,/^\s*\$this->app->bind\(\s*\n\s*PortalInvitationIdentitySource::class,/gm)===1);
  must(count(provider,/\$app->make\(DenyingPortalInvitationIdentitySource::class\)/g)===1);
  must(count(provider,/\$this->app->(?:bind|singleton)\(\s*\n?\s*PortalInvitationIdentitySource::class/g)===1);
  must(!/(?:\b(?:Route|Http|Cookie|Auth|DB)\s*::|\brequest\s*\()/i.test(
    provider.split('public function register(): void')[1]?.split('public function boot(): void')[0]||''
  ));

  // Runtime identity lookup is always NULL; constructing a DTO never proves identity.
  must(/implements PortalInvitationIdentitySource/.test(denying));
  must(/function findFor\([\s\S]*?\): \?PortalInvitationActorEvidence\s*\{\s*return null;\s*\}/.test(denying));
  must(!/\b(?:DB|Http|Request|Cookie|Auth|CRM)\s*::/.test(denying));

  // Static contracts only; no ability to redeem or grant anything real.
  must(/interface PortalInvitationIdentitySource/.test(source));
  must(/function findFor\(/.test(source));
  must(preflight.includes('$this->identities->findFor('));
  must(preflight.includes('PortalInvitationSecret::digestCandidate('));
  must(preflight.includes('PortalInvitationPreflightVerdict::blocked()'));
  must(preflight.includes('PortalInvitationPreflightVerdict::syntheticCandidate()'));
  must(!/\b(?:DB|Route|Http|Cookie|Auth|Mail|Queue)\s*::/.test(preflight));
  must(evidence.includes('SYNTHETIC/UNTRUSTED'));
  must(evidence.includes('MAX_SYNTHETIC_MFA_AGE_SECONDS = 300'));

  for(const name of ['canConsumeInvitation','canIssueSession','canAuthorizeHttp']){
    const exact=new RegExp(
      'public function '+name+'\\(\\): bool\\s*\\{\\s*return false;\\s*\\}'
    );
    must(exact.test(verdict));
  }
  must(!verdict.includes('public string $') && !verdict.includes('public PortalUserId $'));

  // No invitation, SSO, login, API or customer HTTP route may be exposed.
  const routeDir=join(root,prefix+'routes');
  const routes=readdirSync(routeDir).sort();
  must(routes.join(',')==='console.php,web.php');
  for(const rel of routes){
    const contents=read(prefix+'routes/'+rel);
    must(!/\bRoute\s*::/.test(contents));
    must(!/PortalInvitation(?:Preflight|IdentitySource|ActorEvidence)/.test(contents));
    must(!/(?:^|\W)(?:login|invitation\.consumed)\s*\(/i.test(contents));
  }
  must(/health:\s*['"]\/up['"]/.test(bootstrap));
  must(!/\bapi\s*:/.test(bootstrap) && !/\bRoute\s*::/.test(bootstrap));
  must(!/PortalInvitation(?:Preflight|IdentitySource|ActorEvidence)/.test(bootstrap));

  const httpRoot=join(root,prefix+'app/Http');
  let scanned=0;
  function inspect(path){
    const s=lstatSync(path);
    must(!s.isSymbolicLink());
    if(s.isDirectory()){
      for(const item of readdirSync(path))inspect(join(path,item));
      return;
    }
    must(s.isFile() && s.size<=1024*1024);
    must(['.php','.mjs','.js','.json'].includes(extname(path)));
    const c=readFileSync(path,'utf8');
    must(!/PortalInvitation(?:Preflight|IdentitySource|ActorEvidence|Secret)/.test(c));
    must(!/portal_f10[5-9]_/.test(c));
    scanned++;
  }
  if(existsSync(httpRoot))inspect(httpRoot);

  process.stdout.write(JSON.stringify({
    schema_version:'portal.f111.invitation-identity-release-fence.v1',
    production_approved:false,
    real_identity_verified:false,
    invitation_acceptance_enabled:false,
    permitted_http_route:'/up',
    http_files_scanned:scanned
  })+'\n');
}catch{deny();}
