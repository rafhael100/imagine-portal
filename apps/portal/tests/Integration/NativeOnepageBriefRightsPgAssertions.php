<?php
declare(strict_types=1);

namespace Tests\Integration;

use App\Domain\Commerce\OnepageAcceptedOrder;
use App\Domain\SitesIntegration\OnepageBriefSnapshotCandidateEncoder;
use App\Domain\SitesIntegration\SiteContractSnapshot;
use PDO;
use PDOException;
use PHPUnit\Framework\Assert;

/**
 * R28: first persistent buyer-rights candidate on the SAME native order.
 * CI-only fixture schema, never a migration, operational writer, approval,
 * browser click, or official source for #976. No editorial authority is made up.
 */
final class NativeOnepageBriefRightsPgAssertions
{
    public static function run(PDO $bootstrap, OnepageAcceptedOrder $order, string $site,
        string $session, array $deniedSessions): void
    {
        Assert::assertSame('DISPOSABLE_PG18', getenv('PORTAL_NATIVE_ORDER_TEST'));
        Assert::assertSame('portal_native_orders_ci', $bootstrap->query('SELECT current_database()')->fetchColumn());
        Assert::assertSame('portal_native_test_bootstrap', $bootstrap->query('SELECT session_user')->fetchColumn());
        Assert::assertSame(getenv('PORTAL_NATIVE_ORDER_TEST_DATA'), $bootstrap->query('SHOW data_directory')->fetchColumn());
        Assert::assertSame(18, intdiv((int)$bootstrap->query('SHOW server_version_num')->fetchColumn(),10000));
        $bootstrap->exec(<<<'SQL'
CREATE ROLE portal_brief_rights_lab_owner NOLOGIN NOSUPERUSER NOBYPASSRLS NOCREATEDB NOCREATEROLE NOREPLICATION NOINHERIT;
CREATE SCHEMA portal_brief_rights_lab;
REVOKE ALL ON SCHEMA portal_brief_rights_lab FROM PUBLIC;
GRANT USAGE ON SCHEMA portal_brief_rights_lab TO portal_brief_rights_lab_owner,portal_onepage_runtime;
GRANT USAGE ON SCHEMA portal_onepage TO portal_brief_rights_lab_owner;
GRANT EXECUTE ON FUNCTION portal_onepage.read_pending_order(text,uuid) TO portal_brief_rights_lab_owner;
CREATE TABLE portal_brief_rights_lab.versions (
 order_id uuid NOT NULL, site_id uuid NOT NULL, company uuid NOT NULL, buyer uuid NOT NULL,
 contract_ref text NOT NULL, contract_revision integer NOT NULL,
 version integer NOT NULL CHECK(version>0), source_bytes bytea NOT NULL,
 source_sha text NOT NULL CHECK(source_sha=encode(sha256(source_bytes),'hex')),
 CHECK(octet_length(source_bytes) BETWEEN 1 AND 150000),
 PRIMARY KEY(order_id,site_id,version),
 UNIQUE(order_id,site_id,version,source_sha,company,buyer)
);
CREATE TABLE portal_brief_rights_lab.confirmations (
 receipt_id uuid PRIMARY KEY DEFAULT gen_random_uuid(),
 order_id uuid NOT NULL, site_id uuid NOT NULL, version integer NOT NULL,
 source_sha text NOT NULL, company uuid NOT NULL, buyer uuid NOT NULL,
 rights_declared boolean NOT NULL CHECK(rights_declared),
 UNIQUE(order_id,site_id,version),
 FOREIGN KEY(order_id,site_id,version,source_sha,company,buyer)
 REFERENCES portal_brief_rights_lab.versions(order_id,site_id,version,source_sha,company,buyer)
);
ALTER TABLE portal_brief_rights_lab.versions ENABLE ROW LEVEL SECURITY;
ALTER TABLE portal_brief_rights_lab.versions FORCE ROW LEVEL SECURITY;
ALTER TABLE portal_brief_rights_lab.confirmations ENABLE ROW LEVEL SECURITY;
ALTER TABLE portal_brief_rights_lab.confirmations FORCE ROW LEVEL SECURITY;
REVOKE ALL ON portal_brief_rights_lab.versions,portal_brief_rights_lab.confirmations FROM PUBLIC;
GRANT SELECT ON portal_brief_rights_lab.versions TO portal_brief_rights_lab_owner;
GRANT SELECT,INSERT ON portal_brief_rights_lab.confirmations TO portal_brief_rights_lab_owner;
CREATE POLICY fixture_versions ON portal_brief_rights_lab.versions TO portal_brief_rights_lab_owner USING(true);
CREATE POLICY fixture_confirmations ON portal_brief_rights_lab.confirmations TO portal_brief_rights_lab_owner USING(true) WITH CHECK(true);
CREATE FUNCTION portal_brief_rights_lab.confirm_exact(p_session text,p_order uuid,p_site uuid,p_version integer,p_sha text,p_rights boolean)
RETURNS uuid LANGUAGE plpgsql SECURITY DEFINER SET search_path=pg_catalog,pg_temp SET row_security=on AS $$
DECLARE native jsonb; current_version portal_brief_rights_lab.versions%ROWTYPE; result uuid;
BEGIN
 IF p_rights IS DISTINCT FROM true OR p_sha IS NULL OR p_sha !~ '^[a-f0-9]{64}$' THEN RETURN NULL; END IF;
 -- Native session/MFA/order/contract locks are held until this transaction ends.
 native:=portal_onepage.read_pending_order(p_session,p_order);
 IF native IS NULL THEN RETURN NULL; END IF;
 -- Source revisions are installed only by the isolated fixture bootstrap.
 -- This proves sequential supersession, not a concurrent source writer.
 SELECT * INTO current_version FROM portal_brief_rights_lab.versions
 WHERE order_id=p_order AND site_id=p_site ORDER BY version DESC LIMIT 1;
 IF NOT FOUND OR current_version.version IS DISTINCT FROM p_version
 OR current_version.source_sha IS DISTINCT FROM p_sha
 OR current_version.company::text IS DISTINCT FROM native->>'company'
 OR current_version.buyer::text IS DISTINCT FROM native->>'buyer'
 OR current_version.contract_ref IS DISTINCT FROM native->>'contract_ref'
 OR current_version.contract_revision IS DISTINCT FROM (native->>'contract_revision')::integer THEN RETURN NULL; END IF;
 INSERT INTO portal_brief_rights_lab.confirmations(order_id,site_id,version,source_sha,company,buyer,rights_declared)
 VALUES(p_order,p_site,p_version,p_sha,current_version.company,current_version.buyer,true)
 ON CONFLICT(order_id,site_id,version) DO NOTHING;
 SELECT receipt_id INTO result FROM portal_brief_rights_lab.confirmations
 WHERE order_id=p_order AND site_id=p_site AND version=p_version AND source_sha=p_sha
 AND company=current_version.company AND buyer=current_version.buyer AND rights_declared;
 RETURN result;
END $$;
ALTER FUNCTION portal_brief_rights_lab.confirm_exact(text,uuid,uuid,integer,text,boolean) OWNER TO portal_brief_rights_lab_owner;
REVOKE ALL ON FUNCTION portal_brief_rights_lab.confirm_exact(text,uuid,uuid,integer,text,boolean) FROM PUBLIC;
GRANT EXECUTE ON FUNCTION portal_brief_rights_lab.confirm_exact(text,uuid,uuid,integer,text,boolean) TO portal_onepage_runtime;
SQL);
        $port=(string)getenv('PORTAL_NATIVE_ORDER_TEST_PORT');
        Assert::assertMatchesRegularExpression('/\A[0-9]{1,5}\z/D',$port);
        $connect=static fn(string $role): PDO => new PDO(
            'pgsql:host=127.0.0.1;port='.$port.';dbname=portal_native_orders_ci;connect_timeout=3',$role,'',
            [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_EMULATE_PREPARES=>false,PDO::ATTR_STRINGIFY_FETCHES=>false]);
        $runtime=$connect('portal_onepage_runtime');
        Assert::assertSame('portal_onepage_runtime',$runtime->query('SELECT session_user')->fetchColumn());
        $seed=static function(int $version,string $bytes)use($bootstrap,$order,$site):string {
            $sha=hash('sha256',$bytes);
            $bootstrap->prepare("INSERT INTO portal_brief_rights_lab.versions VALUES(?::uuid,?::uuid,?::uuid,?::uuid,?,?,?,decode(?,'hex'),?)")
                ->execute([$order->id,$site,$order->companyId,$order->buyerId,$order->contractRef,$order->contractRevision,$version,bin2hex($bytes),$sha]);
            return $sha;
        };
        $contract=new SiteContractSnapshot($order->companyReference,$order->id,$order->contractRef,$order->contractRevision,'onepage');
        $bytes=static function(string $intro,int $version)use($contract,$site,$bootstrap):string {
            $body=['company_name'=>'Empresa fictícia','company_intro'=>$intro,'services'=>'Serviço de demonstração',
                'current_site'=>'','contact_site'=>'','domain'=>'','email'=>'buyer@example.invalid','phone'=>'',
                'address'=>'','whatsapp'=>'','notes'=>'','ai_support'=>false,'logo_unavailable'=>true,'manual_unavailable'=>true];
            $raw=json_encode($body,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE|JSON_PRETTY_PRINT)."\n";
            $export=OnepageBriefSnapshotCandidateEncoder::build($contract,[
                'siteRef'=>$site,'snapshotRef'=>$bootstrap->query('SELECT gen_random_uuid()')->fetchColumn(),
                'operationId'=>'rights-fixture-'.$version,'idempotencyKey'=>'rights-fixture-'.$version,
                'sourceVersion'=>$version,'expectedBriefRevision'=>0,'payloadRef'=>'rights-payload-'.$version],$raw);
            Assert::assertSame($raw,$export['sourceBytes']);
            Assert::assertFalse($export['authorityVerified']);
            Assert::assertFalse($export['clientAccepted']);
            Assert::assertFalse($export['canPublish']);
            Assert::assertFalse($export['canAcceptRealOrders']);
            return $raw;
        };
        $v1=$bytes('Primeira versão sintética',1);
        $sha1=$seed(1,$v1);
        $confirm=static function(string $ref,int $version,string $sha,bool $rights=true,?string $siteOverride=null)use($runtime,$order,$site):?string {
            $q=$runtime->prepare('SELECT portal_brief_rights_lab.confirm_exact(?,?::uuid,?::uuid,?::integer,?,?::boolean)');
            $q->execute([hash('sha256',$ref),$order->id,$siteOverride??$site,$version,$sha,$rights?'true':'false']);
            $value=$q->fetchColumn();
            return is_string($value)?$value:null;
        };
        foreach ($deniedSessions as $denied) Assert::assertNull($confirm($denied,1,$sha1));
        Assert::assertNull($confirm($session,1,$sha1,false));
        Assert::assertNull($confirm($session,1,str_repeat('0',64)));
        Assert::assertNull($confirm($session,1,$sha1,true,$bootstrap->query('SELECT gen_random_uuid()')->fetchColumn()));
        Assert::assertSame(0,(int)$bootstrap->query('SELECT count(*) FROM portal_brief_rights_lab.confirmations')->fetchColumn());
        $r1=$confirm($session,1,$sha1);
        Assert::assertNotNull($r1);
        Assert::assertSame($r1,$confirm($session,1,$sha1),'Replay must preserve the exact receipt.');
        foreach (['SELECT * FROM portal_brief_rights_lab.versions','SELECT * FROM portal_brief_rights_lab.confirmations',
            'DELETE FROM portal_brief_rights_lab.confirmations','SET ROLE portal_brief_rights_lab_owner'] as $sql) {
            try { $runtime->exec($sql); Assert::fail('Unexpected direct fixture access'); }
            catch(PDOException $e) { Assert::assertSame('42501',$e->getCode()); }
        }
        $v2=$bytes('Segunda versão sintética, alterada',2);
        // R31: concurrent source supersession must serialize with exact rights confirmation.
        // Same fixture order/site; bootstrap is the only synthetic source writer.
        $sha2=hash('sha256',$v2);
        $runtime->exec("SET lock_timeout='250ms'");
        $bootstrap->exec("SET lock_timeout='250ms'");
        try {
            $runtime->beginTransaction();
            Assert::assertSame($r1,$confirm($session,1,$sha1));
            try {
                $seed(2,$v2);
                Assert::fail('Source append must not pass an in-flight confirmation.');
            } catch (PDOException $e) {
                Assert::assertSame('55P03',$e->getCode());
            }
            $runtime->rollBack();
            Assert::assertSame(1,(int)$bootstrap->query('SELECT count(*) FROM portal_brief_rights_lab.versions')->fetchColumn());
            // Reverse ordering: uncommitted source v2 must prevent a stale v1 replay.
            $bootstrap->beginTransaction();
            Assert::assertSame($sha2,$seed(2,$v2));
            try {
                $confirm($session,1,$sha1);
                Assert::fail('Old rights replay must not pass in-flight source append.');
            } catch (PDOException $e) {
                Assert::assertSame('55P03',$e->getCode());
            }
            $bootstrap->commit();
        } finally {
            if ($runtime->inTransaction()) $runtime->rollBack();
            if ($bootstrap->inTransaction()) $bootstrap->rollBack();
            $runtime->exec("SET lock_timeout='0'");
            $bootstrap->exec("SET lock_timeout='0'");
        }
        Assert::assertNotSame($sha1,$sha2);
        Assert::assertNull($confirm($session,1,$sha1),'Old revision is no longer confirmable.');
        Assert::assertNull($confirm($session,2,$sha1),'Cannot reuse old digest.');
        Assert::assertSame(1,(int)$bootstrap->query('SELECT count(*) FROM portal_brief_rights_lab.confirmations')->fetchColumn());
        $r2=$confirm($session,2,$sha2);
        Assert::assertNotNull($r2);
        Assert::assertNotSame($r1,$r2);
        Assert::assertSame($r2,$confirm($session,2,$sha2));
        // Separate bootstrap connection for independent raw storage assertion.
        // This is not an operational reader capability or approval witness.
        $observer=$connect('portal_native_test_bootstrap');
        Assert::assertSame(getenv('PORTAL_NATIVE_ORDER_TEST_DATA'),$observer->query('SHOW data_directory')->fetchColumn());
        $rows=$observer->query("SELECT c.receipt_id::text,c.order_id::text,c.site_id::text,c.company::text,c.buyer::text,c.version,c.source_sha,c.rights_declared,encode(v.source_bytes,'hex') AS bytes_hex
          FROM portal_brief_rights_lab.confirmations c JOIN portal_brief_rights_lab.versions v USING(order_id,site_id,version,source_sha,company,buyer) ORDER BY c.version")->fetchAll(PDO::FETCH_ASSOC);
        Assert::assertCount(2,$rows);
        foreach ([[$r1,$v1,$sha1],[$r2,$v2,$sha2]] as $i=>[$receipt,$raw,$digest]) {
            $row=$rows[$i];
            Assert::assertSame($receipt,$row['receipt_id']);
            Assert::assertSame($order->id,$row['order_id']);
            Assert::assertSame($site,$row['site_id']);
            Assert::assertSame($order->companyId,$row['company']);
            Assert::assertSame($order->buyerId,$row['buyer']);
            Assert::assertSame($i+1,(int)$row['version']);
            Assert::assertSame($digest,$row['source_sha']);
            Assert::assertSame(bin2hex($raw),$row['bytes_hex']);
            Assert::assertTrue($row['rights_declared']);
        }
        Assert::assertSame(1,(int)$observer->query('SELECT count(*) FROM portal_onepage.pending_orders')->fetchColumn());
    }
}
