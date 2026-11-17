<?php
declare(strict_types=1);
try {
    if (PHP_SAPI !== 'cli' || getenv('GITHUB_ACTIONS') !== 'true' || getenv('ADMIN_PHASE8_RECOVERY_CI') !== '1') throw new RuntimeException('ci_only');
    $db = new PDO((string)getenv('PERFORMANCE_PRIVATE_PG_DSN'),(string)getenv('PERFORMANCE_PRIVATE_PG_USER'),trim(file_get_contents((string)getenv('PERFORMANCE_PRIVATE_PG_PASSWORD_FILE'))),[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
    if ($db->query('SELECT current_user')->fetchColumn() !== 'performance_p6_reader') throw new RuntimeException('runtime_role');
    $fingerprint = (string)getenv('P8_CERTIFICATE_SHA256');
    if (!preg_match('/\A[a-f0-9]{64}\z/D',$fingerprint)) throw new RuntimeException('certificate');
    $resolve = $db->prepare('SELECT revision FROM performance_admin_inbox.resolve_delegation(:issuer,:key,:certificate,:company,:operation)');
    foreach (['aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa'=>false,'bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbbb'=>true] as $company=>$allowed) {
        $resolve->execute(['issuer'=>'portal_service','key'=>'phase7_key_01','certificate'=>$fingerprint,'company'=>$company,'operation'=>'dashboard.read']);
        if (($resolve->fetchColumn() !== false) !== $allowed) throw new RuntimeException('restored_delegation');
    }
    $nonce = $db->query("SELECT issuer,nonce,expires_at FROM performance_admin_inbox.nonces WHERE issuer='portal_service' ORDER BY expires_at,nonce LIMIT 1")->fetch(PDO::FETCH_ASSOC);
    if (!$nonce) throw new RuntimeException('persisted_nonce');
    $db->beginTransaction();$denied=false;
    try {
        $insert=$db->prepare('INSERT INTO performance_admin_inbox.nonces(issuer,nonce,expires_at) VALUES(:issuer,:nonce,:expires_at)');
        $insert->execute($nonce);
    } catch (PDOException $error) { $denied=$error->getCode()==='23505'; }
    $db->rollBack();
    if (!$denied) throw new RuntimeException('duplicate_nonce');
    // This proves the persisted database replay barrier, not an expired-envelope
    // denial masquerading as a fresh signed-request replay test.
    echo "PHASE8_RESTORED_DELEGATIONS_AND_NONCE_CONSTRAINT_PASS\n";
} catch (Throwable $error) {
    if (isset($db)&&$db->inTransaction())$db->rollBack();
    fwrite(STDERR,"PHASE8_RECEIVER_PROBE_DENIED\n");exit(1);
}
