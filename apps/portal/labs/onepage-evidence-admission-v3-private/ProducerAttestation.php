<?php
declare(strict_types=1);
/** Fixture producer identity. Never aliases the producer to a buyer runtime. */
final class AdmissionProducerAttestation
{
    public static function facts(array $row,array $seen,array $cluster,string $nonce): void
    {
        $role='onepage_fixture_evidence_producer';
        if (($row['db']??null)!=='portal_onepage_buyer_ci' || ($row['db']??null)!==($cluster['db']??null)
            || ($row['actor']??null)!==$role || ($row['login']??null)!==$role
            || ($row['host']??null)!=='127.0.0.1' || (int)($row['port']??0)!==55439
            || (string)($row['started']??'')!==(string)($cluster['started']??'')
            || !in_array($row['unsafe']??null,[false,'f',0,'0'],true)
            || (int)($row['pid']??0)<1 || (int)($row['client_port']??0)<1
            || (int)($seen['pid']??0)!==(int)$row['pid'] || ($seen['usename']??null)!==$role
            || ($seen['datname']??null)!==$row['db'] || ($seen['client_addr']??null)!=='127.0.0.1'
            || (int)($seen['client_port']??0)!==(int)$row['client_port']
            || !is_string($row['backend_start']??null) || $row['backend_start']===''
            || ($seen['backend_start']??null)!==$row['backend_start']
            || !preg_match('/\Aadmission-attest-[a-f0-9]{32}\z/D',$nonce)
            || ($seen['application_name']??null)!==$nonce)
            throw new RuntimeException('ADMISSION_PRODUCER_IDENTITY_REFUSED');
    }
    public static function check(PDO $producer,PDO $auditor,array $cluster,Closure $barrier): void
    {
        $barrier();
        if ($producer===$auditor || $producer->inTransaction() || $auditor->inTransaction()
            || $producer->getAttribute(PDO::ATTR_DRIVER_NAME)!=='pgsql')
            throw new RuntimeException('ADMISSION_PRODUCER_IDENTITY_REFUSED');
        $nonce='admission-attest-'.bin2hex(random_bytes(16));
        $q=$producer->prepare("SELECT set_config('application_name',?,false)");$q->execute([$nonce]);
        $row=$producer->query("SELECT current_database() db,current_user actor,session_user login,
          host(inet_server_addr()) host,inet_server_port() port,pg_backend_pid() pid,inet_client_port() client_port,
          extract(epoch FROM pg_postmaster_start_time())::bigint started,
          (SELECT backend_start::text FROM pg_stat_activity WHERE pid=pg_backend_pid()) backend_start,
          rolsuper OR rolbypassrls OR rolcreatedb OR rolcreaterole OR rolinherit OR rolreplication
          OR EXISTS(SELECT 1 FROM pg_auth_members WHERE member=r.oid) unsafe
          FROM pg_roles r WHERE rolname=current_user")->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row)) throw new RuntimeException('ADMISSION_PRODUCER_IDENTITY_REFUSED');
        $q=$auditor->prepare('SELECT pid,usename,datname,host(client_addr) client_addr,client_port,backend_start::text,application_name FROM pg_stat_activity WHERE pid=?');
        $q->execute([$row['pid']]);$seen=$q->fetch(PDO::FETCH_ASSOC);
        self::facts($row,is_array($seen)?$seen:[],$cluster,$nonce);$barrier();
    }
}
