<?php
declare(strict_types=1);
namespace PrivateOnepageOneShot;
use Illuminate\Database\Connection;
/** Supplied by an authenticated operator bootstrap, not JSON/HTTP or an environment file.
 * Receipt attests dedicated disposable instance, reviewed catalog/ACL, release bytes,
 * unexpired one-run authorization and exclusively synthetic preexisting data.
 */
interface QualifiedLabContext {
 public function currentReceipt():?array;
 /** Already connected: no lazy connection or DSN selected from receipt/event. */
 public function connection(string $slot):Connection;
 public function effectiveConfiguration(string $slot):array;
}
