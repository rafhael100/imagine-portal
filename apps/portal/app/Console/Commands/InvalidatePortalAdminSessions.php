<?php
declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * F134: revoke all prior admin sessions, including pending password/MFA,
 * using a version increment. Never accepts a session token or password.
 *
 * Not an HTTP endpoint. Test-only CI or explicitly approved interactive
 * staging provisioning. Production is always excluded.
 */
final class InvalidatePortalAdminSessions extends Command
{
    protected $signature='portal-admin:invalidate-sessions
        {--email= : Existing administrator email}
        {--deactivate : Deactivate account in same transaction}';

    protected $description='Invalidate previous Portal administrator sessions and pending MFA challenges';

    public function handle():int
    {
        $ci=getenv('CI')==='true' &&
            app()->environment('testing') &&
            getenv('PORTAL_ADMIN_F134_PG_CI')==='1';
        $staging=app()->environment('staging') &&
            config('portal_admin.provision_enabled')===true &&
            function_exists('stream_isatty') &&
            stream_isatty(STDIN);

        if((!$ci&&!$staging) ||
           config('database.default')!=='pgsql' ||
           ($staging&&!$this->confirm(
               'Revogar todas as sessões administrativas dessa conta?',
               false
           ))) {
            $this->error('Revogação indisponível neste ambiente.');
            return self::FAILURE;
        }

        $email=strtolower(trim((string)$this->option('email')));
        if(strlen($email)>190 ||
           filter_var($email,FILTER_VALIDATE_EMAIL)===false) {
            $this->error('Dados de revogação inválidos.');
            return self::FAILURE;
        }

        try{
            $updated=DB::transaction(static function()use(
                $email
            ):int {
                $admin=DB::table('portal_admin_users')
                    ->where('email',$email)
                    ->lockForUpdate()
                    ->first(['id','session_version']);
                if($admin===null || !is_numeric($admin->session_version) ||
                   (int)$admin->session_version>=9223372036854775800)
                    return 0;

                $values=[
                    'session_version'=>DB::raw('session_version + 1'),
                    'updated_at'=>now(),
                ];
                if((bool)$this->option('deactivate'))
                    $values['is_active']=false;

                return DB::table('portal_admin_users')
                    ->where('id',$admin->id)
                    ->update($values);
            });
        }catch(Throwable){
            $this->error('Não foi possível concluir a revogação.');
            return self::FAILURE;
        }
        if($updated!==1){
            // Do not disclose account existence in command output.
            $this->error('Não foi possível concluir a revogação.');
            return self::FAILURE;
        }
        $this->info(
            'Versão de acesso atualizada. Sessões antigas e desafios de MFA serão recusados.'
        );
        return self::SUCCESS;
    }
}
