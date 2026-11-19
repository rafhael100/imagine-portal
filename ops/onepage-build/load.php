<?php
declare(strict_types=1);
require_once __DIR__.'/../application/vendor/autoload.php';
foreach(['Financial','PdoFinancialPort','Consumer','ServiceAuthorityPort','Worker'] as $name)require_once __DIR__.'/'.$name.'.php';
// No provider registration, connection, DDL or invocation occurs here.
