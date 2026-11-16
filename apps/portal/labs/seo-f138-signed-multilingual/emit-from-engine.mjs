/** Portal F138: generate actual Engine F126 evidence, CI lab only. */
import {generateKeyPairSync,createHash,sign,randomBytes} from 'node:crypto';
import {mkdtemp,mkdir,writeFile,rm} from 'node:fs/promises';
import {tmpdir} from 'node:os';
import {join} from 'node:path';
import {signMultilingualBuildEvidence} from
  '../../../../.qa/seo-f138/src/seo-signed-multilingual-evidence-v2.mjs';

const engineSha='38b80fe77bd74ea6e19b8751014038ac3b9f8400';
const baseContext={
  tenantRef:'demo-tenant',runRef:'github-actions-f138',engineCommitSha:engineSha
};
const site='https://imaginecomunicacao.com.br/';
const routes=[
  ['pt-BR','pt-BR','/servicos/site-onepage/fortaleza-ce/'],
  ['en-US','en','/en/sites/onepage/fortaleza/'],
  ['es-ES','es','/es/sitios/onepage/fortaleza/']
];
const prefix=Buffer.from('IMAGINE-SEO-F126-SIGNED-MULTILINGUAL-REVIEW\n','ascii');
const scenarios=new Set([
  'valid','blocked','tampered','wrong-key','other-tenant','expired',
  'wrong-contract','bad-flags','wrong-status','extra-claim','wrong-prefix'
]);
function page(route,lang,body,alts=''){
  const url=new URL(route,site).href;
  return '<!doctype html><html lang="'+lang+'"><head><meta charset="utf-8">'+
    '<title>Imagine multilingual '+lang+' '+route+'</title>'+
    '<meta name="description" content="Independent regional service '+lang+' '+route+'">'+
    '<meta name="robots" content="index,follow">'+
    '<meta property="og:url" content="'+url+'">'+
    '<link rel="canonical" href="'+url+'">'+alts+
    '<script type="application/ld+json">{"@context":"https://schema.org","@type":"WebPage"}</script>'+
    '</head><body><h1>Imagine local marketing</h1>'+body+'</body></html>';
}
function resign(envelope,privateKey,change,signingPrefix=prefix){
  const claims=JSON.parse(Buffer.from(envelope.payloadBase64,'base64').toString('utf8'));
  change(claims);
  const raw=Buffer.from(JSON.stringify(claims));
  return {
    contractVersion:envelope.contractVersion,
    payloadBase64:raw.toString('base64'),
    signatureBase64:sign(null,Buffer.concat([signingPrefix,raw]),privateKey).toString('base64')
  };
}
async function main(){
  const [scenario,outEnvelope,outPublic]=process.argv.slice(2);
  if(process.argv.length!==5 || !scenarios.has(scenario)||
     process.env.CI!=='true'||process.env.APP_ENV!=='testing'||
     process.env.SEO_F126_SIGNER_LAB!=='1'||process.env.PORTAL_SEO_F138_LAB!=='1'||
     !outEnvelope||!outPublic)throw Error('F138 CI lab only');
  const root=await mkdtemp(join(tmpdir(),'portal-seo-f138-'));
  try{
    const alts=routes.map(([,lang,route])=>
      '<link rel="alternate" hreflang="'+lang+'" href="'+new URL(route,site).href+'">'
    ).join('');
    await writeFile(join(root,'index.html'),
      page('/','pt-BR',routes.map(x=>'<a href="'+x[2]+'">Service</a>').join('')));
    await writeFile(join(root,'robots.txt'),
      'User-agent: *\nAllow: /\nSitemap: '+site+'sitemap.xml\n');
    await writeFile(join(root,'sitemap.xml'),'<urlset>'+
      ['/',...routes.map(x=>x[2])].map(route=>
        '<url><loc>'+new URL(route,site).href+'</loc></url>').join('')+'</urlset>');
    for(const [locale,lang,route] of routes){
      const folder=join(root,...route.split('/').filter(Boolean));
      await mkdir(folder,{recursive:true});
      await writeFile(join(folder,'index.html'),
        page(route,lang,'Independent original text '+locale,
          scenario==='blocked'?'':alts));
    }
    const inventoryInput={
      sourceType:'operator_local_inventory_unverified',
      entries:routes.map(([locale,,route])=>({
        serviceSlug:'site-onepage',ibgeCode:'2304400',state:'CE',
        locale,canonicalPath:route,
        contentSha256:createHash('sha256').update('body-'+locale).digest('hex')
      }))
    };
    const keys=generateKeyPairSync('ed25519');
    const issuedAt=Math.floor(Date.now()/1000)+(scenario==='expired'?-1000:0);
    const signed=await signMultilingualBuildEvidence(
      {inventoryInput,buildDirectory:root,siteUrl:site},
      {...baseContext,...(scenario==='other-tenant'?{tenantRef:'another-tenant'}:{})},
      keys.privateKey,issuedAt
    );
    let envelope=signed.envelope;
    if(scenario==='tampered'){
      const claim=JSON.parse(Buffer.from(envelope.payloadBase64,'base64'));
      claim.tenantRef='tampered-tenant';
      envelope={...envelope,payloadBase64:Buffer.from(JSON.stringify(claim)).toString('base64')};
    }else if(scenario==='wrong-contract'){
      envelope={...envelope,contractVersion:'seo-f122-signed-build-report-v1'};
    }else if(scenario==='extra-claim'){
      envelope=resign(envelope,keys.privateKey,claim=>{claim.canPublish=true;});
    }else if(scenario==='bad-flags'||scenario==='wrong-status'){
      envelope=resign(envelope,keys.privateKey,claim=>{
        const report=JSON.parse(Buffer.from(claim.reportBase64,'base64'));
        if(scenario==='bad-flags')report.publicationAuthorized=true;
        else report.reviewStatus='blocked';
        const reportRaw=Buffer.from(JSON.stringify(report));
        claim.reportBase64=reportRaw.toString('base64');
        claim.reportSha256=createHash('sha256').update(reportRaw).digest('hex');
      });
    }else if(scenario==='wrong-prefix'){
      envelope=resign(envelope,keys.privateKey,()=>{},
        Buffer.from('IMAGINE-SEO-F122-SIGNED-BUILD-REPORT\n'));
    }
    const publicKey=(scenario==='wrong-key'
      ?generateKeyPairSync('ed25519').publicKey:keys.publicKey)
      .export({format:'der',type:'spki'});
    // Ed25519 SPKI DER ends with exactly 32 raw public key bytes.
    const rawKey=Buffer.from(publicKey).subarray(-32);
    await writeFile(outEnvelope,JSON.stringify(envelope),{mode:0o600,flag:'wx'});
    await writeFile(outPublic,rawKey.toString('base64')+'\n',{mode:0o600,flag:'wx'});
  }finally{await rm(root,{force:true,recursive:true});}
}
main().catch(()=>{process.stderr.write('F138 synthetic emitter failed\n');process.exitCode=1;});
