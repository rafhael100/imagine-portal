<?php
declare(strict_types=1);
namespace App\Domain\PerformanceAdministration;
interface PerformanceAdminTransport
{
    public function fixture(string $tenant,int $now):array;
    public function command(array $state,array $context,array $command,int $now):array;
    public function allows(string $role,string $action):bool;
    public function finance(array $state):array;
}
