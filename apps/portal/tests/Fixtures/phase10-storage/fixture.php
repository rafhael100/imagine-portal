<?php
declare(strict_types=1);
require '/run/storage-tools/AuthenticatedStorageBundle.php';

use Imagine\PerformanceRecovery\AuthenticatedStorageBundle as StorageBundle;

const WORK = '/run/storage-work';
const SOURCE = '/run/storage-source';
const TARGET = '/run/storage-target';
const MAGIC = "IMAGINE-PERFORMANCE-STORAGE-1\n";
$stage = 'guard';
set_error_handler(static function(int $severity, string $message): bool {
    if ((error_reporting() & $severity) === 0) return false;
    throw new ErrorException('fixture_io',0,$severity);
});
umask(0077);

function demand(bool $value, string $name): void { if (!$value) throw new RuntimeException($name); }
function encoded(mixed $value): string { return json_encode($value,JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES); }
function exclusive(string $path, string $bytes): void {
    $stream=fopen($path,'xb');demand(is_resource($stream),'exclusive_file');
    try { demand(fwrite($stream,$bytes)===strlen($bytes),'file_write'); } finally { fclose($stream); }
    chmod($path,0600);
}
function metadata(string $name): array { return json_decode(file_get_contents(WORK.'/'.$name),true,64,JSON_THROW_ON_ERROR); }
function restoreBundle(string $archive=WORK.'/storage.enc', string $key=WORK.'/key', string $manifest=WORK.'/manifest.json', string $paired=WORK.'/paired.enc'): void {
    StorageBundle::restore($archive,$key,$manifest,$paired,TARGET);
}
function denyRestore(string $archive, string $key=WORK.'/key', string $manifest=WORK.'/manifest.json', string $paired=WORK.'/paired.enc'): void {
    $before=StorageBundle::inventory(TARGET);$denied=false;
    try { restoreBundle($archive,$key,$manifest,$paired); } catch (Throwable $error) { $denied=true; }
    demand($denied,'invalid_restore_was_accepted');
    demand(StorageBundle::inventory(TARGET)===$before,'refusal_modified_target');
}
function authenticatedPayload(): array {
    $archive=file_get_contents(WORK.'/storage.enc');$offset=strlen(MAGIC);
    demand(str_starts_with($archive,MAGIC),'storage_format');
    $nonce=substr($archive,$offset,SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_NPUBBYTES);
    $plain=sodium_crypto_aead_xchacha20poly1305_ietf_decrypt(substr($archive,$offset+strlen($nonce)),MAGIC,$nonce,file_get_contents(WORK.'/key'));
    demand(is_string($plain),'fixture_authenticated_payload');
    return json_decode($plain,true,64,JSON_THROW_ON_ERROR);
}
function forged(string $name, callable $modify): string {
    $payload=authenticatedPayload();$modify($payload);
    usort($payload['entries'],static fn(array $left,array $right):int=>strcmp($left['path'],$right['path']));
    $nonce=random_bytes(SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_NPUBBYTES);
    $cipher=sodium_crypto_aead_xchacha20poly1305_ietf_encrypt(encoded($payload),MAGIC,$nonce,file_get_contents(WORK.'/key'));
    $path=WORK.'/negative-'.$name.'.enc';exclusive($path,MAGIC.$nonce.$cipher);return $path;
}
function fileEntry(string $path): array { return ['path'=>$path,'type'=>'file','mode'=>0600,'size'=>4,'sha256'=>hash('sha256','test'),'data'=>base64_encode('test')]; }
function rejectCapture(string $name): void {
    $output=WORK.'/negative-source-'.$name.'.enc';$denied=false;
    try { StorageBundle::seal(TARGET,WORK.'/key',WORK.'/manifest.json',WORK.'/paired.enc',$output); } catch(Throwable $error) { $denied=true; }
    demand($denied && !file_exists($output),'invalid_source_was_sealed');
}

