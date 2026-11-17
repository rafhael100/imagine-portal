<?php
declare(strict_types=1);
namespace App\Labs\OnepageBuyer;
use PDO;
use RuntimeException;

/** Read-only preflight. No schema/fixture operation is reachable on a mismatch. */
final class Guard
{
    public static function requirements(array $facts): void
    {
        if (($facts['os'] ?? null) !== 'Linux' || ($facts['proc'] ?? false) !== true
            || ($facts['locale'] ?? null) !== 'C' || ($facts['locale_env'] ?? null) !== 'C'
            || ($facts['sodium'] ?? false) !== true || ($facts['process'] ?? false) !== true
            || !in_array('pgsql', $facts['drivers'] ?? [], true)
            || !in_array('argon2id', $facts['password_algorithms'] ?? [], true))
            throw new RuntimeException('OFFLINE_REQUIREMENTS_REQUIRED');
    }

    public static function offline(): void
    {
        // PHP may initialize LC_CTYPE to C.UTF-8 even when the process environment is C.
        // Normalize only an explicitly opted-in C environment; otherwise refuse it below.
        if (getenv('LC_ALL') === 'C') setlocale(LC_ALL, 'C');
        self::requirements(['os'=>PHP_OS_FAMILY,'proc'=>is_dir('/proc/self'),
            'locale'=>setlocale(LC_ALL,0),'locale_env'=>getenv('LC_ALL'),
            'sodium'=>extension_loaded('sodium') && function_exists('sodium_crypto_secretbox'),
            'process'=>function_exists('proc_open'),'drivers'=>PDO::getAvailableDrivers(),
            'password_algorithms'=>password_algos()]);
    }

    public static function fixtures(?string $hash, ?string $sealed, string $key, string $secret, string $plain): void
    {
        if (!is_string($hash) || !is_string($sealed) || strlen($key)!==32
            || !\App\Domain\Identity\Security\PortalPasswordCredential::verify($plain,$hash)
            || \App\Domain\Identity\Security\PortalTotpSecretCipher::open($sealed,$key)!==$secret)
            throw new RuntimeException('FIXTURE_MATERIAL_INVALID');
    }

    /** Observations must come from two actual PDO connections, never Request claims. */
    public static function runtimeEvidence(array $row, array $observed, array $cluster, string $role, string $nonce): void
    {
        if (!in_array($role,['portal_browser_runtime','onepage_buyer_runtime'],true)
            || ($row['db']??null)!=='portal_onepage_buyer_ci' || ($row['db']??null)!==($cluster['db']??null)
            || ($row['actor']??null)!==$role || ($row['login']??null)!==$role
            || ($row['host']??null)!=='127.0.0.1' || (int)($row['port']??0)!==55439
            || (string)($row['started']??'')!==(string)($cluster['started']??'')
            || !in_array($row['unsafe']??null,[false,'f',0,'0'],true)
            || (int)($row['pid']??0)<1 || (int)($row['client_port']??0)<1
            || (int)($observed['pid']??0)!==(int)$row['pid']
            || ($observed['usename']??null)!==$role || ($observed['datname']??null)!==$row['db']
            || ($observed['client_addr']??null)!=='127.0.0.1'
            || (int)($observed['client_port']??0)!==(int)$row['client_port']
            || !is_string($row['backend_start']??null)
            || ($observed['backend_start']??null)!==$row['backend_start']
            || ($observed['application_name']??null)!==$nonce)
            throw new RuntimeException('RUNTIME_CLUSTER_MISMATCH');
    }

