<?php

declare(strict_types=1);

namespace App\Homologation;

use InvalidArgumentException;
use PDO;
use RuntimeException;

/**
 * CI-only PDO adapter to SQL functions with no direct table grants.
 *
 * All identity parameters are fixture inputs, not authenticated principals.
 * In particular, this class MUST NOT be registered in a production provider.
 */
final class SyntheticCapabilityOrderStore
{
    public function __construct(
        private PDO $database,
        private SyntheticOwnerSqlCapability $issuer,
    ) {
        if (getenv('GITHUB_ACTIONS') !== 'true'
            || getenv('PORTAL_CAPABILITY_CI_ONLY') !== '1') {
            throw new RuntimeException('Synthetic capability adapter is CI-only.');
        }
        if ($database->query('SELECT current_database()')->fetchColumn() !== 'portal_capability_ci'
            || $database->query('SELECT session_user')->fetchColumn() !== 'portal_capability_ci') {
            throw new RuntimeException('No production database or application role allowed.');
        }
    }

    public function create(
        string $organization,
        string $customer,
        string $order,
        string $requestKey,
        string $product,
    ): string {
        if (preg_match('/\ATEST-[a-z0-9-]{8,64}\z/D', $requestKey) !== 1
            || !in_array($product, ['onepage','landing-pages','performance'], true)) {
            throw new InvalidArgumentException('Unsupported synthetic purchase.');
        }
        $scope = $this->issuer->issue('create', $organization, $customer, $order);
        $statement = $this->database->prepare(
            'SELECT portal_capability_lab.create_owned_order(?,?,?,?,?,?,?)'
        );
        $statement->execute([
            $organization,$customer,$order,$requestKey,$product,
            $scope['expiry'],$scope['mac'],
        ]);
        $result=$statement->fetchColumn();
        $statement->closeCursor();
        if (!is_string($result) || $result === '') {
            throw new RuntimeException('Synthetic purchase was not persisted.');
        }
        return $result;
    }

    /** @return list<array{order_id:string,product:string,state:string}> */
    public function list(string $organization, string $customer): array
    {
        $scope = $this->issuer->issue('list',$organization,$customer,'-');
        $statement = $this->database->prepare(
            'SELECT order_id,product,state FROM portal_capability_lab.list_owned_orders(?,?,?,?)'
        );
        $statement->execute([$organization,$customer,$scope['expiry'],$scope['mac']]);
        /** @var list<array{order_id:string,product:string,state:string}> $rows */
        $rows=$statement->fetchAll(PDO::FETCH_ASSOC);
        $statement->closeCursor();
        return $rows;
    }

    public function approve(string $organization,string $customer,string $order): bool
    {
        $scope=$this->issuer->issue('approve',$organization,$customer,$order);
        $statement=$this->database->prepare(
            'SELECT portal_capability_lab.approve_owned_order(?,?,?,?,?)'
        );
        $statement->execute([
            $organization,$customer,$order,$scope['expiry'],$scope['mac'],
        ]);
        $value=$statement->fetchColumn();
        $statement->closeCursor();
        return in_array($value,['t','1',true,1],true);
    }
}
