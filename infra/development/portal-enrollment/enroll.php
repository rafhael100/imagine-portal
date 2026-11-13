<?php
declare(strict_types=1);
// Execute only while the operator has control of the private TTY. Never redirect/record.
require '/var/www/html/vendor/autoload.php';
require __DIR__.'/InitialAccount.php';
$terminalState=null;
$failure='Pré-condições do console não atendidas.';
$wipe=static function():void{fwrite(STDOUT,"\033[2J\033[3J\033[H");};
register_shutdown_function(static function()use(&$terminalState,$wipe):void{
    if(is_string($terminalState))system('stty '.escapeshellarg($terminalState).' 2>/dev/null');
    $wipe();
});
try{
    if($argc!==1||PHP_SAPI!=='cli'||!stream_isatty(STDIN)||!stream_isatty(STDOUT)
        ||getenv('APP_ENV')!=='staging'||getenv('PORTAL_INITIAL_ACCOUNT')!=='PREPARE_INVITED_ONLY')
        throw new RuntimeException();
    $read=static function(string $prompt,bool $hidden=false):string{
        fwrite(STDOUT,$prompt);
        if($hidden){system('stty -echo 2>/dev/null',$terminalExit);if($terminalExit!==0)throw new RuntimeException();}
        try{$value=fgets(STDIN,1100);}finally{if($hidden){system('stty echo 2>/dev/null');fwrite(STDOUT,"\n");}}
        if(!is_string($value)||!str_ends_with($value,"\n"))throw new RuntimeException();
        return rtrim($value,"\r\n");
    };
    $terminalState=trim((string)shell_exec('stty -g 2>/dev/null'));
    if($terminalState==='')throw new RuntimeException();
    $config=\App\Infrastructure\Identity\PortalBrowserSecretFile::load('/run/secrets/runtime.json','https://portal-dev.imaginecomunica.com.br');
    if($config->value('host')!=='127.0.0.1'||$config->value('port')!==5432||$config->value('database')!=='portal_identity_dev')throw new RuntimeException();
    $path='/run/secrets/database-admin';$s=lstat($path);
    if(!is_array($s)||($s['mode']&0170000)!==0100000||($s['mode']&0077)!==0||$s['uid']!==posix_geteuid()||$s['nlink']!==1||$s['size']!==64)throw new RuntimeException();
    $admin=file_get_contents($path);
    if(!is_string($admin)||preg_match('/\\A[a-f0-9]{64}\\z/D',$admin)!==1)throw new RuntimeException();
    $failure='Dados da conta inválidos.';
    $email=$read('E-mail da primeira conta: ');
    $name=$read('Nome da organização de desenvolvimento: ');
    if(strlen(trim($email))>254||filter_var(trim($email),FILTER_VALIDATE_EMAIL)===false||trim($name)===''||strlen(trim($name))>160||preg_match('//u',$name)!==1||preg_match('/[\x00-\x1f\x7f]/',$name))throw new RuntimeException();
    $failure='As senhas não coincidem ou não atendem à política de senha.';
    $password=$read('Senha (mínimo 12 caracteres): ',true);
    $confirmation=$read('Repita a senha: ',true);
    if(!hash_equals($password,$confirmation)||\App\Domain\Identity\Security\PortalPasswordCredential::makeHash($password)===null)throw new RuntimeException();
    $alphabet='ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';$secret='';
    for($i=0;$i<32;$i++)$secret.=$alphabet[random_int(0,31)];
    fwrite(STDOUT,"Cadastre manualmente no autenticador: Imagine Portal (SHA1, 6 dígitos, 30 segundos)\nChave: ".$secret."\n");
    $failure='Confirmação PREPARAR não recebida.';
    if($read('A conta ficará pendente de verificação de e-mail, sem login. Digite PREPARAR para confirmar: ')!=='PREPARAR')throw new RuntimeException();
    $failure='Código do autenticador inválido ou expirado. Confira a chave e o horário automático do aparelho.';
    $code=$read('Código atual do autenticador (aguarde um novo código antes de digitar): ',true);
    if(\App\Domain\Identity\Security\PortalTotpVerifier::matchCounter($secret,$code,time())===null)throw new RuntimeException();
    $failure='Conexão com o banco de desenvolvimento não concluída.';
    $db=new PDO("pgsql:host=127.0.0.1;port=5432;dbname=portal_identity_dev;connect_timeout=3;sslmode=disable;options='--statement_timeout=5000 --lock_timeout=2000'",
        'postgres',$admin,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_EMULATE_PREPARES=>false,PDO::ATTR_PERSISTENT=>false]);
    $failure='Preparação transacional recusada pelo banco de desenvolvimento.';
    $result=\Imagine\Development\InitialAccount::prepare($db,$email,$name,$password,$secret,$code,base64_decode($config->value('totp_key'),true),$config->value('proof_key'));
    unset($admin,$password,$confirmation,$secret,$code);
    $wipe();
    fwrite(STDOUT,"Conta preparada. E-mail pendente; login e recursos de negócio bloqueados.\nOrganização: ".$result['organization']."\n");
    // Shutdown wipes sensitive screen history; emit final non-sensitive receipt only after that.
    register_shutdown_function(static function()use($result):void{
        fwrite(STDOUT,"PASS conta inicial preparada; verificação de e-mail pendente; organização ".$result['organization']."\n");
    });
}catch(Throwable){
    $wipe();register_shutdown_function(static function()use($failure):void{fwrite(STDERR,"Cadastro inicial não concluído. Nenhum acesso foi liberado.\n".$failure."\n");});
    exit(1);
}
