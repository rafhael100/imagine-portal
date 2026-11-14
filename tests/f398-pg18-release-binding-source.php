<?php

declare(strict_types=1);

/** F1-398 real disposable PG18; no current customer, contract, PSP or IAM. */
if (PHP_SAPI!=='cli' || getenv('GITHUB_ACTIONS')!=='true' ||
    getenv('PORTAL_F398_BINDING_CI')!=='1' ||
    getenv('DB_HOST')!=='127.0.0.1' ||
    getenv('DB_DATABASE')!=='portal_release_ci') {
    fwrite(STDERR,"F1-398 requires isolated CI PostgreSQL18.\n");
    exit(2);
}
$root=dirname(__DIR__).'/apps/portal/app/Domain/SitesIntegration/';
spl_autoload_register(static function(string $name)use($root):void {
    $prefix='App\\Domain\\SitesIntegration\\';
    if (!str_starts_with($name,$prefix))return;
    $short=substr($name,strlen($prefix));
    if (preg_match('/\A[A-Za-z][A-Za-z0-9]*\z/D',$short)!==1)
        throw new RuntimeException('Class name not allowed.');
    require $root.$short.'.php';
});
use App\Domain\SitesIntegration\{
    PortalPg18SiteReleaseAssignmentSource,
    PortalSitesReleaseAssignment,
};
function db398(string $user,string $password): PDO
{
    return new PDO(
        'pgsql:host=127.0.0.1;port=5432;dbname=portal_release_ci;connect_timeout=5',
        $user,$password,
        [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,
         PDO::ATTR_EMULATE_PREPARES=>false]
    );
}
$checks=0;
function ok398(bool $ok,string $reason):void {
    global $checks;
    if (!$ok)throw new RuntimeException('F1-398 FAIL: '.$reason);
    ++$checks;
}
function denied398(callable $op):bool {
    try{$op();}catch(Throwable){return true;}
    return false;
}
$admin=db398('postgres','CI-ONLY-RELEASE-POSTGRES');
$reader=db398('portal_release_reader','CI-ONLY-PORTAL-RELEASE-READ-NOT-REAL');
ok398((int)$admin->query('SHOW server_version_num')->fetchColumn()>=180000,
    'actual PostgreSQL 18 required');
ok398($reader->query('SELECT current_user')->fetchColumn()==='portal_release_reader' &&
    $reader->query('SELECT session_user')->fetchColumn()==='portal_release_reader',
    'reader must be actual low privilege SQL session, not a superuser SET ROLE');
ok398((int)$reader->query(
    'SELECT count(*) FROM portal_release.binding_history'
)->fetchColumn()===0,'without signed HMAC reader cannot enumerate any tenants');
ok398(denied398(static fn()=>$reader->query(
    'SELECT secret FROM portal_release.tenant_hmac_keys'
)), 'reader cannot read HMAC secrets from privileged authority');
ok398(denied398(static fn()=>$reader->exec(
    "DELETE FROM portal_release.binding_history WHERE tenant_ref='tenant-ci-a'"
)), 'reader cannot DELETE immutable binding history');
ok398(denied398(static fn()=>$reader->exec(
    "UPDATE portal_release.binding_history SET binding_state='active'"
)), 'reader cannot silently reinstate a revoked release');
ok398(denied398(static fn()=>new PortalPg18SiteReleaseAssignmentSource(
    $admin,'tenant-ci-a',str_repeat('0',64)
)), 'owner/superuser PDO cannot impersonate scoped reader');

$secretA=hex2bin(str_repeat('ab',32));
$secretB=hex2bin(str_repeat('cd',32));
$macA=hash_hmac('sha256','portal-sites-binding-v1:tenant-ci-a',$secretA);
$macB=hash_hmac('sha256','portal-sites-binding-v1:tenant-ci-b',$secretB);
$sourceA=new PortalPg18SiteReleaseAssignmentSource($reader,'tenant-ci-a',$macA);
$sourceB=new PortalPg18SiteReleaseAssignmentSource(
    db398('portal_release_reader','CI-ONLY-PORTAL-RELEASE-READ-NOT-REAL'),
    'tenant-ci-b',$macB
);
$malicious=new PortalPg18SiteReleaseAssignmentSource(
    db398('portal_release_reader','CI-ONLY-PORTAL-RELEASE-READ-NOT-REAL'),
    'tenant-ci-b',$macA
);
$recordA=$sourceA->readCurrent('order-ci-a','site-ci-a','release-ci-a',4);
ok398($recordA instanceof PortalSitesReleaseAssignment &&
    $recordA->tenantRef==='tenant-ci-a' &&
    $recordA->orderRef==='order-ci-a' &&
    $recordA->contractRef==='contract-ci-a' &&
    $recordA->contractRevision===2 &&
    $recordA->bindingRevision===1 &&
    $recordA->productCode==='onepage' &&
    $recordA->artifactDigest===str_repeat('a',64),
    'signed tenant A may read exact active commercial/editorial association');
