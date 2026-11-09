<?php
declare(strict_types=1);
/** Exporta somente o exemplo editorial; não lê conta, DB ou snapshot real. */
require dirname(__DIR__).'/vendor/autoload.php';
$app=require dirname(__DIR__).'/bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$output=$argv[1]??null;
if(!is_string($output)||$output===''||str_contains($output,"\0")){
    fwrite(STDERR,"Usage: php bin/export-commercial-editorial-demo.php <output.html>\n");
    exit(2);
}
$html=view('portal.demo.commercial-editorial')->render();
if(!str_contains($html,'Informações exclusivamente fictícias') ||
   !str_contains($html,'name="robots" content="noindex,nofollow"') ||
   !str_contains($html,'Não existe botão ativo de aprovação')){
   throw new \RuntimeException('Unsafe or incomplete demo output');
}
if(!is_dir(dirname($output)))throw new \RuntimeException('Output folder must exist');
if(file_put_contents($output,$html,LOCK_EX)===false)throw new \RuntimeException('Write error');
echo "Commercial editorial read-only demo exported (synthetic content; no routes).\n";
