<?php
declare(strict_types=1);
$terminalState=null;$auth=null;$pending=null;$active=null;$passed=false;
register_shutdown_function(static function()use(&$terminalState):void{if(is_string($terminalState))system('stty '.escapeshellarg($terminalState).' 2>/dev/null');});
try{
    if($argc!==1||PHP_SAPI!=='cli'||!stream_isatty(STDIN)||!stream_isatty(STDOUT)||getenv('APP_ENV')!=='staging'||getenv('PORTAL_OPERATOR_ACTIVATION')!=='PRIVATE_AUTH_ONLY')throw new RuntimeException();
    require __DIR__.'/bootstrap.php';
    $q=$db->prepare("SELECT m.organization_id FROM public.portal_identities i JOIN public.portal_memberships m ON m.identity_id=i.id JOIN public.portal_organizations o ON o.id=m.organization_id WHERE i.email_normalized=? AND i.status='active' AND m.status='active' AND o.status='active' AND i.email_verified_at<=clock_timestamp()");$q->execute(['rafhael@imaginecomunicacao.com.br']);$rows=$q->fetchAll(PDO::FETCH_COLUMN);if(count($rows)!==1)throw new RuntimeException();
    $runtime=new PDO($dsn,'portal_browser_runtime',$config->value('identity_password'),$options);
    $auth=new \App\Infrastructure\Identity\PostgresPortalBrowserAuthentication($runtime,'portal_identity_dev',$config->value('proof_key'),base64_decode($config->value('totp_key'),true),$config->value('scope_pepper'));
    $terminalState=trim((string)shell_exec('stty -g 2>/dev/null'));if($terminalState==='')throw new RuntimeException();
    $read=static function(string $prompt):string{
        echo $prompt;system('stty -echo 2>/dev/null',$exit);if($exit!==0)throw new RuntimeException();
        try{$value=fgets(STDIN,1100);}finally{system('stty echo 2>/dev/null');echo "\n";}
        if(!is_string($value)||!str_ends_with($value,"\n"))throw new RuntimeException();return rtrim($value,"\r\n");
    };
    echo "Teste privado de senha + aplicativo autenticador. A sessao sera revogada ao final.\n";
    $password=$read('Senha do Portal (digitacao oculta): ');
    $pending=$auth->beginPassword('rafhael@imaginecomunicacao.com.br',$password,$rows[0],'127.0.0.1');unset($password);
    if($pending===null)throw new RuntimeException();
    $code=$read('Codigo atual do APLICATIVO AUTENTICADOR, 6 numeros (nao e o e-mail): ');
    $active=$auth->completeMfa($pending,$code,'127.0.0.1');unset($code);
    if($active===null||!$auth->isActiveBusinessSession($active))throw new RuntimeException();
    foreach(['services.view','services.manage','billing.view','members.manage']as$permission){
        $q=$runtime->prepare('SELECT public.portal_browser_permission_preflight(?,CAST(? AS uuid),?)');$q->execute([hash('sha256',$active),$rows[0],$permission]);
        if(in_array($q->fetchColumn(),[true,1,'t','1'],true))throw new RuntimeException();
    }
    if(!$auth->revoke($active)||$auth->isActiveBusinessSession($active))throw new RuntimeException();$passed=true;
}catch(Throwable){$passed=false;}
finally{
    if($auth!==null)foreach([$pending,$active]as$reference)if(is_string($reference))try{if(!$auth->revoke($reference))$passed=false;}catch(Throwable){$passed=false;}
}
if($passed)echo "PASS senha e MFA validados; sessao de teste revogada; sem permissoes de negocio.\n";
else{fwrite(STDERR,"Teste de login nao concluido; nenhuma permissao de negocio concedida. Nao repita mais de cinco vezes em 15 minutos.\n");exit(1);}