ok398($sourceB->readCurrent('order-ci-b','site-ci-b','release-ci-b',5)
    ?->tenantRef==='tenant-ci-b',
    'independent tenant B credential matches only its current release');
ok398($sourceA->readCurrent('order-ci-b','site-ci-b','release-ci-b',5)===null &&
    $sourceB->readCurrent('order-ci-a','site-ci-a','release-ci-a',4)===null,
    'cross-tenant known order/site/release IDs remain invisible');
ok398($malicious->readCurrent('order-ci-b','site-ci-b','release-ci-b',5)===null,
    'a caller cannot set tenant B with an HMAC minted for tenant A');
$reader->exec("SET imagine.release_tenant='tenant-ci-b'");
$reader->exec("SET imagine.release_mac='".str_repeat('0',64)."'");
ok398((int)$reader->query(
    'SELECT count(*) FROM portal_release.binding_history'
)->fetchColumn()===0,
    'direct SQL SET of tenant and forged HMAC cannot enumerate another tenant');
ok398($sourceA->readCurrent('order-ci-a','site-ci-a','release-ci-a',4)
    ?->bindingRevision===1,
    'scoped SET LOCAL overrides untrusted session GUC only for transaction');
ok398((int)$reader->query(
    'SELECT count(*) FROM portal_release.binding_history'
)->fetchColumn()===0,
    'signed claims expire at commit without leaking HMAC to next query');
ok398($sourceA->readCurrent('../orders','site-ci-a','release-ci-a',4)===null &&
    $sourceA->readCurrent('order-ci-a','site-ci-a','release-ci-a',0)===null,
    'malformed IDs and editorial revision fail closed before querying SQL');
ok398($sourceA->readCurrent('order-ci-a','site-ci-a','release-ci-a',5)===null,
    'current trusted source rejects mismatched editorial revision');
ok398($sourceA->readCurrent('order-ci-a','site-other','release-ci-a',4)===null,
    'same order cannot borrow another site');

/* All writes below belong only to superuser in disposable CI.
 * Real application/service role cannot INSERT, UPDATE or DELETE. */
$admin->exec(
    "INSERT INTO portal_release.binding_history
     (tenant_ref,order_ref,contract_ref,contract_revision,product_code,
      site_ref,release_ref,artifact_digest,editorial_revision,
      binding_revision,binding_state)
     VALUES('tenant-ci-a','order-ci-a','contract-ci-a',2,'onepage',
       'site-ci-a','release-ci-a','".str_repeat('a',64)."',4,2,'revoked')"
);
ok398($sourceA->readCurrent('order-ci-a','site-ci-a','release-ci-a',4)===null,
    'revocation is append-only and supersedes earlier active record');
ok398($sourceB->readCurrent('order-ci-b','site-ci-b','release-ci-b',5)
    ?->bindingRevision===1,
    'tenant A revocation does not affect tenant B current release');
$admin->exec(
    "INSERT INTO portal_release.binding_history
     (tenant_ref,order_ref,contract_ref,contract_revision,product_code,
      site_ref,release_ref,artifact_digest,editorial_revision,
      binding_revision,binding_state)
     VALUES('tenant-ci-a','order-ci-a','contract-ci-a',2,'onepage',
       'site-ci-a','release-ci-a2','".str_repeat('c',64)."',5,3,'active')"
);
ok398($sourceA->readCurrent('order-ci-a','site-ci-a','release-ci-a',4)===null,
    'old signed proof cannot be reactivated by a newer release revision');
$new=$sourceA->readCurrent('order-ci-a','site-ci-a','release-ci-a2',5);
ok398($new instanceof PortalSitesReleaseAssignment &&
    $new->bindingRevision===3 &&
    $new->artifactDigest===str_repeat('c',64),
    'only the newest versioned active release can be issued');
ok398((int)$admin->query(
    "SELECT count(*) FROM portal_release.binding_history
      WHERE order_ref='order-ci-a'"
)->fetchColumn()===3,
    'append-only history preserves old version and revocation for audit');
ok398(denied398(static fn()=>$reader->exec(
    "INSERT INTO portal_release.binding_history
      (tenant_ref,order_ref,contract_ref,contract_revision,product_code,
       site_ref,release_ref,artifact_digest,editorial_revision,
       binding_revision,binding_state)
      VALUES('tenant-ci-a','evil','fake',1,'onepage','fake','fake','".
         str_repeat('f',64)."',1,4,'active')"
)), 'reader cannot create authorization record');

echo "$checks F1-398 PG18 signed-tenant immutable release association assertions passed.\n";
echo "NO-GO: only fictional tenants, HMAC, roles and release; no Portal IAM, PSP, Sites publishing or VPS schema.\n";
