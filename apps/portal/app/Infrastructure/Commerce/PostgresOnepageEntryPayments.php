<?php

declare(strict_types=1);

namespace App\Infrastructure\Commerce;

use App\Domain\SitesIntegration\AsaasTrustedPaymentBinding;
use App\Domain\SitesIntegration\AsaasTrustedPaymentBindingSource;
use PDO;
use RuntimeException;
use Throwable;

/**
 * Native expected-payment persistence, not a charge creator or settlement source.
 * Reader and registrar use DIFFERENT native database logins; this class never
 * switches roles, installs schema, enrolls accounts or obtains credentials.
 */
final readonly class PostgresOnepageEntryPayments implements AsaasTrustedPaymentBindingSource
{
    public function __construct(
        private PDO $connection,
        private string $database,
        private string $sandboxAccount,
        #[\SensitiveParameter] private ?string $businessReference = null,
    ) {
        if (!preg_match('/\A[a-zA-Z_][a-zA-Z0-9_]{0,62}\z/D', $database)
            || !preg_match('/\A[A-Za-z0-9][A-Za-z0-9._:-]{0,95}\z/D', $sandboxAccount)) {
            throw new RuntimeException('Native payment binding configuration required.');
        }
    }

    public function findInstallationPayment(string $orderRef): ?AsaasTrustedPaymentBinding
    {
        if ($this->businessReference === null || !preg_match('/\A[a-f0-9]{64}\z/D', $this->businessReference)
            || !OnepageOrderCommands::uuid($orderRef)) return null;
        try {
            $this->ready('portal_onepage_runtime');
            $q = $this->connection->prepare('SELECT portal_onepage.read_entry_payment(?,?::uuid,?)');
            $q->execute([hash('sha256', $this->businessReference), $orderRef, $this->sandboxAccount]);
            $raw = $q->fetchColumn();
            if (!is_string($raw)) return null;
            $row = json_decode($raw, true, 32, JSON_THROW_ON_ERROR);
            if (!is_array($row)) return null;
            return new AsaasTrustedPaymentBinding(
                $row['tenant_ref'], $row['order_ref'], $row['contract_ref'], $row['contract_revision'],
                $row['product_code'], $row['payment_id'], $row['customer_id'], $row['external_reference'],
                $row['amount_cents'], $row['billing_type'],
            );
        } catch (Throwable) {
            return null;
        }
    }

    /** Register the trusted worker's PSP customer receipt for this order's company. */
    public function registerCustomer(string $orderRef, string $customerId): string
    {
        if (!OnepageOrderCommands::uuid($orderRef) || !preg_match('/\Acus_[A-Za-z0-9]{6,64}\z/D', $customerId)) return 'denied';
        return $this->write('register_payment_customer', '?::uuid,?,?', [$orderRef, $this->sandboxAccount, $customerId]);
    }

    /**
     * Registers identifiers from a trusted PSP creation receipt. SQL derives the
     * entire accepted order scope and validates its entry reference/value/date.
     * Returning recorded/replay never means received funds or a release decision.
     */
    public function registerEntry(
        string $orderRef,
        string $paymentId,
        string $customerId,
        string $externalReference,
        int $amountCents,
        string $dueDate,
        string $billingType,
    ): string {
        if (!OnepageOrderCommands::uuid($orderRef)
            || !preg_match('/\Apay_[A-Za-z0-9]{6,64}\z/D', $paymentId)
            || !preg_match('/\Acus_[A-Za-z0-9]{6,64}\z/D', $customerId)
            || !preg_match('/\Aimagine:onepage:setup-1:[a-f0-9]{64}\z/D', $externalReference)
            || !preg_match('/\A[0-9]{4}-[0-9]{2}-[0-9]{2}\z/D', $dueDate)
            || $amountCents < 1 || !in_array($billingType, ['PIX', 'CREDIT_CARD', 'BOLETO'], true)) return 'denied';
        return $this->write('register_entry_payment', '?::uuid,?,?,?,?,?,?::date,?', [
            $orderRef, $this->sandboxAccount, $paymentId, $customerId,
            $externalReference, $amountCents, $dueDate, $billingType,
        ]);
    }

    public function revokeEntry(string $orderRef, string $reasonReference): string
    {
        if (!OnepageOrderCommands::uuid($orderRef)
            || !preg_match('/\A[A-Za-z0-9][A-Za-z0-9._:-]{0,127}\z/D', $reasonReference)) return 'denied';
        return $this->write('revoke_entry_payment', '?::uuid,?', [$orderRef, $reasonReference]);
    }

    private function write(string $function, string $arguments, array $parameters): string
    {
        try {
            $this->ready('portal_onepage_psp_registrar');
            $q = $this->connection->prepare('SELECT portal_onepage.'.$function.'('.$arguments.')');
            $q->execute($parameters);
            $result = $q->fetchColumn();
            return in_array($result, ['recorded', 'replay', 'denied'], true) ? $result : 'unavailable';
        } catch (Throwable) {
            return 'unavailable';
        }
    }

    private function ready(string $role): void
    {
        if ($this->connection->getAttribute(PDO::ATTR_DRIVER_NAME) !== 'pgsql' || $this->connection->inTransaction()) {
            throw new RuntimeException('Native payment autocommit connection required.');
        }
        $row = $this->connection->query("SELECT current_database() db,current_user actor,session_user login,
            rolsuper OR rolbypassrls OR rolcreatedb OR rolcreaterole OR rolinherit OR rolreplication
            OR EXISTS(SELECT 1 FROM pg_auth_members WHERE member=r.oid) unsafe,
            current_setting('transaction_isolation') isolation
            FROM pg_roles r WHERE rolname=current_user")->fetch(PDO::FETCH_ASSOC);
        if (!$row || $row['db'] !== $this->database || $row['actor'] !== $role || $row['login'] !== $role
            || $row['isolation'] !== 'read committed' || !in_array($row['unsafe'], [false, 'f', 0, '0'], true)) {
            throw new RuntimeException('Restricted native payment role required.');
        }
    }

    public function __debugInfo(): array { return ['backend' => 'Portal native expected entry payment']; }
    public function __serialize(): array { throw new RuntimeException('Payment runtime cannot be serialized.'); }
}
