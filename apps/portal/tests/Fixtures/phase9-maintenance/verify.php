<?php
declare(strict_types=1);

$stage = 'guard';
set_error_handler(static function(int $severity, string $message): never { throw new ErrorException('fixture_io', 0, $severity); });
const ROOT = '/var/www/html';
const HELPER = ROOT.'/tools/performance-maintenance/clear-derived-cache.php';
const EXPECTED = ROOT.'/storage/private/phase9-expected.json';
const EXECUTED = ROOT.'/storage/private/phase9-cache-was-executed';

function requireTrue(bool $value, string $check): void { if (!$value) throw new RuntimeException($check); }
function save(string $path, string $bytes): void {
    if (!is_dir(dirname($path))) mkdir(dirname($path), 0700, true);
    file_put_contents($path, $bytes);
}
function cacheFixtures(): array {
    $active = '<?php file_put_contents('.var_export(EXECUTED,true).', "unexpected"); throw new RuntimeException("obsolete_provider");';
    $files = [];
    foreach (['packages.php','services.php','config.php','events.php','routes.php','routes-v7.php','routes-legacy.php'] as $name) $files[ROOT.'/bootstrap/cache/'.$name] = $active;
    $files[ROOT.'/bootstrap/cache/config.php'] = '<?php THIS IS DELIBERATELY INVALID STALE PHP';
    $files[ROOT.'/storage/framework/views/'.str_repeat('a',32).'.php'] = $active;
    $files[ROOT.'/storage/framework/views/legacy-compiled.php'] = $active;
    return $files;
}
function freshCaches(): array {
    $hashes = [];
    foreach (cacheFixtures() as $path=>$bytes) { save($path,$bytes);$hashes[$path]=hash('sha256',$bytes); }
    return $hashes;
}
function identical(array $hashes, string $check): void {
    foreach ($hashes as $path=>$hash) {
        clearstatcache(true,$path);
        requireTrue(is_file($path) && hash_equals($hash,hash_file('sha256',$path)),$check);
    }
    requireTrue(!file_exists(EXECUTED),'stale_php_was_evaluated');
}
function helper(array $arguments, string $script=HELPER): array {
    $pipes=[];
    $process=proc_open(array_merge([PHP_BINARY,$script],$arguments),[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes);
    requireTrue(is_resource($process),'helper_process');
    fclose($pipes[0]);$out=stream_get_contents($pipes[1]);$err=stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);
    return ['status'=>proc_close($process),'out'=>$out,'err'=>$err];
}
function accepted(array $arguments): array {
    $result=helper($arguments);
    requireTrue($result['status']===0 && $result['err']==='','helper_expected_success');
    $report=json_decode($result['out'],true,8,JSON_THROW_ON_ERROR);
    requireTrue(($report['schema']??null)==='performance.derived-cache-maintenance.v1','helper_output_schema');
    return $report;
}
function denied(array $cache, array $sentinels, array $arguments=['--apply'], string $script=HELPER): void {
    $result=helper($arguments,$script);
    requireTrue($result['status']!==0 && $result['out']==='' && preg_match('/\APERFORMANCE_DERIVED_CACHE_DENIED stage=(arguments|preflight)\n\z/D',$result['err'])===1,'helper_expected_refusal');
    identical($cache,'preflight_deleted_candidate');
    identical($sentinels,'refusal_changed_persistent_data');
}

