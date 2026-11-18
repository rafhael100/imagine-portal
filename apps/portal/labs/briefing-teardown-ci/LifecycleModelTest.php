<?php
declare(strict_types=1);
ini_set('display_errors','0');ini_set('log_errors','0');
require dirname(__DIR__,2).'/vendor/autoload.php';
final class LifecycleModelTest extends Illuminate\Foundation\Testing\TestCase {
 public static bool $method=false;
 public static bool $teardown=false;
 public static bool $flushed=false;
 public static bool $callback=false;
 public static bool $afterClass=false;
 public static bool $fail=false;
 public function createApplication() {
  $app=new Illuminate\Foundation\Application(__DIR__);
  $app->instance('config',new Illuminate\Config\Repository(['app'=>['env'=>'testing']]));
  $app->instance('env','testing');
  Illuminate\Support\Facades\Facade::setFacadeApplication($app);
  (new Illuminate\Foundation\Bootstrap\HandleExceptions())->bootstrap($app);
  $this->beforeApplicationDestroyed(function(){self::$callback=true;if(self::$fail)throw new RuntimeException('MODEL_FAILURE');});
  return $app;
 }
 public static function tearDownAfterClass():void {parent::tearDownAfterClass();self::$afterClass=true;}
 public function testSignal():void {self::$method=true;self::assertTrue(true);}
 protected function tearDown():void {self::$teardown=true;try{parent::tearDown();}finally{self::$flushed=$this->app===null;}}
}

if(realpath($_SERVER['SCRIPT_FILENAME']??'')!==__FILE__)return;
// Faithful memory-only model of the worker's already-booted maintenance application.
$outer=new Illuminate\Foundation\Application(__DIR__);
$outer->instance('config',new Illuminate\Config\Repository(['app'=>['env'=>'testing']]));
$outer->instance('env','testing');
Illuminate\Support\Facades\Facade::setFacadeApplication($outer);
(new Illuminate\Foundation\Bootstrap\HandleExceptions())->bootstrap($outer);
$mode=$argv[1]??'';
if($mode==='direct') {
 try{(new LifecycleModelTest('testSignal'))->runBare();exit(2);}
 catch(AssertionError|TypeError $e){
  $configuration=false;$handler=false;
  foreach($e->getTrace() as $frame){
   $configuration|=($frame['class']??'')==='PHPUnit\\TextUI\\Configuration\\Registry';
   $handler|=($frame['class']??'')==='Illuminate\\Foundation\\Bootstrap\\HandleExceptions';
  }
  if(!$configuration||!$handler||!LifecycleModelTest::$method||!LifecycleModelTest::$teardown||!LifecycleModelTest::$flushed||!LifecycleModelTest::$callback)exit(3);
  fwrite(STDOUT,"PASS MODEL_MANUAL_RUNBARE_TEARDOWN_REGISTRY_REFUSED\n");
 }
} elseif(in_array($mode,['runner','runner-fail'],true)) {
 LifecycleModelTest::$fail=$mode==='runner-fail';
 $code=(new PHPUnit\TextUI\Application())->run(['phpunit','--no-configuration','--no-output','--bootstrap',__DIR__.'/RunnerBootstrap.php','--do-not-cache-result','--fail-on-warning','--fail-on-risky','--fail-on-skipped','--fail-on-incomplete','--fail-on-empty-test-suite',__FILE__]);
 if(!LifecycleModelTest::$method||!LifecycleModelTest::$teardown||!LifecycleModelTest::$flushed||!LifecycleModelTest::$callback||!LifecycleModelTest::$afterClass)exit(4);
 if($mode==='runner' && $code!==0)exit(5);
 if($mode==='runner-fail' && $code===0)exit(6);
 fwrite(STDOUT,$mode==='runner'?"PASS MODEL_RUNNER_TEARDOWN_COMPLETE\n":"PASS MODEL_RUNNER_TEARDOWN_FAILURE_PROPAGATED\n");
} else exit(7);
