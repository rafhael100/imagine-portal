<?php
declare(strict_types=1);

/**
 * F110: real disposable PostgreSQL18 migration/rollback safety verification.
 * Setup, check and cleanup are explicit; all data are synthetic.
 */
if(PHP_SAPI!=='cli'||$argc!==2||
    getenv('APP_ENV')!=='testing'||
    getenv('PORTAL_GSC_F110_IDENTITY_LAB')!=='1'||
    getenv('PGHOST')!=='127.0.0.1'||
    getenv('PGDATABASE')!=='portal_gsc_f110_lab'||
    !in_array($argv[1],['setup','verify','cleanup','final-cleanup'],true)){
    fwrite(STDERR,"F110 disposable CI only\n");exit(2);
}
function failUnless(bool $ok):void{
    if(!$ok)throw new RuntimeException('GSC F110 permission migration test refused');
}
$dsn='pgsql:host=127.0.0.1;port=5432;dbname=portal_gsc_f110_lab';
$pdo=new PDO($dsn,getenv('PGUSER'),getenv('PGPASSWORD'),[
    PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_EMULATE_PREPARES=>false,
]);
$ident='11111111-1111-4111-8111-111111111110';
$org='22222222-2222-4222-8222-222222222220';
$member='33333333-3333-4333-8333-333333333330';
$connect='44444444-4444-4444-8444-444444444440';
$view='55555555-5555-4555-8555-555555555550';
try{
    $version=(int)$pdo->query("SELECT current_setting('server_version_num')::integer/10000")->fetchColumn();
    failUnless($version===18);
    $mustCheck=static function()use($pdo):bool{
        $s=$pdo->prepare(
            'SELECT COUNT(*) FROM pg_constraint c
             WHERE c.conrelid = to_regclass(:target)
               AND c.conname = :constraint'
        );
        $s->execute(['target'=>'public.portal_membership_grants',
            'constraint'=>'portal_grant_permission_ck']);
        return (int)$s->fetchColumn()===1;
    };
    failUnless($mustCheck());
    if($argv[1]==='setup'){
        $pdo->prepare("INSERT INTO portal_identities
           (id,email_normalized,status,created_at,updated_at)
           VALUES (?,'f110@example.invalid','active',now(),now())")
            ->execute([$ident]);
        $pdo->prepare("INSERT INTO portal_organizations
           (id,reference,display_name,created_at,updated_at)
           VALUES (?,'f110-test-org','Synthetic only',now(),now())")
            ->execute([$org]);
        $pdo->prepare("INSERT INTO portal_memberships
           (id,identity_id,organization_id,role,status,created_at,updated_at)
           VALUES (?,?,?,'owner','active',now(),now())")
            ->execute([$member,$ident,$org]);
        $insert=$pdo->prepare("INSERT INTO portal_membership_grants
           (id,membership_id,permission_code,created_at,updated_at)
           VALUES (?,?,?,now(),now())");
        $insert->execute([$connect,$member,'seo.gsc.connect']);
        $insert->execute([$view,$member,'seo.gsc.view']);
        failUnless((int)$pdo->query("SELECT COUNT(*) FROM portal_membership_grants")
            ->fetchColumn()===2);
        // A wildcard or foreign permission can never be inserted.
        foreach(['seo.gsc.*','seo.gsc.admin','gsc.view','*'] as $index=>$invalid) {
            try{
                $invalidId=sprintf('99999999-9999-4999-8999-%012d',$index+1);
                $insert->execute([$invalidId,$member,$invalid]);
                failUnless(false);
            }catch(PDOException $e){
                failUnless($e->getCode()==='23514');
            }
        }
        // Duplicate grant is rejected by the foundation unique constraint.
        try{$insert->execute([
            '66666666-6666-4666-8666-666666666660',$member,'seo.gsc.view'
        ]);failUnless(false);}catch(PDOException $e){
            failUnless($e->getCode()==='23505');
        }
        echo "F110 setup: explicit connect + view stored, wildcard and duplicates refused\n";
    } elseif($argv[1]==='verify'){
        $rows=$pdo->query("SELECT permission_code FROM portal_membership_grants
            ORDER BY permission_code")->fetchAll(PDO::FETCH_COLUMN);
        failUnless($rows===['seo.gsc.connect','seo.gsc.view']);
        $q=$pdo->query("SELECT count(*) FROM pg_policies
            WHERE tablename='portal_membership_grants'");
        failUnless((int)$q->fetchColumn()===0);
        echo "F110 verify: rollback refused while view is still granted\n";
    } elseif($argv[1]==='cleanup') {
        $pdo->prepare("DELETE FROM portal_membership_grants
            WHERE id=? AND permission_code='seo.gsc.view'")->execute([$view]);
        failUnless((int)$pdo->query("SELECT COUNT(*) FROM portal_membership_grants
            WHERE permission_code='seo.gsc.connect'")->fetchColumn()===1);
        echo "F110 cleanup: explicit view grant revoked, connect still exists\n";
    } else {
        $pdo->prepare("DELETE FROM portal_membership_grants WHERE id=?")->execute([$connect]);
        $pdo->prepare("DELETE FROM portal_memberships WHERE id=?")->execute([$member]);
        $pdo->prepare("DELETE FROM portal_identities WHERE id=?")->execute([$ident]);
        $pdo->prepare("DELETE FROM portal_organizations WHERE id=?")->execute([$org]);
        failUnless((int)$pdo->query("SELECT COUNT(*) FROM portal_membership_grants")->fetchColumn()===0);
        echo "F110 final cleanup: original connect grant and all fixture rows safely cleared\n";
    }
}catch(Throwable $e){
    fwrite(STDERR,"F110 disposable permission test failed\n");exit(1);
}
