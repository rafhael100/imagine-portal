<?php
declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * F134/F135: revoke all prior sessions with a mandatory atomic audit,
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

        $deactivate=(bool)$this->option('deactivate');
        // No claim of real administrator identity: the operation is still
        // a CI fixture or a TTY-controlled staging command.
        $actorContext=$ci?'ci_synthetic_operator':'staging_tty_unverified';
        try{
            $updated=DB::transaction(static function()use(
                $email,$deactivate,$actorContext
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
                if($deactivate)
                    $values['is_active']=false;

                $version=(int)$admin->session_version;
                $affected=DB::table('portal_admin_users')
                    ->where('id',$admin->id)
                    ->where('session_version',$version)
                    ->update($values);
                if($affected!==1)return 0;

                // F135: mandatory append-only audit in the SAME transaction.
                // Any INSERT error undoes the preceding session rotation.
                $inserted=DB::table('portal_admin_security_events')->insert([
                    'event_ref'=>bin2hex(random_bytes(16)),
                    'admin_user_id'=>(int)$admin->id,
                    'event_code'=>$deactivate
                        ?'admin_deactivated':'sessions_revoked',
                    'version_before'=>$version,
                    'version_after'=>$version+1,
                    'actor_context'=>$actorContext,
                    'occurred_at'=>now(),
                ]);
                if($inserted!==true)
                    throw new \RuntimeException(
                        'F135: mandatory session revocation audit refused'
                    );
                return 1;
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
