<?php

declare(strict_types=1);

namespace App\Infrastructure\Identity;

use Illuminate\Contracts\Encryption\Encrypter;
use PDO;
use RuntimeException;
use SessionHandlerInterface;
use Throwable;

/**
 * Candidate server-side envelope handler. Always encrypts, independently of
 * Laravel session.encrypt. Dedicated PDO/role, never shared with identity PDO.
 * The transaction and advisory lock span read -> final write/commit.
 * No application driver is registered by this class.
 */
final class EncryptedPostgresPortalSessionHandler implements SessionHandlerInterface
{
    private const BINDING = "IMAGINE-PORTAL-ENVELOPE-V1\n";

    private bool $owned = false;

    public function __construct(
        private readonly PDO $db,
        private readonly string $database,
        private readonly Encrypter $cipher,
        private readonly int $lockWaitMilliseconds = 2000,
    ) {
        if ($lockWaitMilliseconds < 100 || $lockWaitMilliseconds > 5000) {
            throw new RuntimeException('Bounded session lock wait required.');
        }
        $this->ready();
    }

    public function __debugInfo(): array
    {
        return ['backend'=>'encrypted Portal PostgreSQL envelopes'];
    }

    // F289: cloning would duplicate the ownership flag while retaining the
    // *same* PDO connection. The clone's destructor could roll back an active
    // session transaction owned by the original request.
    // Private visibility rejects clone before the copy is constructed.
    private function __clone(): void
    {
    }

    public function __serialize(): array
    {
        throw new RuntimeException('Session handlers cannot be serialized.');
    }

    public function open(string $path, string $name): bool
    {
        return true;
    }

    public function close(): bool
    {
        $this->rollback();
        return true;
    }

    public function read(string $id): string|false
    {
        try {
            $this->start();
            $digest=$this->digest($id);
            $payload=$this->query('SELECT public.portal_browser_envelope_read(?)',[$digest]);
            if ($payload === '') {
                return '';
            }
            $plain=$this->cipher->decrypt($payload,false);
            $binding=self::BINDING.$digest."\n";
            if (!is_string($plain) || strlen($plain)<strlen($binding)
                || !hash_equals($binding,substr($plain,0,strlen($binding)))) {
                throw new RuntimeException('Invalid session payload binding.');
            }
            return substr($plain,strlen($binding));
        } catch (Throwable) {
            $this->rollback();
            throw new RuntimeException('Portal session storage unavailable.');
        }
    }

    public function write(string $id, string $data): bool
    {
        try {
            $this->start();
            $digest=$this->digest($id);
            $payload=$this->cipher->encrypt(self::BINDING.$digest."\n".$data,false);
            if (strlen($payload)>65536 || !$this->truth($this->query(
                'SELECT public.portal_browser_envelope_write(?,?)',[$digest,$payload]))) {
                throw new RuntimeException('Session write refused.');
            }
            $this->db->commit();
            $this->owned=false;
            return true;
        } catch (Throwable) {
            $this->rollback();
            throw new RuntimeException('Portal session storage unavailable.');
        }
    }

    public function destroy(string $id): bool
    {
        $alreadyOwned=$this->owned;
        try {
            $this->start();
            if (!$this->truth($this->query('SELECT public.portal_browser_envelope_destroy(?)',[$this->digest($id)]))) {
                throw new RuntimeException('Session destruction refused.');
            }
            // Rotation during a request deletes old ID and writes new ID in one transaction.
            if (!$alreadyOwned) {
                $this->db->commit();
                $this->owned=false;
            }
            return true;
        } catch (Throwable) {
            $this->rollback();
            throw new RuntimeException('Portal session storage unavailable.');
        }
    }

    public function gc(int $max_lifetime): int|false
    {
        $alreadyOwned=$this->owned;
        try {
            $this->start();
            $count=(int)$this->query('SELECT public.portal_browser_envelope_gc()',[]);
            if (!$alreadyOwned) {
                $this->db->commit();
                $this->owned=false;
            }
            return $count;
        } catch (Throwable) {
            $this->rollback();
            throw new RuntimeException('Portal session storage unavailable.');
        }
    }

    public function __destruct()
    {
        $this->rollback();
    }

    private function start(): void
    {
        if ($this->owned) {
            if (!$this->db->inTransaction()) {
                $this->owned=false;
                throw new RuntimeException('Lost session transaction.');
            }
            return;
        }
        $this->ready();
        $this->db->beginTransaction();
        $this->owned=true;
        $this->db->exec("SET LOCAL lock_timeout = '".$this->lockWaitMilliseconds."ms'");
        $this->db->exec("SET LOCAL statement_timeout = '5s'");
        $this->db->exec("SET LOCAL idle_in_transaction_session_timeout = '30s'");
    }

    private function ready(): void
    {
        if ($this->db->getAttribute(PDO::ATTR_DRIVER_NAME)!=='pgsql' || $this->db->inTransaction()) {
            throw new RuntimeException('Dedicated autocommit session connection required.');
        }
        $q=$this->db->query("SELECT current_database() AS db,current_user AS actor,session_user AS login,
          rolsuper,rolbypassrls,rolcreatedb,rolcreaterole,rolinherit,
          EXISTS(SELECT 1 FROM pg_auth_members WHERE member=r.oid) AS inherited
          FROM pg_roles r WHERE rolname=current_user");
        $row=$q->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row)||$row['db']!==$this->database
            ||$row['actor']!=='portal_browser_envelope_runtime'||$row['login']!=='portal_browser_envelope_runtime') {
            throw new RuntimeException('Dedicated Portal envelope role required.');
        }
        foreach(['rolsuper','rolbypassrls','rolcreatedb','rolcreaterole','rolinherit','inherited']as$field) {
            if($this->truth($row[$field]))throw new RuntimeException('Privileged session role refused.');
        }
    }

    private function digest(string $id): string
    {
        if(preg_match('/\\A[A-Za-z0-9]{40}\\z/D',$id)!==1)throw new RuntimeException('Invalid session identifier.');
        return hash('sha256',$id);
    }

    private function query(string $sql,array $parameters): mixed
    {
        $q=$this->db->prepare($sql);
        $q->execute($parameters);
        return $q->fetchColumn();
    }

    private function truth(mixed $value): bool
    {
        return in_array($value,[true,1,'1','t'],true);
    }

    private function rollback(): void
    {
        if($this->owned) {
            try {
                if($this->db->inTransaction())$this->db->rollBack();
            }catch(Throwable){
                // Destructor/close never expose a database diagnostic or commit partial state.
            }
            $this->owned=false;
        }
    }
}
