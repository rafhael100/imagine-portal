<?php
declare(strict_types=1);

namespace App\Domain\SeoReview;

use Closure;
use PDO;
use Throwable;

/**
 * Z: scope PostgreSQL RLS context to one transaction, never the connection.
 *
 * This helper DOES NOT verify login, MFA, membership or Google permissions.
 * Tenant+actor MUST come from a future independently authenticated backend,
 * not from the query string, callback parameters or browser.
 */
final class GoogleSearchConsoleTenantScopedTransaction
{
    public function __construct(private readonly PDO $pdo) {}

    /**
     * Execute only with a non-bypass PostgreSQL role and trusted context.
     * Returns null on any failure; never leaks PDO exception contents.
     */
    public function run(mixed $trustedContext, Closure $operation):mixed
    {
        if (!self::validContext($trustedContext) ||
            $this->pdo->inTransaction()) return null;
        try {
            if ($this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME)!=='pgsql')
                return null;
            $role=$this->pdo->query(
                'SELECT rolsuper, rolbypassrls FROM pg_roles
                 WHERE rolname = current_user'
            )->fetch(PDO::FETCH_ASSOC);
            if (!is_array($role) ||
                !self::isPgFalse($role['rolsuper']??null) ||
                !self::isPgFalse($role['rolbypassrls']??null))return null;

            if (!$this->pdo->beginTransaction())return null;
            $stmt=$this->pdo->prepare(
                "SELECT set_config('app.tenant_ref', :tenant, true)"
            );
            if (!$stmt ||
                !$stmt->execute(['tenant'=>$trustedContext['tenantRef']]))
                throw new \RuntimeException('GSC transaction scope unavailable');
            $set=$stmt->fetchColumn();
            if ($set!==$trustedContext['tenantRef'])
                throw new \RuntimeException('GSC transaction scope refused');
            $out=$operation($this->pdo);
            if (!$this->pdo->inTransaction() || !$this->pdo->commit())
                return null;
            return $out;
        }catch(Throwable) {
            if($this->pdo->inTransaction()) {
                try{$this->pdo->rollBack();}catch(Throwable){}
            }
            return null;
        }
    }

    public static function authorizesPortalMembership():bool{return false;}
    public static function authorizesGoogleHttp():bool{return false;}
    public static function authorizesCustomerDisplay():bool{return false;}

    private static function isPgFalse(mixed $value):bool
    {
        return in_array($value,[false,'f','0',0],true);
    }
    private static function validContext(mixed $context):bool
    {
        if(!is_array($context)||array_is_list($context)||
            count($context)!==2 ||
            !array_key_exists('tenantRef',$context) ||
            !array_key_exists('actorRef',$context))return false;
        foreach(['tenantRef','actorRef'] as $key) {
            $ref=$context[$key];
            if(!is_string($ref) ||
                preg_match('/\A[a-z0-9][a-z0-9_-]{2,63}\z/D',$ref)!==1)
                return false;
        }
        return true;
    }
}
