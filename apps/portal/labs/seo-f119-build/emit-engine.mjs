/**
 * Portal SEO F119: run actual immutable SEO Engine F114 against a disposable
 * local HTML build, pass ONLY the original JSON DTO to PHP. Never use URLs
 * or private content from the operator; all files are synthetic CI fixtures.
 */
import {mkdtemp,mkdir,writeFile,rm} from 'node:fs/promises';
import {join,resolve} from 'node:path';
import {tmpdir} from 'node:os';
import {createHash} from 'node:crypto';
import {pathToFileURL} from 'node:url';

const [scenario,...extra]=process.argv.slice(2);
if(process.env.CI!=='true'||process.env.APP_ENV!=='testing'||
   process.env.PORTAL_SEO_F118_LAB!=='1'||
   !['clean','missing','wrong-locale','private-preview'].includes(scenario)||
   extra.length)
  throw Error('F119: fixed test-only scenario required');

const engine=pathToFileURL(resolve('.qa/seo-f118/src/geo-static-build-review.mjs')).href;
const {reviewMunicipalStaticBuild}=await import(engine);
const origin='https://imaginecomunicacao.com.br/';
const service='/servicos/site-onepage/fortaleza-ce/';
const doc=(route,content,marker='')=>{
  const absolute=new URL(route,origin).href;
  return '<!doctype html><html lang="pt-BR"><head>'+
    '<meta charset="utf-8">'+
    '<title>Exemplo técnico original '+route+'</title>'+
    '<meta name="description" content="Exemplo de laboratório com descrição exclusiva '+route+'">'+
    '<meta name="robots" content="index,follow">'+
    '<meta property="og:url" content="'+absolute+'">'+
    '<link rel="canonical" href="'+absolute+'">'+marker+
    '<script type="application/ld+json">{"@context":"https://schema.org","@type":"WebPage"}</script>'+
    '</head><body><h1>Página de laboratório</h1>'+content+'</body></html>';
};
const root=await mkdtemp(join(tmpdir(),'portal-seo-f119-'));
try{
  const folder=join(root,'servicos','site-onepage','fortaleza-ce');
  await mkdir(folder,{recursive:true});
  await writeFile(join(root,'index.html'),doc('/','<a href="'+service+'">Visitar</a>'));
  const marker=scenario==='private-preview'
    ?'<meta name="imagine:editorial-preview" content="private-unpublished-review">'
    :'';
  await writeFile(join(folder,'index.html'),doc(service,
    '<p>Exemplo editorial sintético para testes</p>',marker));
  await writeFile(join(root,'robots.txt'),
    'User-agent: *\nAllow: /\nSitemap: '+origin+'sitemap.xml\n');
  await writeFile(join(root,'sitemap.xml'),
    '<urlset><url><loc>'+origin+'</loc></url>'+
    '<url><loc>'+new URL(service,origin).href+'</loc></url></urlset>');

  const canonicalPath=scenario==='missing'
    ?'/servicos/site-onepage/missing-synthetic/':service;
  const locale=scenario==='wrong-locale'?'en-US':'pt-BR';
  const input={
    inventoryInput:{
      sourceType:'operator_local_inventory_unverified',
      entries:[{
        serviceSlug:'site-onepage',ibgeCode:'2304400',state:'CE',
        locale,canonicalPath,
        contentSha256:createHash('sha256')
          .update('synthetic editable content').digest('hex')
      }]
    },
    buildDirectory:root,siteUrl:origin
  };
  const dto=await reviewMunicipalStaticBuild(input);
  if(scenario==='clean'&&dto.reviewStatus!=='human_independent_review_required')
    throw Error('F119: pristine static build unexpectedly blocked');
  if(scenario!=='clean'&&dto.reviewStatus!=='blocked')
    throw Error('F119: expected negative mutation was not blocked');
  process.stdout.write(JSON.stringify(dto));
}finally{
  await rm(root,{recursive:true,force:true});
}
