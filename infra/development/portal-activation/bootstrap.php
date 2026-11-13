<?php
declare(strict_types=1);
require '/var/www/html/vendor/autoload.php';
require __DIR__.'/OperatorActivation.php';
$config=\App\Infrastructure\Identity\PortalBrowserSecretFile::load('/run/secrets/runtime.json','https://portal-dev.imaginecomunica.com.br');
if($config->value('host')!=='127.0.0.1'||$config->value('port')!==5432||$config->value('database')!=='portal_identity_dev')throw new RuntimeException();
$path='/run/secrets/database-admin';$s=lstat($path);
if(!is_array($s)||($s['mode']&0170000)!==0100000||($s['mode']&0077)!==0||$s['uid']!==posix_geteuid()||$s['nlink']!==1||$s['size']!==64)throw new RuntimeException();
$admin=file_get_contents($path);if(preg_match('/\A[a-f0-9]{64}\z/D',$admin)!==1)throw new RuntimeException();
$dsn='pgsql:host=127.0.0.1;port=5432;dbname=portal_identity_dev;connect_timeout=3;sslmode=disable';
$options=[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_EMULATE_PREPARES=>false,PDO::ATTR_PERSISTENT=>false];
$db=new PDO($dsn,'postgres',$admin,$options);unset($admin);
$activation=new \Imagine\Development\OperatorActivation($db,$config->value('proof_key'),base64_decode($config->value('totp_key'),true));
