<?php
declare(strict_types=1);
namespace App\Domain\PerformanceAdministration;
use Imagine\Performance\Administration\AdministrativeLabEngine as Engine;
use RuntimeException;
/** Local code adapter, not a network integration and not an S2S credential. */
final class PinnedLaboratoryTransport implements PerformanceAdminTransport
{
    public function __construct()
    {
        if(!PrivateDemonstrationPolicy::allowed())throw new RuntimeException('Laboratory unavailable');
        $root=realpath((string)config('performance_admin.engine_root'));
        if(!$root)throw new RuntimeException('Pinned engine unavailable');
        foreach(['src/Domain/Administration/AdministrativeLabEngine.php'=>'engine_hash',
            'src/Domain/Commercial/MonthlyManagementFeeFloorV7Candidate.php'=>'finance_hash'] as $file=>$key){
            $path=$root.'/'.$file;
            if(!is_file($path)||!hash_equals(config('performance_admin.'.$key),hash_file('sha256',$path)))throw new RuntimeException('Pinned engine integrity failed');
        }
        require_once $root.'/src/Domain/Administration/AdministrativeLabEngine.php';
    }
    public function fixture(string $tenant,int $now):array{return Engine::fixture($tenant,$now);}
    public function command(array $state,array $context,array $command,int $now):array{return Engine::command($state,$context,$command,$now);}
    public function allows(string $role,string $action):bool{return Engine::allows($role,$action);}
    public function finance(array $state):array{return Engine::finance($state);}
}
