/**
 * F125: exercise actual Engine F121 signer on real disposable static HTML,
 * then output a test-only envelope and separate trusted public key for PHP.
 * Private key is never written to disk or logged.
 */
import {mkdtemp,mkdir,writeFile,rm} from 'node:fs/promises';
import {tmpdir} from 'node:os';
import {join,resolve} from 'node:path';
import {pathToFileURL} from 'node:url';
import {createHash,generateKeyPairSync} from 'node:crypto';

const [scenario,outEnvelope,outPublic,...extra]=process.argv.slice(2);
const modes=new Set([
  'valid','private-preview','wrong-locale','tampered',
  'wrong-key','other-tenant','expired'
]);
if(process.env.CI!=='true'||
   process.env.APP_ENV!=='testing'||
   process.env.SEO_F121_SIGNER_LAB!=='1'||
   process.env.PORTAL_SEO_F125_LAB!=='1'||
   !modes.has(scenario)||!outEnvelope||!outPublic||
   outEnvelope===outPublic||extra.length)
  throw Error('F125: disposable CI source-signer fixture required');

const engineFile=resolve('.qa/seo-f125/src/seo-signed-build-evidence.mjs');
const {signLocalMunicipalBuildEvidence:issue}=await import(
  pathToFileURL(engineFile).href
);
const root=await mkdtemp(join(tmpdir(),'seo-f125-'));
const origin='https://imaginecomunicacao.com.br/';
const route='/servicos/site-onepage/fortaleza-ce/';
const page=(path,body,marker='')=>{
  const url=new URL(path,origin).href;
  return '<!doctype html><html lang="pt-BR"><head>'+
    '<meta charset="utf-8"><title>Imagine editorial de laboratório '+path+'</title>'+
    '<meta name="description" content="Exemplo SEO original '+path+'">'+
    '<meta name="robots" content="index,follow">'+
    '<meta property="og:url" content="'+url+'">'+
    '<link rel="canonical" href="'+url+'">'+marker+
    '<script type="application/ld+json">{"@context":"https://schema.org","@type":"WebPage"}</script>'+
    '</head><body><h1>Imagine SEO</h1>'+body+'</body></html>';
};
try{
  const folder=join(root,'servicos','site-onepage','fortaleza-ce');
  await mkdir(folder,{recursive:true});
  await writeFile(join(root,'index.html'),
    page('/','<a href="'+route+'">Site Onepage</a>'));
  const marker=scenario==='private-preview'?
    '<meta name="imagine:editorial-preview" content="private-unpublished-review">':'';
  await writeFile(join(folder,'index.html'),
    page(route,'<p>Site Onepage sintético de revisão.</p>',marker));
  await writeFile(join(root,'robots.txt'),
    'User-agent: *\nAllow: /\nSitemap: '+origin+'sitemap.xml\n');
  await writeFile(join(root,'sitemap.xml'),'<urlset>'+
    '<url><loc>'+origin+'</loc></url>'+
    '<url><loc>'+new URL(route,origin).href+'</loc></url></urlset>');

  const input={
    inventoryInput:{
      sourceType:'operator_local_inventory_unverified',
      entries:[{
        serviceSlug:'site-onepage',ibgeCode:'2304400',state:'CE',
        locale:scenario==='wrong-locale'?'en-US':'pt-BR',
        canonicalPath:route,
        contentSha256:createHash('sha256')
          .update('synthetic editorial-only text').digest('hex')
      }]
    },
    buildDirectory:root,
    siteUrl:origin
  };
  const context={
    tenantRef:scenario==='other-tenant'?'other-tenant':'demo-tenant',
    runRef:'github-actions-f122',
    engineCommitSha:'3c75b8e7b64f4173a17f4daab45cea1b8bdc5554'
  };
  const {privateKey,publicKey}=generateKeyPairSync('ed25519');
  const issuedAt=Math.floor(Date.now()/1000)-
    (scenario==='expired'?900:0);
  const result=await issue(input,context,privateKey,issuedAt);
  if(!result||result.publicationAuthorized!==false)
    throw Error('F125: issuer tried to authorize publication');

  const envelope=result.envelope;
  if(scenario==='tampered'){
    const payload=JSON.parse(Buffer.from(envelope.payloadBase64,'base64')
      .toString('utf8'));
    payload.tenantRef='forged-tenant';
    envelope.payloadBase64=Buffer.from(JSON.stringify(payload))
      .toString('base64');
  }
  function rawPub(key){
    const der=key.export({format:'der',type:'spki'});
    if(der.length!==44 ||
       der.subarray(0,12).toString('hex')!=='302a300506032b6570032100')
      throw Error('F125: unsupported Ed25519 public format');
    return der.subarray(12);
  }
  const publicToVerify=scenario==='wrong-key'?
    generateKeyPairSync('ed25519').publicKey:publicKey;
  await writeFile(outEnvelope,JSON.stringify(envelope),{
    mode:0o600,flag:'wx'
  });
  await writeFile(outPublic,rawPub(publicToVerify).toString('base64'),{
    mode:0o600,flag:'wx'
  });
  console.log('F125: Engine-owned signer emitted synthetic evidence');
}finally{
  await rm(root,{recursive:true,force:true});
}