    public static function runtime(PDO $runtime, PDO $bootstrap, array $cluster, string $role): void
    {
        if ($runtime->getAttribute(PDO::ATTR_DRIVER_NAME)!=='pgsql' || $runtime->inTransaction())
            throw new RuntimeException('RUNTIME_CLUSTER_MISMATCH');
        $nonce='onepage-attest-'.bin2hex(random_bytes(16));
        $query=$runtime->prepare("SELECT set_config('application_name',?,false)");$query->execute([$nonce]);
        $row=$runtime->query("SELECT current_database() db,current_user actor,session_user login,
            host(inet_server_addr()) host,inet_server_port() port,pg_backend_pid() pid,inet_client_port() client_port,
            extract(epoch FROM pg_postmaster_start_time())::bigint started,
            (SELECT backend_start::text FROM pg_stat_activity WHERE pid=pg_backend_pid()) backend_start,
            rolsuper OR rolbypassrls OR rolcreatedb OR rolcreaterole OR rolinherit OR rolreplication
            OR EXISTS(SELECT 1 FROM pg_auth_members WHERE member=r.oid) unsafe
            FROM pg_roles r WHERE rolname=current_user")->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row)) throw new RuntimeException('RUNTIME_CLUSTER_MISMATCH');
        $q=$bootstrap->prepare('SELECT pid,usename,datname,host(client_addr) client_addr,client_port,backend_start::text,application_name FROM pg_stat_activity WHERE pid=?');
        $q->execute([$row['pid']]);$observed=$q->fetch(PDO::FETCH_ASSOC);
        self::runtimeEvidence($row,is_array($observed)?$observed:[],$cluster,$role,$nonce);
    }

    public static function configuration(string $environment, string $default, array $config, string $database = 'portal_onepage_buyer_ci'): void
    {
        if (!in_array($database, ['postgres','portal_onepage_buyer_ci'], true) || $environment !== 'testing' || $default !== 'pgsql'
            || ($config['driver'] ?? null) !== 'pgsql' || ($config['host'] ?? null) !== '127.0.0.1'
            || (string)($config['port'] ?? '') !== '55439'
            || ($config['database'] ?? null) !== $database
            || ($config['username'] ?? null) !== 'onepage_lab_bootstrap'
            || !empty($config['url']) || !empty($config['read']) || !empty($config['write'])
            || !empty($config['options'])) throw new RuntimeException('DISPOSABLE_CONFIG_REQUIRED');
    }

    public static function identity(PDO $db, string $cluster, string $controlBinary, string $database = 'portal_onepage_buyer_ci'): array
    {
        if (!in_array($database, ['postgres','portal_onepage_buyer_ci'], true)) throw new RuntimeException('DATABASE_REQUIRED');
        $cluster = realpath($cluster) ?: throw new RuntimeException('CLUSTER_REQUIRED');
        if (basename($cluster) !== 'data' || basename(dirname($cluster)) !== 'onepage-pg-lab')
            throw new RuntimeException('DEDICATED_CLUSTER_REQUIRED');
        $pid = file($cluster.'/postmaster.pid', FILE_IGNORE_NEW_LINES);
        $processId = (int)($pid[0] ?? 0);
        $argv = explode("\0", file_get_contents('/proc/'.$processId.'/cmdline'));
        $executable = realpath('/proc/'.$processId.'/exe');
        $controlBinary = realpath($controlBinary) ?: throw new RuntimeException('CONTROL_REQUIRED');
        if (!$pid || ($pid[1] ?? '') !== $cluster || ($pid[3] ?? '') !== '55439'
            || !in_array($cluster, $argv, true) || !$executable || basename($executable) !== 'postgres'
            || basename($controlBinary) !== 'pg_controldata' || dirname($controlBinary) !== dirname($executable)) throw new RuntimeException('LOCAL_PROCESS_REQUIRED');
        $row = $db->query("SELECT current_database() db,current_user actor,session_user login,
            host(inet_server_addr()) host,inet_server_port() port,current_setting('data_directory') directory,
            extract(epoch FROM pg_postmaster_start_time())::bigint started,
            (SELECT system_identifier::text FROM pg_control_system()) system_id,
            current_setting('server_version_num')::int version")->fetch(PDO::FETCH_ASSOC);
        $process = proc_open([$controlBinary, $cluster], [1=>['pipe','w'],2=>['pipe','w']], $pipes);
        if (!is_resource($process)) throw new RuntimeException('CONTROL_REQUIRED');
        $out=stream_get_contents($pipes[1]); fclose($pipes[1]);
        stream_get_contents($pipes[2]); fclose($pipes[2]); $exit=proc_close($process);
        preg_match('/Database system identifier:\s*(\d+)/', $out, $match);
        if ($exit !== 0 || !$row || $row['db'] !== $database
            || $row['actor'] !== 'onepage_lab_bootstrap' || $row['login'] !== 'onepage_lab_bootstrap'
            || $row['host'] !== '127.0.0.1' || (int)$row['port'] !== 55439 || $row['directory'] !== $cluster
            || abs((int)$row['started']-(int)($pid[2] ?? 0)) > 1
            || ($match[1] ?? null) !== $row['system_id'] || intdiv((int)$row['version'],10000) !== 18)
            throw new RuntimeException('DISPOSABLE_IDENTITY_MISMATCH');
        return $row;
    }
}
