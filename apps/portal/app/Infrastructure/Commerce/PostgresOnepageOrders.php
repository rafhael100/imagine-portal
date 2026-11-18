<?php
declare(strict_types=1);
namespace App\Infrastructure\Commerce;

use App\Domain\Commerce\OnepageAcceptedOrder;
use App\Domain\Commerce\OnepageOrderStore;
use PDO;
use RuntimeException;
use Throwable;

/** Fixed native namespace and restricted role. No installer, credential source or HTTP registration. */
final readonly class PostgresOnepageOrders implements OnepageOrderStore
{
    private OnepageOrderCommands $commands;

    public function __construct(private PDO $runtime,private string $database)
    {
        if (!preg_match('/\A[a-zA-Z_][a-zA-Z0-9_]{0,62}\z/D',$database)) throw new RuntimeException('Native order database required.');
        $this->commands=new OnepageOrderCommands(function(string $function,array $parameters):mixed {
            $this->ready();
            $arguments=match($function) {
                'command'=>'?,?,?,?,?::jsonb,?::jsonb',
                'review_order','read_pending_order'=>'?,?::uuid',
                'accept_order'=>'?,?,?::uuid,?::jsonb',
                default=>throw new RuntimeException('Unknown native order command.'),
            };
            $q=$this->runtime->prepare('SELECT portal_onepage.'.$function.'('.$arguments.')');
            $q->execute($parameters);
            return $q->fetchColumn();
        });
    }

    public function createDraft(string $businessReference,string $requestKey,array $selection):array
    { return $this->commands->createDraft($businessReference,$requestKey,$selection); }
    public function resumeDraft(string $businessReference,string $draftId):array
    { return $this->commands->resumeDraft($businessReference,$draftId); }
    public function review(string $businessReference,string $quoteId):?array
    { return $this->commands->review($businessReference,$quoteId); }
    public function accept(string $businessReference,string $requestKey,string $quoteId,array $acceptance):array
    { return $this->commands->accept($businessReference,$requestKey,$quoteId,$acceptance); }
    public function findOwnedAcceptedOrder(string $businessReference,string $orderId):?OnepageAcceptedOrder
    {
        try {
            $row=$this->commands->read($businessReference,$orderId);
            return $row===null?null:OnepageAcceptedOrder::fromRow($row);
        } catch (Throwable) { return null; }
    }

    private function ready():void
    {
        if ($this->runtime->getAttribute(PDO::ATTR_DRIVER_NAME)!=='pgsql'||$this->runtime->inTransaction()) {
            throw new RuntimeException('Native order autocommit connection required.');
        }
        $row=$this->runtime->query("SELECT current_database() db,current_user actor,session_user login,
            rolsuper OR rolbypassrls OR rolcreatedb OR rolcreaterole OR rolinherit OR rolreplication
            OR EXISTS(SELECT 1 FROM pg_auth_members WHERE member=r.oid) unsafe,
            current_setting('transaction_isolation') isolation
            FROM pg_roles r WHERE rolname=current_user")->fetch(PDO::FETCH_ASSOC);
        if (!$row||$row['db']!==$this->database||$row['actor']!=='portal_onepage_runtime'
            ||$row['login']!=='portal_onepage_runtime'||$row['isolation']!=='read committed'
            ||!in_array($row['unsafe'],[false,'f',0,'0'],true)) throw new RuntimeException('Restricted native order role required.');
    }

    public function __debugInfo():array { return ['backend'=>'Portal native Onepage orders']; }
    public function __serialize():array { throw new RuntimeException('Order runtime cannot be serialized.'); }
}