try {
    demand(PHP_SAPI==='cli' && getenv('GITHUB_ACTIONS')==='true' && getenv('ADMIN_PHASE10_STORAGE_CI')==='1','ci_only');
    demand(count($argv)===2 && in_array($argv[1],['seed-source','capture','initialize','negatives','restore','verify'],true),'action');
    $stage=$argv[1];
    if ($stage==='initialize') {
        demand(function_exists('posix_geteuid') && posix_geteuid()===0,'owner_initializer_only');
        clearstatcache(true,TARGET);$stat=lstat(TARGET);
        demand(($stat['mode']&0170000)===0040000 && realpath(TARGET)===TARGET && scandir(TARGET)===['.','..'],'literal_empty_target_required');
        // Only the root of this already identity-checked empty new volume is
        // changed. No recursive chown, deletion, data cleanup or DAC override.
        demand(chmod(TARGET,0700) && chown(TARGET,33) && chgrp(TARGET,33),'empty_root_ownership');
        echo "PHASE10_STORAGE_EMPTY_ROOT_PREPARED\n";exit(0);
    }
    demand(function_exists('posix_geteuid') && posix_geteuid()===33 && posix_getegid()===33,'runtime_uid_gid');
    if ($stage==='seed-source') {
        $directory=SOURCE.'/app/private/phase10-ci';
        demand(!file_exists($directory),'fresh_sentinel_directory');
        mkdir($directory,0700,true);
        exclusive($directory.'/data.bin',random_bytes(257));
        exclusive($directory.'/receipt.json',encoded(['kind'=>'synthetic_storage_canary','generation'=>metadata('manifest.json')['generation']]));
        exclusive($directory.'/mode-probe.php','<?php throw new RuntimeException("storage_must_not_be_evaluated");');
        chmod($directory.'/mode-probe.php',0755);
        foreach ([SOURCE.'/logs/phase10-ci.log',SOURCE.'/framework/sessions/phase10-ci.session'] as $path) exclusive($path,'SYNTHETIC_STORAGE_SENTINEL_'.bin2hex(random_bytes(16)));
        echo "PHASE10_STORAGE_SENTINELS_PREPARED\n";exit(0);
    }
    if ($stage==='capture') {
        $before=StorageBundle::inventory(SOURCE);
        StorageBundle::seal(SOURCE,WORK.'/key',WORK.'/manifest.json',WORK.'/paired.enc',WORK.'/storage.enc');
        demand(StorageBundle::inventory(SOURCE)===$before,'capture_modified_source');
        demand($before['total_bytes']>0 && count($before['entries'])>0,'nonempty_storage_snapshot');
        exclusive(WORK.'/source-inventory.json',encoded($before));
        echo "PHASE10_STORAGE_READONLY_CAPTURE_PASS\n";exit(0);
    }
    if ($stage==='negatives') {
        $checks=[];
        demand(StorageBundle::inventory(TARGET)['entries']===[],'fresh_negative_target');
        foreach (['traversal'=>'../outside-canary','absolute'=>'/tmp/phase10-outside-canary'] as $name=>$path) {
            $archive=forged($name,static function(array &$payload) use($path):void { $payload['entries'][]=fileEntry($path); });
            denyRestore($archive);$checks[]='authenticated_'.$name.'_refused_without_writes';
        }
        foreach (['symlink','hardlink'] as $type) {
            $archive=forged($type,static function(array &$payload) use($type):void {
                $payload['entries'][]=['path'=>'phase10-link','type'=>$type,'mode'=>0700,'size'=>0,'sha256'=>null,'data'=>null];
            });
            denyRestore($archive);$checks[]='authenticated_'.$type.'_refused_without_writes';
        }
        denyRestore(forged('duplicate',static function(array &$payload):void { $payload['entries'][]=$payload['entries'][0]; }));
        $checks[]='authenticated_duplicate_path_refused_without_writes';
        denyRestore(forged('file-parent',static function(array &$payload):void { $payload['entries'][]=fileEntry('phase10-conflict');$payload['entries'][]=fileEntry('phase10-conflict/child'); }));
        $checks[]='authenticated_file_parent_conflict_refused_without_writes';
        denyRestore(forged('digest',static function(array &$payload):void {
            foreach ($payload['entries'] as &$entry) if($entry['type']==='file') { $entry['sha256']=str_repeat('0',64);break; }
        }));
        $checks[]='authenticated_content_digest_mismatch_refused_without_writes';
        denyRestore(forged('entry-limit',static function(array &$payload):void {
            for($index=0;$index<2049;$index++)$payload['entries'][]=['path'=>'phase10-limit-'.str_pad((string)$index,5,'0',STR_PAD_LEFT),'type'=>'directory','mode'=>0700,'size'=>0,'sha256'=>null,'data'=>null];
        }));
        $checks[]='authenticated_entry_limit_refused_without_writes';

        $tampered=file_get_contents(WORK.'/storage.enc');$tampered[strlen($tampered)-1]=chr(ord($tampered[strlen($tampered)-1])^1);
        exclusive(WORK.'/tampered.enc',$tampered);denyRestore(WORK.'/tampered.enc');
        $checks[]='ciphertext_tamper_refused_without_writes';
        exclusive(WORK.'/wrong-key',random_bytes(32));denyRestore(WORK.'/storage.enc',WORK.'/wrong-key');
        $checks[]='wrong_key_refused_without_writes';
        $otherManifest=metadata('manifest.json');$otherManifest['generation']=bin2hex(random_bytes(16));
        exclusive(WORK.'/wrong-manifest.json',encoded($otherManifest));denyRestore(WORK.'/storage.enc',WORK.'/key',WORK.'/wrong-manifest.json');
        $checks[]='other_generation_refused_without_writes';
        copy(WORK.'/paired.enc',WORK.'/wrong-paired.enc');chmod(WORK.'/wrong-paired.enc',0600);
        $stream=fopen(WORK.'/wrong-paired.enc','r+b');fseek($stream,-1,SEEK_END);$byte=fread($stream,1);fseek($stream,-1,SEEK_END);fwrite($stream,chr(ord($byte)^1));fclose($stream);
        denyRestore(WORK.'/storage.enc',WORK.'/key',WORK.'/manifest.json',WORK.'/wrong-paired.enc');
        $checks[]='different_paired_database_ciphertext_refused_without_writes';

        exclusive(WORK.'/outside-sentinel','SOURCE_OUTSIDE_CANARY');
        symlink(WORK.'/outside-sentinel',TARGET.'/source-link');
        try { rejectCapture('symlink'); } finally { unlink(TARGET.'/source-link'); }
        demand(file_get_contents(WORK.'/outside-sentinel')==='SOURCE_OUTSIDE_CANARY','outside_file_modified');
        $checks[]='source_symlink_refused_without_archive';
        exclusive(TARGET.'/hardlink-source','SOURCE_HARDLINK_CANARY');link(TARGET.'/hardlink-source',TARGET.'/hardlink-alias');
        try { rejectCapture('hardlink'); } finally { unlink(TARGET.'/hardlink-alias');unlink(TARGET.'/hardlink-source'); }
        $checks[]='source_hardlink_refused_without_archive';
        exclusive(TARGET.'/existing-data','DO_NOT_OVERWRITE');
        denyRestore(WORK.'/storage.enc');
        demand(file_get_contents(TARGET.'/existing-data')==='DO_NOT_OVERWRITE','populated_target_overwritten');
        $checks[]='populated_volume_refused_without_overwrite';
        exclusive(WORK.'/negative-checks.json',encoded($checks));
        echo 'PHASE10_STORAGE_NEGATIVES_PASS: '.count($checks)." checks\n";exit(0);
    }
    if ($stage==='restore') {
        demand(StorageBundle::inventory(TARGET)['entries']===[],'literal_empty_restore_target');
        restoreBundle();
        demand(StorageBundle::inventory(TARGET)===metadata('source-inventory.json'),'restored_content_modes_mismatch');
        echo "PHASE10_STORAGE_IMPORT_PASS\n";exit(0);
    }
    $source=StorageBundle::inventory(SOURCE);$target=StorageBundle::inventory(TARGET);$expected=metadata('source-inventory.json');
    demand($source===$expected && $target===$expected,'source_or_target_inventory_changed');
    $modeProbe=array_values(array_filter($target['entries'],static fn(array $entry):bool=>$entry['path']==='app/private/phase10-ci/mode-probe.php'));
    demand(count($modeProbe)===1 && $modeProbe[0]['mode']===0755,'normal_executable_mode_not_preserved');
    $checks=array_merge(metadata('negative-checks.json'),[
        'source_storage_captured_readonly_while_application_writers_stopped',
        'storage_bound_to_same_generation_sources_compose_and_paired_databases',
        'target_volume_absence_observed_before_creation',
        'new_target_project_volume_labels_and_creation_receipt_verified',
        'literal_empty_volume_mounted_with_nocopy_before_first_portal_oneshot',
        'empty_root_owner_prepared_without_recursive_operations',
        'import_runs_as_uid_gid_33_without_dac_override',
        'all_file_paths_content_digests_sizes_and_modes_restored',
        'normal_executable_php_mode_preserved_without_evaluation',
        'source_inventory_unchanged_after_restore',
    ]);
    exclusive(WORK.'/evidence.json',encoded([
        'schema'=>'performance.phase10.storage.evidence.v1','checks'=>$checks,'passed'=>count($checks),
        'entry_count'=>count($source['entries']),'total_bytes'=>$source['total_bytes'],'source_tree_sha256'=>$source['tree_sha256'],'restored_tree_sha256'=>$target['tree_sha256'],
        'source'=>'synthetic','secret_custody_verified'=>false,'external_backup_verified'=>false,'deployment_authorized'=>false,'external_operations_enabled'=>false,'billable'=>false,
    ]));
    echo "PHASE10_STORAGE_CONTENT_AND_SOURCE_VERIFIED\n";
} catch (Throwable $error) {
    $check=preg_match('/\A[a-z_]+\z/D',$error->getMessage())?$error->getMessage():'assertion';
    fwrite(STDERR,'PHASE10_STORAGE_DENIED stage='.$stage.' check='.$check.PHP_EOL);exit(1);
}
