<?php
declare(strict_types=1);
// PHPUnit has initialized its configuration before loading this bootstrap.
// Retire only the already-finished maintenance application, before the test app/hook lifecycle starts.
if (!(PHPUnit\TextUI\Configuration\Registry::get() instanceof PHPUnit\TextUI\Configuration\Configuration)) {
    throw new RuntimeException('RUNNER_BOOTSTRAP_REFUSED');
}
$maintenance=Illuminate\Container\Container::getInstance();
if (!$maintenance instanceof Illuminate\Foundation\Application)throw new RuntimeException('RUNNER_BOOTSTRAP_REFUSED');
$maintenance->flush();
Illuminate\Support\Facades\Facade::clearResolvedInstances();
Illuminate\Support\Facades\Facade::setFacadeApplication(null);
Illuminate\Foundation\Bootstrap\HandleExceptions::flushState();
unset($maintenance);
