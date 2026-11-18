<?php
declare(strict_types=1);
// Deliberately relative to apps/portal in the reviewed checkout. No alternate vendor or class copies.
(function():void {
    $app=realpath(__DIR__.'/../..');
    $pins=json_decode(file_get_contents(__DIR__.'/pins.json'),true,512,JSON_THROW_ON_ERROR);
    $need=static function(bool $ok):void{if(!$ok)throw new RuntimeException('HOOK_LOAD_REFUSED');};
    $blob=static function(string $p):string{$b=file_get_contents($p);return sha1('blob '.strlen($b)."\0".$b);};
    $need(is_string($app)&&$blob($app.'/composer.lock')===$pins['lock_blob']);
    $lock=json_decode(file_get_contents($app.'/composer.lock'),true,512,JSON_THROW_ON_ERROR);
    $installed=json_decode(file_get_contents($app.'/vendor/composer/installed.json'),true,512,JSON_THROW_ON_ERROR);
    $index=static function(array $packages):array{$r=[];foreach($packages as $p)$r[$p['name']]=[$p['version'],$p['source']['reference']??null,$p['dist']['reference']??null];ksort($r);return $r;};
    $need($index(array_merge($lock['packages'],$lock['packages-dev']))===$index($installed['packages']));
    foreach($pins['canonical'] as $path=>$pin)$need($blob($app.'/'.$path)===$pin['blob']);
    require_once $app.'/vendor/autoload.php';
    // Canonical lab class is not PSR-4; product classes are loaded ONLY by Composer.
    if(!class_exists('NativeBriefingFixture\\Fixture',false))require_once $app.'/labs/briefing-teardown-ci/Fixture.php';
    foreach($pins['canonical'] as $path=>$pin){
        $need(class_exists($pin['class'])||interface_exists($pin['class']));
        $need(realpath((new ReflectionClass($pin['class']))->getFileName())===realpath($app.'/'.$path));
    }
    foreach(['ObservedIds','Observer','Channel','FrontController','Hook','Caller'] as $file)require_once __DIR__.'/'.$file.'.php';
})();
