import assert from 'node:assert/strict';
import {spawnSync} from 'node:child_process';
import {
  copyFileSync,mkdirSync,mkdtempSync,readFileSync,writeFileSync,
  rmSync,symlinkSync
} from 'node:fs';
import {tmpdir} from 'node:os';
import {resolve,join,dirname} from 'node:path';

const tool='apps/portal/tools/f111-audit-invitation-release-fence.mjs';
const env={...process.env,APP_ENV:'testing',PORTAL_F111_RELEASE_FENCE_LAB:'1'};
const tmp=mkdtempSync(join(tmpdir(),'portal-f111-no-go-'));
const files=[
  'apps/portal/app/Providers/AppServiceProvider.php',
  'apps/portal/app/Domain/Identity/Invitations/DenyingPortalInvitationIdentitySource.php',
  'apps/portal/app/Domain/Identity/Invitations/PortalInvitationIdentitySource.php',
  'apps/portal/app/Domain/Identity/Invitations/PortalInvitationPreflight.php',
  'apps/portal/app/Domain/Identity/Invitations/PortalInvitationPreflightVerdict.php',
  'apps/portal/app/Domain/Identity/Invitations/PortalInvitationActorEvidence.php',
  'apps/portal/bootstrap/app.php',
  'apps/portal/routes/console.php',
  'apps/portal/routes/web.php'
];
const at=rel=>join(tmp,rel);
const run=(root=tmp,e=env)=>spawnSync('node',[tool,root],{
  env:e,encoding:'utf8',timeout:15000
});
let checked=0;
const check=(v,m)=>{assert.ok(v,m);checked++;};
const deny=(m,e=env)=>{
  const r=run(tmp,e);
  check(r.status!==0&&r.stdout==='',m+': '+r.stdout);
};
const mutation=(rel,from,to,name)=>{
  const p=at(rel),actual=readFileSync(p,'utf8');
  check(actual.includes(from),'fixture anchor exists for '+name);
  writeFileSync(p,actual.replace(from,to));
  deny(name);
  writeFileSync(p,actual);
};
try{
  for(const rel of files){
    mkdirSync(dirname(at(rel)),{recursive:true});
    copyFileSync(resolve(rel),at(rel));
  }
  const baseline=spawnSync('node',[tool],{env,encoding:'utf8'});
  check(baseline.status===0,'current source must pass: '+baseline.stderr);
  const copied=run();
  check(copied.status===0,'minimal fixture must pass: '+copied.stderr);
  const report=JSON.parse(copied.stdout);
  check(report.production_approved===false &&
    report.real_identity_verified===false &&
    report.invitation_acceptance_enabled===false &&
    report.permitted_http_route==='/up',
    'report must explicitly deny operational permissions');

  mutation(
    'apps/portal/app/Providers/AppServiceProvider.php',
    '$this->app->singleton(DenyingPortalInvitationIdentitySource::class);',
    '$this->app->singleton(UntrustedIdentitySource::class);',
    'remove default denying identity adapter'
  );
  mutation(
    'apps/portal/app/Providers/AppServiceProvider.php',
    '$app->make(DenyingPortalInvitationIdentitySource::class)',
    '$app->make(UnsafeAuthenticatedPortalIdentitySource::class)',
    'rebind Portal identity source to unreviewed provider'
  );
  mutation(
    'apps/portal/app/Domain/Identity/Invitations/DenyingPortalInvitationIdentitySource.php',
    'return null;',
    'return $this->forgedEvidence();',
    'default identity resolver is no longer deny-all'
  );
  mutation(
    'apps/portal/app/Domain/Identity/Invitations/PortalInvitationPreflightVerdict.php',
    'public function canAuthorizeHttp(): bool\n    {\n        return false;\n    }'.replaceAll('\\n','\n'),
    'public function canAuthorizeHttp(): bool\n    {\n        return true;\n    }'.replaceAll('\\n','\n'),
    'synthetic verdict grants HTTP'
  );
  mutation(
    'apps/portal/app/Domain/Identity/Invitations/PortalInvitationPreflightVerdict.php',
    'public function canConsumeInvitation(): bool\n    {\n        return false;\n    }'.replaceAll('\\n','\n'),
    'public function canConsumeInvitation(): bool\n    {\n        return true;\n    }'.replaceAll('\\n','\n'),
    'synthetic verdict consumes real invitation'
  );
  mutation(
    'apps/portal/app/Domain/Identity/Invitations/PortalInvitationPreflightVerdict.php',
    'public function canIssueSession(): bool\n    {\n        return false;\n    }'.replaceAll('\\n','\n'),
    'public function canIssueSession(): bool\n    {\n        return true;\n    }'.replaceAll('\\n','\n'),
    'synthetic verdict issues login session'
  );
  mutation(
    'apps/portal/app/Domain/Identity/Invitations/PortalInvitationActorEvidence.php',
    'MAX_SYNTHETIC_MFA_AGE_SECONDS = 300',
    'MAX_SYNTHETIC_MFA_AGE_SECONDS = 86400',
    'unsafe MFA freshness relaxation'
  );
  mutation(
    'apps/portal/app/Domain/Identity/Invitations/PortalInvitationPreflight.php',
    'PortalInvitationPreflightVerdict::blocked()',
    'PortalInvitationPreflightVerdict::allow()',
    'preflight approval replaces fail-closed verdict'
  );
  mutation(
    'apps/portal/routes/web.php',
    'Apenas /up e exposta',
    "Apenas /up e exposta\nRoute::post('/portal/convites/aceitar', fn()=>true);",
    'customer invitation HTTP route introduced'
  );
  mutation(
    'apps/portal/bootstrap/app.php',
    "health: '/up',",
    "api: 'routes/api.php',\n        health: '/up',",
    'API routes exposed from bootstrap'
  );
  const http=at('apps/portal/app/Http');
  mkdirSync(http,{recursive:true});
  const controller=join(http,'InviteAcceptController.php');
  writeFileSync(controller,
    "<?php // App\\Domain\\Identity\\Invitations\\PortalInvitationPreflight\n");
  deny('controller references synthetic preflight');
  rmSync(http,{recursive:true,force:true});

  const provider=at('apps/portal/app/Providers/AppServiceProvider.php');
  rmSync(provider);
  symlinkSync(at('apps/portal/bootstrap/app.php'),provider);
  deny('provider source symlink rejected');
  rmSync(provider);
  copyFileSync(resolve('apps/portal/app/Providers/AppServiceProvider.php'),provider);
  deny('production must not run auditor',{...env,APP_ENV:'production'});
  deny('unconfigured static-fence flag must deny',{
    ...env,PORTAL_F111_RELEASE_FENCE_LAB:'0'
  });
  check(run().status===0,'restored locked-down fixture passes');
}finally{rmSync(tmp,{recursive:true,force:true});}
console.log('PASS PORTAL-F111 '+checked+' static invitation release NO-GO assertions');
