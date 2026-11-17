<?php
declare(strict_types=1);

// CI only: the reconciliation below is deliberately bounded to this synthetic
// revocation exercise. It cannot provision users, rotate credentials, add
// permissions, re-enable a revoked grant, or operate against the source DB.
$stage = 'guard';
function encode(mixed $value): string { return json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES); }
function rows(PDO $db, string $table): array {
    return array_map(static fn(string $row): array => json_decode($row, true, 32, JSON_THROW_ON_ERROR),
        $db->query("SELECT row_to_json(t)::text FROM $table t ORDER BY row_to_json(t)::text")->fetchAll(PDO::FETCH_COLUMN));
}
function same(mixed $left, mixed $right): bool { return hash_equals(hash('sha256', encode($left)), hash('sha256', encode($right))); }
function subset(array $expected, array $actual): bool {
    $known = array_fill_keys(array_map(static fn(array $row): string => hash('sha256', encode($row)), $actual), true);
    foreach ($expected as $row) if (!isset($known[hash('sha256', encode($row))])) return false;
    return true;
}
function checkpoint(PDO $db, string $kind, string $cluster): array {
    $schemas = $kind === 'portal' ? "'public'" : "'performance_admin_inbox'";
    $authority = [];
    if ($kind === 'portal') {
        $authority['users'] = [];
        foreach (rows($db, 'public.portal_admin_users') as $user) {
            $authority['users'][] = ['id' => $user['id'], 'is_active' => $user['is_active'], 'session_version' => $user['session_version'],
                'last_totp_counter' => $user['last_totp_counter'], 'credential_sha256' => hash('sha256', $user['password_hash'].'|'.$user['totp_secret_ciphertext'])];
        }
        foreach (['portal_admin_org_grants', 'performance_admin_lab_memberships', 'portal_performance_services', 'portal_performance_grants'] as $table)
            $authority['public.'.$table] = rows($db, 'public.'.$table);
        $appendTables = ['public.portal_performance_access_audit', 'public.portal_admin_security_events', 'public.performance_admin_lab_events'];
    } else {
        foreach (['principals', 'service_delegations'] as $table) $authority['performance_admin_inbox.'.$table] = rows($db, 'performance_admin_inbox.'.$table);
        $appendTables = ['performance_admin_inbox.receipts', 'performance_admin_inbox.nonces', 'performance_admin_inbox.request_audit', 'performance_admin_inbox.principal_events'];
    }
    $append = [];
    foreach ($appendTables as $table) $append[$table] = rows($db, $table);
    $tables = [];
    foreach ($db->query("SELECT n.nspname||'.'||c.relname FROM pg_class c JOIN pg_namespace n ON n.oid=c.relnamespace WHERE n.nspname IN ($schemas) AND c.relkind IN ('r','p') ORDER BY 1")->fetchAll(PDO::FETCH_COLUMN) as $table) {
        if (!preg_match('/\A[a-z_][a-z_0-9]*\.[a-z_][a-z_0-9]*\z/D', $table)) throw new RuntimeException('table_name');
        $data = rows($db, $table);
        $tables[$table] = ['count' => count($data), 'sha256' => hash('sha256', encode($data))];
    }
    $profile = [
        // NULL means PostgreSQL's object-specific default ACL, not an empty
        // privilege set. pg_dump may omit an explicitly materialized default;
        // compare the effective ACL while retaining a real '{}' as distinct.
        'database' => $db->query("SELECT datname,pg_get_userbyid(datdba) owner,ARRAY(SELECT a::text FROM unnest(coalesce(datacl,acldefault('d',datdba))) a ORDER BY a::text)::text acl FROM pg_database WHERE datname=current_database()")->fetchAll(PDO::FETCH_ASSOC),
        'schemas' => $db->query("SELECT nspname,pg_get_userbyid(nspowner) owner,ARRAY(SELECT a::text FROM unnest(coalesce(nspacl,acldefault('n',nspowner))) a ORDER BY a::text)::text acl FROM pg_namespace WHERE nspname IN ($schemas) ORDER BY nspname")->fetchAll(PDO::FETCH_ASSOC),
        'relations' => $db->query("SELECT n.nspname,c.relname,c.relkind,c.relrowsecurity,c.relforcerowsecurity,pg_get_userbyid(c.relowner) owner,ARRAY(SELECT a::text FROM unnest(coalesce(c.relacl,acldefault((CASE WHEN c.relkind='S' THEN 's' ELSE 'r' END)::\"char\",c.relowner))) a ORDER BY a::text)::text acl FROM pg_class c JOIN pg_namespace n ON n.oid=c.relnamespace WHERE n.nspname IN ($schemas) AND c.relkind IN ('r','p','S') ORDER BY 1,2")->fetchAll(PDO::FETCH_ASSOC),
        'columns' => $db->query("SELECT n.nspname,c.relname,a.attname,format_type(a.atttypid,a.atttypmod) type,a.attnotnull,ARRAY(SELECT p::text FROM unnest(coalesce(a.attacl,'{}'::aclitem[])) p ORDER BY p::text)::text acl FROM pg_attribute a JOIN pg_class c ON c.oid=a.attrelid JOIN pg_namespace n ON n.oid=c.relnamespace WHERE n.nspname IN ($schemas) AND c.relkind IN ('r','p') AND a.attnum>0 AND NOT a.attisdropped ORDER BY 1,2,3")->fetchAll(PDO::FETCH_ASSOC),
        'policies' => $db->query("SELECT schemaname,tablename,policyname,permissive,roles::text,cmd,qual,with_check FROM pg_policies WHERE schemaname IN ($schemas) ORDER BY 1,2,3")->fetchAll(PDO::FETCH_ASSOC),
        'functions' => $db->query("SELECT n.nspname,p.proname,pg_get_function_identity_arguments(p.oid) arguments,p.prosecdef,pg_get_userbyid(p.proowner) owner,ARRAY(SELECT a::text FROM unnest(coalesce(p.proacl,acldefault('f',p.proowner))) a ORDER BY a::text)::text acl,pg_get_functiondef(p.oid) definition FROM pg_proc p JOIN pg_namespace n ON n.oid=p.pronamespace WHERE n.nspname IN ($schemas) ORDER BY 1,2,3")->fetchAll(PDO::FETCH_ASSOC),
        'roles' => $db->query("SELECT rolname,rolsuper,rolinherit,rolcreaterole,rolcreatedb,rolcanlogin,rolreplication,rolbypassrls FROM pg_roles WHERE rolname IN ('portal_p6_runtime','portal_p6_authority','performance_p6_reader') ORDER BY rolname")->fetchAll(PDO::FETCH_ASSOC),
        'memberships' => $db->query("SELECT r.rolname role,m.rolname member,g.rolname grantor,a.admin_option,a.inherit_option,a.set_option FROM pg_auth_members a JOIN pg_roles r ON r.oid=a.roleid JOIN pg_roles m ON m.oid=a.member JOIN pg_roles g ON g.oid=a.grantor WHERE r.rolname IN ('portal_p6_runtime','portal_p6_authority','performance_p6_reader') OR m.rolname IN ('portal_p6_runtime','portal_p6_authority','performance_p6_reader') ORDER BY 1,2,3")->fetchAll(PDO::FETCH_ASSOC),
    ];
    $sequences = $db->query("SELECT schemaname,sequencename,start_value,min_value,max_value,increment_by,cycle,cache_size,last_value FROM pg_sequences WHERE schemaname IN ($schemas) ORDER BY 1,2")->fetchAll(PDO::FETCH_ASSOC);
    $sessions = null;
    if ($kind === 'portal') {
        $payloads = $db->query('SELECT payload FROM sessions')->fetchAll(PDO::FETCH_COLUMN);
        $encrypted = count($payloads) > 0;
        foreach ($payloads as $payload) {
            $serialized = base64_decode($payload, true);
            $cipher = is_string($serialized) ? base64_decode($serialized, true) : false;
            $envelope = is_string($cipher) ? json_decode($cipher, true) : null;
            if (!is_array($envelope) || !isset($envelope['iv'], $envelope['value'], $envelope['mac'])
                || str_contains((string) $serialized, 'portal_admin_mfa_id')) $encrypted = false;
        }
        $sessions = ['count' => count($payloads), 'encrypted' => $encrypted];
    }
    return ['schema' => 'performance.phase8.checkpoint.v1', 'kind' => $kind, 'source_cluster' => $cluster,
        'authority' => $authority, 'append' => $append, 'tables' => $tables, 'profile' => $profile, 'sequences' => $sequences, 'sessions' => $sessions];
}
function ready(array $current, array $restored): bool {
    if (!same($current['authority'], $restored['authority']) || !same($current['profile'], $restored['profile'])) return false;
    foreach ($current['append'] as $table => $data) if (!subset($data, $restored['append'][$table] ?? [])) return false;
    return $restored['kind'] !== 'portal' || $restored['sessions']['encrypted'];
}
function restoreSequence(PDO $db, string $table): void {
    $sequence = $db->query("SELECT pg_get_serial_sequence('$table','id')")->fetchColumn();
    if (!is_string($sequence) || !preg_match('/\A[a-z_0-9]+\.[a-z_0-9]+\z/D', $sequence)) throw new RuntimeException('sequence');
    $maximum = (int) $db->query("SELECT coalesce(max(id),0) FROM $table")->fetchColumn();
    if ($maximum > 0) $db->exec("SELECT setval('$sequence',GREATEST((SELECT last_value FROM $sequence),$maximum),true)");
}
function reconcile(PDO $db, string $kind, array $current, array $restored): void {
    if (!same($current['profile'], $restored['profile']) || array_keys($current['authority']) !== array_keys($restored['authority'])) throw new RuntimeException('profile_changed');
    // Import current append-only records first; an authority update may itself
    // append a new audit event. No current audit, nonce or receipt is discarded.
    foreach ($restored['append'] as $table => $existing) {
        $desired = $current['append'][$table] ?? null;
        if (!is_array($desired) || count($desired) > 10000 || !subset($existing, $desired)) throw new RuntimeException('append_conflict');
        $known = array_fill_keys(array_map(static fn(array $row): string => hash('sha256', encode($row)), $existing), true);
        foreach ($desired as $row) if (!isset($known[hash('sha256', encode($row))])) {
            $insert = $db->prepare("INSERT INTO $table OVERRIDING SYSTEM VALUE SELECT * FROM json_populate_record(NULL::$table,CAST(:row AS json))");
            $insert->execute(['row' => encode($row)]);
        }
    }
    $sequenceTables = $kind === 'portal' ? ['public.portal_admin_security_events','public.performance_admin_lab_events'] : ['performance_admin_inbox.request_audit','performance_admin_inbox.principal_events'];
    foreach ($sequenceTables as $table) restoreSequence($db, $table);
    $mutable = $kind === 'portal' ? ['users','public.portal_performance_grants'] : ['performance_admin_inbox.service_delegations'];
    foreach ($restored['authority'] as $table => $oldRows) {
        $newRows = $current['authority'][$table];
        if (!in_array($table, $mutable, true)) {
            if (!same($oldRows, $newRows)) throw new RuntimeException('authority_scope_changed');
            continue;
        }
        $keyFields = $table === 'performance_admin_inbox.service_delegations' ? ['database_login','issuer','key_id','certificate_sha256','company_id','operation'] : ['id'];
        $key = static function(array $row) use ($keyFields): string { return encode(array_intersect_key($row, array_fill_keys($keyFields, true))); };
        $newByKey = [];
        foreach ($newRows as $row) $newByKey[$key($row)] = $row;
        if (count($oldRows) !== count($newByKey)) throw new RuntimeException('authority_identity_changed');
        foreach ($oldRows as $old) {
            $new = $newByKey[$key($old)] ?? null;
            if (!is_array($new)) throw new RuntimeException('authority_identity_changed');
            $fields = $table === 'users' ? ['is_active','session_version','last_totp_counter'] : ($kind === 'portal' ? ['revision','revoked_at'] : ['revision','active','revoked_at']);
            if (!same(array_diff_key($old,array_fill_keys($fields,true)),array_diff_key($new,array_fill_keys($fields,true)))) throw new RuntimeException('authority_credentials_or_scope_changed');
            if ($table === 'users') {
                if ($new['session_version'] < $old['session_version'] || $new['last_totp_counter'] < $old['last_totp_counter'] || (!$old['is_active'] && $new['is_active'])) throw new RuntimeException('authority_regression');
            } else {
                if ($new['revision'] < $old['revision'] || ($old['revoked_at'] !== null && $new['revoked_at'] === null)
                    || ($kind === 'receiver' && !$old['active'] && $new['active'])) throw new RuntimeException('authority_regression');
            }
            if (same($old,$new)) continue;
            $actualTable = $table === 'users' ? 'public.portal_admin_users' : $table;
            $set = implode(',',array_map(static fn(string $field):string => "$field=:$field",$fields));
            $where = implode(' AND ',array_map(static fn(string $field):string => "$field=:key_$field",$keyFields));
            $update = $db->prepare("UPDATE $actualTable SET $set WHERE $where");
            foreach ($fields as $field) $update->bindValue(':'.$field,$new[$field],is_bool($new[$field])?PDO::PARAM_BOOL:($new[$field]===null?PDO::PARAM_NULL:PDO::PARAM_STR));
            foreach ($keyFields as $field) $update->bindValue(':key_'.$field,$old[$field]);
            $update->execute();
            if ($update->rowCount() !== 1) throw new RuntimeException('authority_update');
        }
    }
}

