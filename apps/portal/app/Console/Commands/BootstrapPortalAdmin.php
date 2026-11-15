<?php
declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\PortalAccess\Security\PortalAdminEnrollmentSecret;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Throwable;

/**
 * F133: one-time, server-side admin provisioning, never an HTTP enrollment
 * route. Test-only on CI or explicitly enabled staging with private TTY.
 *
 * Password and TOTP enrollment secret are never printed in CLI logs.
 */
final class BootstrapPortalAdmin extends Command
{
    protected $signature='portal-admin:bootstrap
        {--email= : Canonical new admin email}
        {--totp-file= : New private file outside the repository}
        {--activate : Explicitly activate the new account}';

    protected $description='Provision a Portal admin on an authorized server, never via a website';

    public function handle():int
    {
        $ci=getenv('CI')==='true' &&
            app()->environment('testing') &&
            getenv('PORTAL_ADMIN_F133_PG_CI')==='1';
        $staging=app()->environment('staging') &&
            config('portal_admin.provision_enabled')===true;
        if((!$ci&&!$staging) ||
           config('database.default')!=='pgsql' ||
           !is_string(config('app.key')) ||
           strlen((string)config('app.key'))<30){
            $this->error('Provisionamento indisponível neste ambiente.');
            return self::FAILURE;
        }

        $email=strtolower(trim((string)$this->option('email')));
        $path=(string)$this->option('totp-file');
        if(strlen($email)<6 || strlen($email)>190 ||
           filter_var($email,FILTER_VALIDATE_EMAIL)===false ||
           !str_starts_with($path,'/') ||
           str_contains($path,"\0") ||
           file_exists($path) || is_link($path)){
            $this->error('Dados de provisionamento inválidos.');
            return self::FAILURE;
        }

        // Refuse public/build/repository output and symlink ancestors.
        $parent=realpath(dirname($path));
        $repo=realpath(dirname(base_path(),2));
        if($parent===false ||
           $parent!==dirname($path) ||
           !is_dir($parent) ||
           !is_writable($parent) ||
           ($repo!==false &&
            ($parent===$repo||str_starts_with($parent,$repo.'/'))) ||
           ((fileperms($parent)&0077)!==0)){
            $this->error('É obrigatório usar uma pasta privada, modo 0700, fora do repositório.');
            return self::FAILURE;
        }

        if($ci){
            $password=(string)getenv('PORTAL_F133_TEST_PASSWORD');
        }else{
            if(!function_exists('stream_isatty')||
               !stream_isatty(STDIN)){
                $this->error('É necessário usar um terminal administrativo interativo.');
                return self::FAILURE;
            }
            $password=(string)$this->secret('Senha inicial forte (não exibida)');
        }
        if(strlen($password)<14 || strlen($password)>128 ||
           preg_match('/[a-z]/',$password)!==1 ||
           preg_match('/[A-Z]/',$password)!==1 ||
           preg_match('/[0-9]/',$password)!==1 ||
           preg_match('/[^a-zA-Z0-9]/',$password)!==1){
            $this->error('A senha não atende aos requisitos de segurança.');
            return self::FAILURE;
        }

        $secret=random_bytes(20);
        $enrollment=PortalAdminEnrollmentSecret::enrollmentText($email,$secret);
        $created=false;
        $oldUmask=umask(0077);
        try{
            DB::transaction(function()use(
                $email,$password,$secret,$path,$enrollment,&$created
            ):void {
                if(DB::table('portal_admin_users')
                    ->where('email',$email)->exists())
                    throw new \RuntimeException('duplicate-admin-account');

                DB::table('portal_admin_users')->insert([
                    'email'=>$email,
                    'password_hash'=>Hash::make($password),
                    'totp_secret_ciphertext'=>Crypt::encryptString(
                        bin2hex($secret)
                    ),
                    'is_active'=>(bool)$this->option('activate'),
                    'last_totp_counter'=>-1,
                    'created_at'=>now(),
                    'updated_at'=>now(),
                ]);

                // Exclusive create: never truncate an existing path. Secret
                // is written only to the supplied private file, not stdout.
                $file=@fopen($path,'x');
                if($file===false)
                    throw new \RuntimeException('private-secret-file-unavailable');
                $created=true;
                try{
                    if(!chmod($path,0600))
                        throw new \RuntimeException('secret-permissions');
                    $len=strlen($enrollment);
                    $written=0;
                    while($written<$len){
                        $n=fwrite($file,substr($enrollment,$written));
                        if($n===false||$n===0)
                            throw new \RuntimeException('secret-write');
                        $written+=$n;
                    }
                    if(!fflush($file))
                        throw new \RuntimeException('secret-flush');
                }finally{
                    fclose($file);
                }
            });
        }catch(Throwable){
            if($created)@unlink($path);
            $this->error('Não foi possível concluir o provisionamento.');
            return self::FAILURE;
        }finally{
            umask($oldUmask);
        }

        $this->info('Conta registrada; o segredo TOTP está exclusivamente no arquivo privado.');
        $this->warn(
            (bool)$this->option('activate')
                ?'Conta ativada por opção explícita; validar acesso apenas em homologação.'
                :'Conta permanece inativa até autorização.'
        );
        return self::SUCCESS;
    }
}
