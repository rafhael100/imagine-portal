<?php
declare(strict_types=1);
// Operator bootstrap first loads #986 worker/load.php and authenticates QualifiedLabContext.
// This callable is not an HTTP route and cannot be activated by environment variables.
require_once __DIR__.'/QualifiedLabContext.php';
require_once __DIR__.'/OneShot.php';
return static fn(\PrivateOnepageOneShot\QualifiedLabContext $context):array => (new \PrivateOnepageOneShot\OneShot($context))->run();