try {
    if (PHP_SAPI !== 'cli' || getenv('GITHUB_ACTIONS') !== 'true' || getenv('ADMIN_PHASE8_RECOVERY_CI') !== '1') throw new RuntimeException('ci_only');
    [$script,$kind,$target,$action] = $argv + [null,null,null,null];
    if (!in_array($kind,['portal','receiver'],true) || !in_array($target,['live','restore'],true)
        || !in_array($action,['capture','fresh-roles','revoke','gate','reconcile','profile-regression'],true)) throw new RuntimeException('action');
    $host = $kind === 'portal' ? 'portal-db' : 'performance-db';
    $database = $kind === 'portal' ? 'portal_p6_synthetic' : 'performance_p6_synthetic';
    $owner = $kind === 'portal' ? 'portal_p6_migrator' : 'performance_p6_migrator';
    $ca = $kind === 'portal' ? '/run/pki/portal_db_ca.pem' : '/run/private/db-ca.pem';
    $db = new PDO("pgsql:host=$host;port=5432;dbname=$database;sslmode=verify-full;sslrootcert=$ca",$owner,trim(file_get_contents('/run/recovery-owner/owner-password')),[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
    $identity = $db->query("SELECT current_database() db,current_user actor,(SELECT ssl FROM pg_stat_ssl WHERE pid=pg_backend_pid()) tls,(SELECT system_identifier::text FROM pg_control_system()) cluster")->fetch(PDO::FETCH_ASSOC);
    $cluster = trim(file_get_contents('/run/recovery-context/cluster'));
    $sourceCluster = trim(file_get_contents('/run/recovery-context/source-cluster'));
    if ($identity['db'] !== $database || $identity['actor'] !== $owner || !$identity['tls'] || !hash_equals($cluster,$identity['cluster'])
        || ($target === 'live' ? $cluster !== $sourceCluster : $cluster === $sourceCluster)) throw new RuntimeException('database_identity');
    if ($action === 'fresh-roles') {
        $stage = 'fresh-target';
        if ($target !== 'restore' || (int)$db->query("SELECT count(*) FROM pg_class c JOIN pg_namespace n ON n.oid=c.relnamespace WHERE n.nspname IN ('public','performance_admin_inbox') AND c.relkind IN ('r','p')")->fetchColumn() !== 0) throw new RuntimeException('nonempty_target');
        $passwords = $kind === 'portal' ? ['portal_p6_runtime'=>'/run/secrets/portal_db_password','portal_p6_authority'=>'/run/secrets/portal_authority_password'] : ['performance_p6_reader'=>'/run/private/db-password'];
        foreach ($passwords as $role=>$file) {
            $password = trim(file_get_contents($file));
            if (hash_equals(trim(file_get_contents('/run/recovery-owner/owner-password')),$password)) throw new RuntimeException('owner_password_reuse');
            $db->exec("CREATE ROLE $role LOGIN PASSWORD ".$db->quote($password).' NOSUPERUSER NOBYPASSRLS NOCREATEDB NOCREATEROLE NOREPLICATION');
            if ($kind === 'portal') $db->exec("GRANT CONNECT ON DATABASE portal_p6_synthetic TO $role");
        }
    } elseif ($action === 'revoke') {
        $stage = 'current-revocation';
        if ($target !== 'live') throw new RuntimeException('source_required');
        $db->beginTransaction();
        if ($kind === 'portal') {
            $user = $db->query("SELECT id,session_version FROM portal_admin_users WHERE email='phase7-0@example.test' FOR UPDATE")->fetch(PDO::FETCH_ASSOC);
            if (!$user) throw new RuntimeException('nominal_user');
            $id = (int)$user['id'];$before = (int)$user['session_version'];$after = $before+1;
            $db->exec("UPDATE portal_admin_users SET session_version=$after WHERE id=$id");
            $event = bin2hex(random_bytes(16));
            $db->exec("INSERT INTO portal_admin_security_events(event_ref,admin_user_id,event_code,version_before,version_after,actor_context,occurred_at) VALUES('$event',$id,'sessions_revoked',$before,$after,'ci_synthetic_operator',clock_timestamp())");
            $db->exec("UPDATE portal_performance_grants SET revoked_at=extract(epoch FROM clock_timestamp())::bigint,revision=revision+1 WHERE company_id='aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa'");
        } else {
            $db->exec("UPDATE performance_admin_inbox.service_delegations SET active=false,revoked_at=clock_timestamp(),revision=revision+1 WHERE company_id='aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa'");
        }
        $db->commit();
    } elseif ($action === 'profile-regression') {
        $stage = 'profile-regression';
        if ($target !== 'restore' || $kind !== 'receiver') throw new RuntimeException('isolated_receiver_required');
        $baseline = checkpoint($db,$kind,$cluster);
        $scenarios = [
            'columns' => 'GRANT SELECT(active) ON performance_admin_inbox.service_delegations TO performance_p6_reader',
            'memberships' => 'CREATE ROLE performance_p8_profile_probe NOLOGIN NOSUPERUSER NOBYPASSRLS NOCREATEDB NOCREATEROLE NOREPLICATION; GRANT performance_p8_profile_probe TO performance_p6_reader',
            'relations' => 'REVOKE ALL ON performance_admin_inbox.principals FROM performance_p6_migrator',
        ];
        foreach ($scenarios as $category=>$sql) {
            $db->beginTransaction();
            $db->exec($sql);
            $changed = checkpoint($db,$kind,$cluster);
            if (same($baseline['profile'][$category],$changed['profile'][$category]) || ready($baseline,$changed)) throw new RuntimeException('profile_change_not_denied');
            $db->rollBack();
            if (!same($baseline['profile'],checkpoint($db,$kind,$cluster)['profile'])) throw new RuntimeException('profile_rollback_failed');
        }
        echo "PHASE8_PROFILE_REGRESSION_PASS: column ACL, empty ACL and runtime membership changes denied; isolated transactions rolled back\n";
    } else {
        $stage = 'capture';
        $state = checkpoint($db,$kind,$cluster);
        if ($action === 'capture') { echo encode($state).PHP_EOL; exit(0); }
        if ($target !== 'restore') throw new RuntimeException('restore_only');
        $current = json_decode(file_get_contents('/run/recovery-context/current.json'),true,64,JSON_THROW_ON_ERROR);
        if (($current['schema']??null) !== 'performance.phase8.checkpoint.v1' || ($current['kind']??null) !== $kind
            || ($current['source_cluster']??null) !== $sourceCluster) throw new RuntimeException('external_current_identity');
        $stage = 'current-authority-gate';
        if ($action === 'reconcile') {
            $stage = 'reconcile-current';$db->beginTransaction();
            reconcile($db,$kind,$current,$state);
            if (!ready($current,checkpoint($db,$kind,$cluster))) throw new RuntimeException('reconcile_incomplete');
            $db->commit();
        } elseif (!ready($current,$state)) throw new RuntimeException('stale_authority');
    }
    echo "PHASE8_STATE_OK kind=$kind action=$action\n";
} catch (Throwable $error) {
    if (isset($db) && $db->inTransaction()) $db->rollBack();
    fwrite(STDERR,'PHASE8_STATE_DENIED stage='.$stage.' type='.get_class($error).PHP_EOL);
    exit(1);
}
