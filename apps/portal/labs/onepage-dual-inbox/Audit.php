<?php
declare(strict_types=1);

namespace PrivateInboxProtocol;

use Closure;
use PDO;
use RuntimeException;
use PrivateDualDb\DualDbGate;
use PrivateDualDb\InboxInstall;
use PrivateInboxSemantic\Compare;

/** No command launcher, file reader, supervisor, SQL connection factory or DDL owner. */
final readonly class Audit
{
    private const CATALOG_SHA='808aa9b6f8273a81787b798f6a03b58f2eace9ec2d480af3da356fcfb9bd7734';
    private const MANIFESTS=[
      'F391'=>'3efabeccc3df221dd88af27661216a51860d2d810464f66737d6a0bc604a9efa',
      'F392'=>'d0dceed8d6be174283a80edfee2d7a41b7b85da7fcca5f32922c10f0468028c9',
    ];
    public function __construct(private string $catalogSql, private array $manifests,
        private array $baseline, private array $phaseAnchors, private array $globalExpected)
    {
        self::need(hash_equals(self::CATALOG_SHA,hash('sha256',$catalogSql)));
        self::need(array_keys($manifests)===array_keys(self::MANIFESTS));
        foreach(self::MANIFESTS as $phase=>$hash)
            self::need(is_string($manifests[$phase])&&hash_equals($hash,hash('sha256',$manifests[$phase])));
        self::need(array_keys($globalExpected)===['F391','F392','POST_NATIVE','POST_BINDING']);
        self::need(array_keys($phaseAnchors)===['POST_NATIVE','POST_BINDING']);
        // Only reviewed native role/membership *expectations* may change here;
        // same independent pre-install DB inventory, ACLs and extensions remain immutable.
        foreach ($phaseAnchors as $delta) self::need(array_keys($delta)===['roles','memberships']);
        foreach ($globalExpected as $facts) self::need(is_array($facts));
    }

    private static function need(bool $ok): void
    {
        if(!$ok) throw new RuntimeException('INBOX_AUDIT_REFUSED');
    }

    private static function canonical(mixed $value): mixed
    {
        if(!is_array($value)) return $value;
        if(!array_is_list($value)) ksort($value);
        return array_map(self::canonical(...),$value);
    }

    private static function quote(string $fixed): string
    {
        self::need(preg_match('/\A[A-Za-z0-9_]+\z/D',$fixed)===1);
        return "'".$fixed."'";
    }

    private static function object(string $line): array
    {
        self::need($line!=='' && strlen($line)<=262144
            && !str_contains($line,"\r") && substr_count($line,"\n")===1 && str_ends_with($line,"\n"));
        $v=json_decode(substr($line,0,-1),true,64,JSON_THROW_ON_ERROR);
        self::need(is_array($v)&&!array_is_list($v));
        return $v;
    }

    private static function exactKeys(array $v,array $expected): void
    {
        $keys=array_keys($v);sort($keys);
        self::need($keys===$expected);
    }

    private function sql(string $phase,string $nonce): string
    {
        self::need(isset($this->globalExpected[$phase])&&preg_match('/\A[a-f0-9]{64}\z/D',$nonce)===1);
        $catalog=trim($this->catalogSql);
        self::need(str_ends_with($catalog,';'));
        return 'SELECT jsonb_build_object('
            ."'phase',".self::quote($phase).",'nonce',".self::quote($nonce)
            .",'backend',pg_backend_pid(),'facts',g.facts::jsonb,'fresh',g.fresh,"
            ."'catalog',i.semantic_inventory::jsonb)::text AS proof"
            ." FROM (\n".DualDbGate::SQL."\n) g CROSS JOIN (\n".substr($catalog,0,-1)."\n) i;\n";
    }

    private function auditLine(string $phase,string $nonce,int $backend,string $line): void
    {
        $v=self::object($line);
        self::exactKeys($v,['backend','catalog','facts','fresh','nonce','phase']);
        self::need($v['phase']===$phase&&$v['nonce']===$nonce&&$v['backend']===$backend
            && $v['fresh']===true&&is_array($v['facts'])&&is_array($v['catalog']));
        self::need(self::canonical($v['facts'])===self::canonical($this->globalExpected[$phase]));
        $baseline=$this->baseline;
        if(isset($this->phaseAnchors[$phase])) $baseline=array_replace($baseline,$this->phaseAnchors[$phase]);
        $stage=$phase==='F391'?'F391':'F392';
        Compare::matches($stage,$this->manifests[$stage],$baseline,$v['catalog']);
    }

    private function beforeSql(string $nonce): string
    {
        $fingerprint=<<<'SQL'
SELECT jsonb_build_object(
 'databases',(SELECT coalesce(jsonb_agg(jsonb_build_array(datname,pg_catalog.pg_get_userbyid(datdba),
      datallowconn,datistemplate,datconnlimit,datacl IS NULL)),'[]') FROM pg_catalog.pg_database),
 'roles',(SELECT coalesce(jsonb_agg(jsonb_build_array(rolname,rolcanlogin,rolsuper,rolinherit,rolcreatedb,
      rolcreaterole,rolreplication,rolbypassrls)),'[]') FROM pg_catalog.pg_roles),
 'memberships',(SELECT coalesce(jsonb_agg(jsonb_build_array(pg_catalog.pg_get_userbyid(roleid),
      pg_catalog.pg_get_userbyid(member),pg_catalog.pg_get_userbyid(grantor),admin_option,inherit_option,set_option)),'[]')
      FROM pg_catalog.pg_auth_members),
 'extensions',(SELECT coalesce(jsonb_agg(jsonb_build_array(e.extname,e.extversion,
      pg_catalog.pg_get_userbyid(e.extowner),n.nspname,e.extrelocatable)),'[]')
      FROM pg_catalog.pg_extension e JOIN pg_catalog.pg_namespace n ON n.oid=e.extnamespace),
 'acl',(SELECT coalesce(jsonb_agg(jsonb_build_array(kind,object,NULL,
      CASE WHEN grantee=0 THEN 'PUBLIC' ELSE pg_catalog.pg_get_userbyid(grantee) END,
      pg_catalog.pg_get_userbyid(grantor),privilege_type,is_grantable)),'[]')
      FROM (
          SELECT 'schema' kind,nspname AS object,x.* FROM pg_catalog.pg_namespace n
          CROSS JOIN LATERAL pg_catalog.aclexplode(coalesce(n.nspacl,pg_catalog.acldefault('n',n.nspowner))) x
          WHERE n.nspname='public'
          UNION ALL
          SELECT 'database',datname,x.* FROM pg_catalog.pg_database d
          CROSS JOIN LATERAL pg_catalog.aclexplode(coalesce(d.datacl,pg_catalog.acldefault('d',d.datdba))) x
      ) a)
) baseline
SQL;
        return "SELECT jsonb_build_object('phase','BEFORE','nonce',".self::quote($nonce)
            .",'backend',pg_backend_pid(),'baseline',b.baseline)::text FROM (\n".$fingerprint."\n) b;\n";
    }

    private function auditBefore(array $v): void
    {
        $keys=['acl','databases','extensions','memberships','roles'];
        self::exactKeys($v,$keys);self::exactKeys($this->baseline,$keys);
        foreach ($keys as $k) {
            $received=$v[$k];$expected=$this->baseline[$k];
            self::need(is_array($received)&&is_array($expected)&&array_is_list($received)&&array_is_list($expected));
            $sort=static fn(array $a,array $b):int=>strcmp(json_encode($a,JSON_THROW_ON_ERROR),
                json_encode($b,JSON_THROW_ON_ERROR));
            usort($received,$sort);usort($expected,$sort);
            self::need($received===$expected);
        }
    }

    public function session(array $anchor): Session
    {
        DualDbGate::validateAnchor($anchor);
        self::need($anchor['phase']==='asaas_empty_owner_ready');
        $required=['F391','F392'];
        foreach ($required as $phase) {
            $fixed=$anchor['expected'];unset($fixed['roles'],$fixed['memberships'],$fixed['empty']);
            $next=$this->globalExpected[$phase];unset($next['roles'],$next['memberships'],$next['empty']);
            self::need(self::canonical($fixed)===self::canonical($next)&&$this->globalExpected[$phase]['empty']===false);
        }
        $emit=fn(string $phase):string=>$this->sql($phase,$anchor['nonce']);
        $verify=fn(string $phase,int $backend,string $line)=>$this->auditLine($phase,$anchor['nonce'],$backend,$line);
        return new Session($anchor,$emit,$verify,$this->beforeSql($anchor['nonce']),$this->auditBefore(...));
    }

    /** Compatibility with SetupBridge::run(..., Closure $semanticAudit). */
    public function forSetupBridge(): Closure
    {
        return function(string $phase,PDO $pdo):bool {
            self::need(in_array($phase,['F392','POST_NATIVE','POST_BINDING'],true)
                && !$pdo->inTransaction()&&$pdo->getAttribute(PDO::ATTR_DRIVER_NAME)==='pgsql');
            $pid=$pdo->query('SELECT pg_backend_pid()')->fetchColumn();
            self::need(is_int($pid)&&$pid>1);
            $nonce=bin2hex(random_bytes(32));
            $stmt=$pdo->query($this->sql($phase,$nonce));
            $line=$stmt->fetchColumn();
            self::need(is_string($line)&&$stmt->fetchColumn()===false);
            $this->auditLine($phase,$nonce,$pid,$line."\n");
            self::need(!$pdo->inTransaction()&&$pdo->query('SELECT pg_backend_pid()')->fetchColumn()===$pid);
            return true;
        };
    }
}

