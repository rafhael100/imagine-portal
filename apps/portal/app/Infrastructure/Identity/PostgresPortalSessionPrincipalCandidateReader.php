<?php

declare(strict_types=1);
namespace App\Infrastructure\Identity;

use PDO;
use RuntimeException;
use Throwable;

/**
 * Read-only principal observation, not a capability or permission decision.
 * No container binding. A restore fence and resource authorization are still required.
 */
final class PostgresPortalSessionPrincipalCandidateReader
{
    public function __construct(private PDO $db, private string $database)
    {
        if (preg_match('/\A[a-z][a-z0-9_]{1,62}\z/D',$database)!==1) {
            throw new RuntimeException('Dedicated Portal database required.');
        }
        $this->ready();
    }

    /** @return array{identity_id:string,organization_id:string,membership_id:string,identity_revision:int,membership_revision:int,organization_revision:int,expires_at:string,authorizes_http:false}|null */
    public function read(string $reference): ?array
    {
        if (preg_match('/\A[a-f0-9]{64}\z/D',$reference)!==1) return null;
        $this->ready();
        try {
            $q=$this->db->prepare('SELECT * FROM public.portal_browser_principal_candidate(?)');
            $q->execute([hash('sha256',$reference)]);
            $rows=$q->fetchAll(PDO::FETCH_ASSOC);
        } catch (Throwable) { throw new RuntimeException('Portal principal observation unavailable.'); }
        if ($rows===[]) return null;
        if (count($rows)!==1) throw new RuntimeException('Ambiguous Portal principal observation.');
        $row=$rows[0];
        foreach (['identity_id','organization_id','membership_id'] as $field) {
            if (!is_string($row[$field]??null)
                ||preg_match('/\A[0-9a-f]{8}-[0-9a-f]{4}-[1-8][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}\z/D',$row[$field])!==1) {
                throw new RuntimeException('Invalid Portal principal observation.');
            }
        }
        foreach (['identity_revision','membership_revision','organization_revision'] as $field) {
            $value=filter_var($row[$field]??null,FILTER_VALIDATE_INT,['options'=>['min_range'=>1]]);
            if ($value===false) throw new RuntimeException('Invalid Portal principal revision.');
            $row[$field]=$value;
        }
        if (!is_string($row['expires_at']??null)||strlen($row['expires_at'])>64) {
            throw new RuntimeException('Invalid Portal principal expiry.');
        }
        $row['authorizes_http']=false;
        return $row;
    }

    private function ready(): void
    {
        try {
            if ($this->db->getAttribute(PDO::ATTR_DRIVER_NAME)!=='pgsql'||$this->db->inTransaction()) {
                throw new RuntimeException('Independent PostgreSQL connection required.');
            }
            $r=$this->db->query("SELECT current_database() AS db,current_user AS actor,session_user AS login,
                rolsuper,rolbypassrls,rolcreatedb,rolcreaterole,rolinherit,
                EXISTS(SELECT 1 FROM pg_auth_members WHERE member=r.oid) AS inherited
                FROM pg_roles r WHERE rolname=current_user")->fetch(PDO::FETCH_ASSOC);
            if (!$r||$r['db']!==$this->database||$r['actor']!=='portal_browser_runtime'||$r['login']!=='portal_browser_runtime') {
                throw new RuntimeException('Restricted Portal principal reader required.');
            }
            foreach (['rolsuper','rolbypassrls','rolcreatedb','rolcreaterole','rolinherit','inherited'] as $flag) {
                if (!in_array($r[$flag]??null,[false,'f',0,'0'],true)) {
                    throw new RuntimeException('Privileged Portal principal reader refused.');
                }
            }
        } catch (Throwable) { throw new RuntimeException('Portal principal observation unavailable.'); }
    }
}
