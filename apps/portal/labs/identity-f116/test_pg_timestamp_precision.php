<?php
declare(strict_types=1);

// Disposable PostgreSQL regression only; never an operational authentication probe.
if (PHP_SAPI !== 'cli' || getenv('APP_ENV') !== 'testing'
    || getenv('PORTAL_F116_PG_LAB') !== '1'
    || getenv('PGHOST') !== '127.0.0.1'
    || getenv('PGDATABASE') !== 'portal_identity_lab') {
    throw new RuntimeException('Disposable local identity laboratory required.');
}
$db = new PDO('pgsql:host=127.0.0.1;port=5432;dbname=portal_identity_lab',
    'postgres', (string) getenv('F66_PG_ADMIN_PASSWORD'),
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$truth = static fn(mixed $v): bool => in_array($v, [true, 1, '1', 't'], true);
$moment = '2026-01-01 00:00:00.750000+00';
$legacy = $db->prepare('SELECT CAST(CAST(? AS timestamptz) AS timestamptz(0)) > CAST(? AS timestamptz)');
$legacy->execute([$moment, $moment]);
if (!$truth($legacy->fetchColumn())) {
    throw new RuntimeException('Legacy future-rounding reproducer failed.');
}
$tables = [
    'portal_identities' => ['email_verified_at', 'mfa_confirmed_at'],
    'portal_auth_sessions' => ['mfa_verified_at', 'issued_at', 'expires_at', 'revoked_at'],
    'portal_email_verification_challenges' => ['issued_at', 'expires_at', 'consumed_at', 'revoked_at'],
];
$checks = 0;
foreach ($tables as $table => $columns) {
    foreach ($columns as $column) {
        $schema = $db->prepare("SELECT datetime_precision FROM information_schema.columns WHERE table_schema='public' AND table_name=? AND column_name=?");
        $schema->execute([$table, $column]);
        if ((int) $schema->fetchColumn() !== 6) {
            throw new RuntimeException('Microsecond schema precision required.');
        }
        // Clone the actual column type, rather than a manually chosen probe type.
        $db->beginTransaction();
        try {
            $db->exec("CREATE TEMP TABLE temporal_precision_probe AS SELECT $column AS observed_at FROM public.$table WITH NO DATA");
            $insert = $db->prepare('INSERT INTO temporal_precision_probe VALUES (CAST(? AS timestamptz))');
            $insert->execute([$moment]);
            $compare = $db->prepare('SELECT observed_at = CAST(? AS timestamptz) FROM temporal_precision_probe');
            $compare->execute([$moment]);
            if (!$truth($compare->fetchColumn())) {
                throw new RuntimeException('Security event time was rounded.');
            }
            $checks++;
        } finally {
            $db->rollBack();
        }
    }
}
echo "PASS timestamp precision: legacy failure reproduced and $checks actual columns preserve event time\n";