/** One use only. A failed or out-of-order frame poisons the instance. */
final class Session
{
    private int $step=0;
    private int $backend;
    public function __construct(private readonly array $anchor,private readonly Closure $sql,
        private readonly Closure $audit,private readonly string $initialSql,private readonly Closure $checkInitial) {}
    private function requireStep(int $step): void
    {
        if($this->step!==$step) {$this->step=-1;throw new RuntimeException('INBOX_SESSION_REFUSED');}
        $this->step=-1;
    }
    public function begin(): string
    {
        $this->requireStep(0);
        $out=InboxInstall::preamble($this->anchor).$this->initialSql;
        $this->step=1;return $out;
    }
    public function continueAfter(string $line): string
    {
        $s=$this->step;
        if(!in_array($s,[1,2,3],true)) $this->requireStep(-2);
        $this->requireStep($s);
        if ($s===1) {
            if ($line===''||strlen($line)>262144||substr_count($line,"\n")!==1||!str_ends_with($line,"\n")
                ||str_contains($line,"\r")) throw new RuntimeException('INBOX_SESSION_REFUSED');
            $a=json_decode($line,true,64,JSON_THROW_ON_ERROR);$keys=is_array($a)?array_keys($a):[];sort($keys);
            if ($keys!==['backend','baseline','nonce','phase']||$a['phase']!=='BEFORE'||$a['nonce']!==$this->anchor['nonce']
                ||!is_int($a['backend'])||$a['backend']<=1||!is_array($a['baseline'])) throw new RuntimeException('INBOX_SESSION_REFUSED');
            ($this->checkInitial)($a['baseline']);
            $this->backend=$a['backend'];
            $next="\\i inbox.sql\n".($this->sql)('F391');
        } elseif ($s===2) {
            ($this->audit)('F391',$this->backend,$line);
            $next="\\i lease.sql\n".($this->sql)('F392');
        } else {
            ($this->audit)('F392',$this->backend,$line);
            $next="\\echo PASS INBOX_INSTALLED\n";
        }
        $this->step=$s+1;return $next;
    }
    public function accepted(int $exit, string $marker, bool $stderrWasEmpty, bool $pdoAuditPassed): bool
    {
        return $this->step===4&&$exit===0&&$marker===InboxInstall::MARK&&$stderrWasEmpty&&$pdoAuditPassed;
    }
}
