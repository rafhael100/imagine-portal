<?php
declare(strict_types=1);
// Pipe-only administrative protocol. Never accepts secrets on argv or env.
try {
    require '/var/www/html/vendor/autoload.php';
    require __DIR__.'/PgLedger.php';
    if($argc!==1||PHP_SAPI!=='cli'||stream_isatty(STDIN)||getenv('APP_ENV')!=='staging'||getenv('PORTAL_EMAIL_LEDGER')!=='PRIVATE_INVITED_ONLY')throw new RuntimeException();
    $read=static function(string $path,int $size):string{
        $s=lstat($path);
        if(!is_array($s)||($s['mode']&0170000)!==0100000||($s['mode']&0077)!==0||$s['uid']!==posix_geteuid()||$s['nlink']!==1||$s['size']!==$size)throw new RuntimeException();
        $v=file_get_contents($path);if(!is_string($v)||strlen($v)!==$size)throw new RuntimeException();return $v;
    };
    $c=\App\Infrastructure\Identity\PortalBrowserSecretFile::load('/run/secrets/runtime.json','https://portal-dev.imaginecomunica.com.br');
    if($c->value('host')!=='127.0.0.1'||$c->value('port')!==5432||$c->value('database')!=='portal_identity_dev')throw new RuntimeException();
    $admin=$read('/run/secrets/database-admin',64);$key=$read('/run/secrets/email-proof-key',32);
    if(preg_match('/\A[a-f0-9]{64}\z/D',$admin)!==1||hash_equals($key,base64_decode($c->value('totp_key'),true)))throw new RuntimeException();
    $db=new PDO("pgsql:host=127.0.0.1;port=5432;dbname=portal_identity_dev;connect_timeout=3;sslmode=disable",'postgres',$admin,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_EMULATE_PREPARES=>false]);
    $ledger=new \Imagine\Development\PgLedger($db,$key,$c->value('proof_key'));
    $input=stream_get_contents(STDIN,4097);if(strlen($input)>4096)throw new RuntimeException();
    $r=json_decode($input,true,8,JSON_THROW_ON_ERROR);
    if(!is_array($r)||($r['recipient']??null)!=='rafhael@imaginecomunicacao.com.br')throw new RuntimeException();
    $action=$r['action']??null;
    $value=match($action){
        'install'=>(function()use($ledger){$ledger->install();return true;})(),
        'reserve'=>$ledger->reserve($r['recipient'],$r['token']??'',(int)($r['ttl']??0)),
        'accepted'=>$ledger->accepted($r['reference']??'',$r['recipient']),
        'revoke'=>(function()use($ledger,$r){$ledger->revoke($r['reference']??'');return true;})(),
        'consume'=>$ledger->consume($r['reference']??'',$r['recipient'],$r['token']??''),
        default=>throw new RuntimeException(),
    };
    echo json_encode(['ok'=>true,'value'=>$value],JSON_THROW_ON_ERROR)."\n";
}catch(Throwable){fwrite(STDOUT,"{\"ok\":false}\n");exit(1);}