try {
    if (PHP_SAPI!=='cli' || getenv('GITHUB_ACTIONS')!=='true' || getenv('ADMIN_PHASE9_MAINTENANCE_CI')!=='1'
        || count($argv)!==2 || !in_array($argv[1],['seed','verify'],true)) throw new RuntimeException('ci_only');
    if ($argv[1]==='seed') {
        $stage='seed';
        requireTrue(!file_exists(EXPECTED),'fresh_volumes_required');
        $sentinels=[];
        foreach ([
            '/storage/private/keep.data','/storage/app/private/keep.data','/storage/logs/keep.log',
            '/storage/framework/sessions/keep.session','/storage/framework/cache/data/keep.cache',
            '/storage/framework/views/keep.txt','/storage/framework/views/nested/compiled.php',
            '/bootstrap/cache/keep.json','/bootstrap/cache/custom.php','/bootstrap/cache/nested/routes-v7.php',
        ] as $relative) {
            $path=ROOT.$relative;$bytes="PHASE9_PERSISTENT_SENTINEL\0".bin2hex(random_bytes(16));
            save($path,$bytes);$sentinels[$path]=hash('sha256',$bytes);
        }
        save(EXPECTED,json_encode(['sentinels'=>$sentinels,'caches'=>freshCaches()],JSON_THROW_ON_ERROR));
        echo "PHASE9_FIXTURE_SEEDED\n";exit(0);
    }
    $stage='verify';
    $expected=json_decode(file_get_contents(EXPECTED),true,16,JSON_THROW_ON_ERROR);
    $sentinels=$expected['sentinels'];$cache=$expected['caches'];$checks=[];
    identical($sentinels,'persistent_volume_sentinels_missing');identical($cache,'persisted_stale_cache_missing');
    $checks[]='second_container_observes_same_persistent_volumes';
    // Laravel cache-path environment variables cannot redirect this helper.
    putenv('APP_CONFIG_CACHE='.ROOT.'/storage/private/keep.data');
    putenv('APP_ROUTES_CACHE='.ROOT.'/storage/framework/sessions/keep.session');
    $preview=accepted(['--check']);
    requireTrue($preview['mode']==='--check' && $preview['removed_files']===0 && $preview['eligible_files']>=count($cache),'check_must_not_remove');
    identical($cache,'check_changed_cache');identical($sentinels,'check_changed_data');
    $checks[]='check_only_reports_without_removal';
    $applied=accepted(['--apply']);
    requireTrue($applied['removed_files']===$preview['eligible_files'],'allowlisted_removal_count');
    foreach ($cache as $path=>$hash) requireTrue(!file_exists($path),'allowlisted_cache_survived');
    identical($sentinels,'apply_changed_persistent_data');
    $checks[]='only_allowlisted_immediate_php_caches_removed';
    $checks[]='data_logs_sessions_application_cache_and_subdirectories_preserved';
    $checks[]='invalid_stale_php_and_providers_never_bootstrapped';
    $checks[]='environment_path_overrides_ignored';
    $again=accepted(['--apply']);
    requireTrue($again['eligible_files']===0 && $again['removed_files']===0,'second_apply_not_idempotent');
    $checks[]='second_apply_is_idempotent';

    $cache=freshCaches();
    foreach ([ROOT.'/bootstrap/cache/routes-symlink.php',ROOT.'/storage/framework/views/symlink.php',ROOT.'/bootstrap/cache/unselected-link.txt'] as $link) {
        symlink(ROOT.'/storage/private/keep.data',$link);
        try { denied($cache,$sentinels); } finally { unlink($link); }
    }
    $checks[]='selected_and_unselected_symlinks_refused';
    $checks[]='invalid_second_cache_root_prevents_first_root_deletion';

    // A linked root and a linked ancestor must both refuse before mutation.
    foreach (['views','framework'] as $case) {
        $path=$case==='views'?ROOT.'/storage/framework/views':ROOT.'/storage/framework';
        $moved=$path.'-phase9-real';
        rename($path,$moved);symlink(basename($moved),$path);
        try { denied($cache,$sentinels); } finally { unlink($path);rename($moved,$path); }
    }
    $checks[]='symlinked_cache_root_refused';
    $checks[]='symlinked_ancestor_refused';

    $config=ROOT.'/bootstrap/cache/config.php';$withoutConfig=$cache;unset($withoutConfig[$config]);
    unlink($config);mkdir($config,0700);
    try { denied($withoutConfig,$sentinels);requireTrue(is_dir($config),'nonregular_candidate_changed'); }
    finally { rmdir($config);save($config,cacheFixtures()[$config]); }
    $checks[]='nonregular_allowlisted_candidate_refused';

    denied($cache,$sentinels,['--apply',ROOT.'/storage/private']);
    $checks[]='caller_supplied_root_refused';
    $alias=ROOT.'/storage/private/phase9-helper-alias.php';symlink(HELPER,$alias);
    try { denied($cache,$sentinels,['--apply'],$alias); } finally { unlink($alias); }
    $checks[]='symlinked_script_alias_refused';
    $final=accepted(['--apply']);
    requireTrue($final['removed_files']===count($cache),'final_cleanup_count');
    identical($sentinels,'final_sentinel_changed');
    requireTrue(json_decode(file_get_contents(EXPECTED),true,16,JSON_THROW_ON_ERROR)===$expected,'fixture_metadata_changed');
    $checks[]='persistent_sentinels_and_metadata_remain_unchanged';
    echo json_encode(['schema'=>'performance.phase9.maintenance.evidence.v1','checks'=>$checks,'passed'=>count($checks),
        'source'=>'synthetic','runtime_started'=>false,'same_image_fresh_containers'=>true,'private_disposable_volumes'=>true,
        'deployment_authorized'=>false,'external_operations_enabled'=>false,'billable'=>false],JSON_THROW_ON_ERROR|JSON_PRETTY_PRINT).PHP_EOL;
} catch (Throwable $error) {
    $check=preg_match('/\A[a-z_]+\z/D',$error->getMessage())?$error->getMessage():'assertion';
    fwrite(STDERR,'PHASE9_MAINTENANCE_DENIED stage='.$stage.' check='.$check.PHP_EOL);exit(1);
}
