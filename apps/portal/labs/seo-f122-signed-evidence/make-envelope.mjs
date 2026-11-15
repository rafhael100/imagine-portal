/**
 * F122 — disposable Ed25519 signer for a REAL F114 engine report produced
 * by F119 from synthetic HTML. The private key never leaves this process.
 *
 * NO production issuer is established: all keys are ephemeral CI fixtures.
 * The trusted verification key is stored separately in RUNNER_TEMP only.
 */
import {generateKeyPairSync,createHash,randomBytes,sign} from 'node:crypto';
import {readFile,writeFile} from 'node:fs/promises';

const [reportPath,envelopePath,publicKeyPath,scenario,...extra]=process.argv.slice(2);
const modes=new Set([
  'valid','bad-signature','tampered-payload','other-tenant',
  'other-run','expired','other-engine','wrong-public-key',
  'wrong-issuer','unexpected-claim'
]);
if(process.env.CI!=='true'||process.env.APP_ENV!=='testing'||
   process.env.PORTAL_SEO_F122_LAB!=='1'||
   !reportPath||!envelopePath||!publicKeyPath||
   new Set([reportPath,envelopePath,publicKeyPath]).size!==3 ||
   extra.length||!modes.has(scenario))
  throw Error('F122: CI-only signed evidence fixture required');

const report=await readFile(reportPath);
if(report.length<100||report.length>100000)
  throw Error('F122: bounded synthetic Engine report required');
const parsed=JSON.parse(report.toString('utf8'));
if(parsed.contractVersion!=='seo-municipal-static-build-reconciliation-offline-v1'||
   parsed.sourceType!=='operator_supplied_inventory_and_local_build_unverified')
  throw Error('F122: actual Engine F114 JSON required');

const {publicKey,privateKey}=generateKeyPairSync('ed25519');
const now=Math.floor(Date.now()/1000);
const claims={
  issuer:'imagine-seo-engine-ci-lab',
  audience:'imagine-portal-ci-lab',
  tenantRef:scenario==='other-tenant'?'another-tenant':'demo-tenant',
  runRef:scenario==='other-run'?'different-ci-job':'github-actions-f122',
  engineCommitSha:scenario==='other-engine'?'0'.repeat(40):
    '7847c8c66d5be228a08a94282da562c790cdd440',
  issuedAt:scenario==='expired'?now-900:now,
  expiresAt:scenario==='expired'?now-600:now+240,
  nonce:randomBytes(24).toString('base64url'),
  reportSha256:createHash('sha256').update(report).digest('hex'),
  reportBase64:report.toString('base64'),
};
if(scenario==='wrong-issuer')claims.issuer='untrusted-seo-engine';
if(scenario==='unexpected-claim')claims.publishAllowed=true;

const raw=Buffer.from(JSON.stringify(claims),'utf8');
const signed=Buffer.concat([
  Buffer.from('IMAGINE-SEO-F122-SIGNED-BUILD-REPORT\n','ascii'),raw
]);
const signature=sign(null,signed,privateKey);
const envelope={
  contractVersion:'seo-f122-signed-build-report-v1',
  payloadBase64:raw.toString('base64'),
  signatureBase64:signature.toString('base64')
};
if(scenario==='bad-signature'){
  const bad=Buffer.from(signature);
  bad[0]^=0x80;
  envelope.signatureBase64=bad.toString('base64');
}
if(scenario==='tampered-payload'){
  const modified=JSON.parse(raw.toString('utf8'));
  modified.tenantRef='another-tenant';
  envelope.payloadBase64=Buffer.from(JSON.stringify(modified))
    .toString('base64');
}

function publicRaw(key){
  const der=key.export({format:'der',type:'spki'});
  const expected='302a300506032b6570032100';
  if(der.length!==44||!der.subarray(0,12).equals(Buffer.from(expected,'hex')))
    throw Error('F122: unsupported Ed25519 public key format');
  return der.subarray(12);
}
let trustedPublic=publicRaw(publicKey);
if(scenario==='wrong-public-key')
  trustedPublic=publicRaw(generateKeyPairSync('ed25519').publicKey);

await writeFile(envelopePath,JSON.stringify(envelope),{flag:'wx',mode:0o600});
await writeFile(publicKeyPath,trustedPublic.toString('base64'),{
  flag:'wx',mode:0o600
});
console.log('F122: synthetic Ed25519 fixture ready; no issuer deployed');
