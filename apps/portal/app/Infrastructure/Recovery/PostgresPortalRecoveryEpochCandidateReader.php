<?php
declare(strict_types=1);

namespace App\Infrastructure\Recovery;

use PDO;
use RuntimeException;
use Throwable;

/**
 * F374: read-only, server-scoped, QUARANTINED PostgreSQL recovery epoch reader.
 * No application/browser grants, no HTTP route, no serving authorization.
 * Production credential/role conversion remains unapproved.
 */
final readonly class PostgresPortalRecoveryEpochCandidateReader implements PortalLiveRecoveryEpochSourceCandidate
{
    public function __construct(
        private PDO $db,
        private string $expectedDatabase,
        private string $organization,
        private string $instance,
    ) {
        if(preg_match('/\A[0-9a-f]{8}-[0-9a-f]{4}-[1-8][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}\z/D',$organization)!==1
            ||preg_match('/\A[A-Za-z0-9_-]{1,64}\z/D',$instance)!==1) {
            throw new RuntimeException('Portal recovery epoch scope invalid.');
        }
        $this->ready();
    }

    public function currentEpoch(): array
    {
        $this->ready();
        try {
            $q=$this->db->prepare(
                "SELECT organization_id,instance,generation,session_revision,
                        membership_revision,witnessed_sequence,state
                   FROM public.portal_recovery_epoch_candidates
                  WHERE organization_id=CAST(? AS uuid) AND instance=?"
            );
            $q->execute([$this->organization,$this->instance]);
            $rows=$q->fetchAll(PDO::FETCH_ASSOC);
            if(count($rows)!==1 || $rows[0]['state']!=='quarantined') {
                throw new RuntimeException();
            }
            $r=$rows[0];
            if($r['organization_id']!==$this->organization || $r['instance']!==$this->instance) {
                throw new RuntimeException();
            }
            $numbers=[];
            foreach(['generation','session_revision','membership_revision','witnessed_sequence'] as $key){
                if(!is_string($r[$key]) && !is_int($r[$key]))throw new RuntimeException();
                $raw=(string)$r[$key];
                if(preg_match('/\A[1-9][0-9]{0,9}\z/D',$raw)!==1)throw new RuntimeException();
                $numbers[$key]=(int)$raw;
                if($numbers[$key]>1000000000)throw new RuntimeException();
            }
            return [
                'organization'=>$r['organization_id'],
                'instance'=>$r['instance'],
                'generation'=>$numbers['generation'],
                'session_revision'=>$numbers['session_revision'],
                'membership_revision'=>$numbers['membership_revision'],
                'witnessed_sequence'=>$numbers['witnessed_sequence'],
            ];
        }catch(Throwable){
            throw new RuntimeException('Portal recovery epoch unavailable.');
        }
    }

    private function ready(): void
    {
        try{
            if($this->db->getAttribute(PDO::ATTR_DRIVER_NAME)!=='pgsql'
                ||$this->db->inTransaction())throw new RuntimeException();
            $q=$this->db->query(
                "SELECT current_database() AS db,current_user AS actor,
                        session_user AS login,
                        rolsuper,rolbypassrls,rolcreatedb,rolcreaterole,rolinherit,
                        rolcanlogin,
                        EXISTS(SELECT 1 FROM pg_auth_members WHERE member=r.oid) AS inherited
                   FROM pg_roles r WHERE rolname=current_user"
            );
            $r=$q->fetch(PDO::FETCH_ASSOC);
            if(!is_array($r)||$r['db']!==$this->expectedDatabase
                ||$r['actor']!=='portal_recovery_epoch_reader'
                ||!($r['login']==='portal_recovery_epoch_reader'
                     ||($r['login']==='postgres'
                        && getenv('GITHUB_ACTIONS')==='true'
                        && getenv('RUNNER_ENVIRONMENT')==='github-hosted'
                        && getenv('PORTAL_F374_CI')==='DISPOSABLE_ONLY'))) {
                throw new RuntimeException();
            }
            foreach(['rolsuper','rolbypassrls','rolcreatedb','rolcreaterole','rolinherit','rolcanlogin','inherited'] as $key) {
                if(in_array($r[$key],[true,'t','1',1],true))throw new RuntimeException();
            }
            $rls=$this->db->query(
                "SELECT count(*) FROM pg_class c JOIN pg_namespace n ON n.oid=c.relnamespace
                  WHERE n.nspname='public' AND c.relname='portal_recovery_epoch_candidates'
                    AND c.relrowsecurity AND c.relforcerowsecurity"
            )->fetchColumn();
            if((int)$rls!==1)throw new RuntimeException();
            $q=$this->db->query(
                "SELECT has_table_privilege('portal_recovery_epoch_reader',
                  'public.portal_recovery_epoch_candidates','UPDATE,INSERT,DELETE,TRUNCATE')"
            );
            if(in_array($q->fetchColumn(),[true,'t','1',1],true))throw new RuntimeException();
        }catch(Throwable){
            throw new RuntimeException('Portal recovery epoch unavailable.');
        }
    }
}
