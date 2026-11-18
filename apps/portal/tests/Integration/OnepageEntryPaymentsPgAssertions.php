<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Domain\Commerce\OnepageAcceptedOrder;
use App\Domain\SitesIntegration\{
    AsaasOfficialPaymentReader, AsaasVerifiedSettlementSource, OnepageEntryPaymentBindingSource,
    OnepageEntryPaymentTerms, PortalEd25519EntitlementIssuer, PortalSettlementEvidence,
    SitesCreateProjectEncoder,
};
use App\Infrastructure\Commerce\{
    PostgresOnepageEntryPayments, PostgresOnepageOrders, PostgresPortalSiteContractSource,
};
use Closure;
use DomainException;
use PDO;
use PDOException;
use PHPUnit\Framework\Assert;

/** Adds entry-payment assertions to the SAME native buyer/order PG test. */
final class OnepageEntryPaymentsPgAssertions
{
    public static function run(
        PDO $bootstrap,
        PDO $runtime,
        PDO $publisher,
        Closure $connect,
        OnepageAcceptedOrder $order,
        string $businessReference,
        PostgresOnepageOrders $reader,
    ): void {
        Assert::assertSame('DISPOSABLE_PG18', getenv('PORTAL_NATIVE_ORDER_TEST'));
        Assert::assertSame('portal_native_test_bootstrap', $bootstrap->query('SELECT session_user')->fetchColumn());
        $database = 'portal_native_orders_ci';
        Assert::assertSame($database, $bootstrap->query('SELECT current_database()')->fetchColumn());
        Assert::assertSame(getenv('PORTAL_NATIVE_ORDER_TEST_DATA'), $bootstrap->query("SHOW data_directory")->fetchColumn());
        $bootstrap->exec(file_get_contents(dirname(__DIR__, 2).'/database/security/portal_onepage_entry_payments.sql'));
        $bootstrap->exec('ALTER ROLE portal_onepage_psp_registrar LOGIN');
        $registrar = $connect('portal_onepage_psp_registrar');
        $account = 'native-entry-sandbox'; $customer = 'cus_NativeEntry001'; $payment = 'pay_NativeEntry001';
        $writer = new PostgresOnepageEntryPayments($registrar, $database, $account);
        $bindings = new PostgresOnepageEntryPayments($runtime, $database, $account, $businessReference);
        $contracts = new PostgresPortalSiteContractSource($reader, $businessReference);
        $source = new OnepageEntryPaymentBindingSource($reader, $businessReference, $account, $contracts, $bindings);
        $schedule = OnepageEntryPaymentTerms::schedule($order);
        Assert::assertNotNull($schedule);
        Assert::assertSame([33000, 33000, 33000], array_column($schedule, 'amount_cents'));
        $reference = OnepageEntryPaymentTerms::reference($order, $account, $customer);
        $secondReference = OnepageEntryPaymentTerms::reference($order, $account, $customer, 'setup-2');
        Assert::assertIsString($reference); Assert::assertIsString($secondReference);
        Assert::assertNotSame($reference, $secondReference);

        Assert::assertSame(0, (int) $bootstrap->query('SELECT count(*) FROM portal_onepage.payment_accounts')->fetchColumn());
        Assert::assertSame(0, (int) $bootstrap->query('SELECT count(*) FROM portal_onepage.entry_payment_bindings')->fetchColumn());
        Assert::assertNull($source->findInstallationPayment($order->id));
        Assert::assertSame('denied', $writer->registerCustomer($order->id, $customer));
        $bootstrap->prepare("INSERT INTO portal_onepage.payment_accounts(account_ref,environment) VALUES(?,'sandbox')")->execute([$account]);
        Assert::assertSame('denied', $writer->registerCustomer($order->id, $customer));
        $bootstrap->prepare('UPDATE portal_onepage.payment_accounts SET active=true WHERE account_ref=?')->execute([$account]);
        Assert::assertSame('recorded', $writer->registerCustomer($order->id, $customer));
        Assert::assertSame('replay', $writer->registerCustomer($order->id, $customer));
        Assert::assertSame('denied', $writer->registerCustomer($order->id, 'cus_OtherBuyer001'));

        $writeSql = 'SELECT portal_onepage.register_entry_payment(?::uuid,?,?,?,?,?,?::date,?)';
        $args = [$order->id, $account, $payment, $customer, $reference, 33000, $schedule[0]['due_date'], 'CREDIT_CARD'];
        foreach ([
            [4 => $secondReference], [3 => 'cus_OtherBuyer001'], [5 => 99000],
            [5 => 32999], [6 => $schedule[1]['due_date']], [0 => '00000000-0000-4000-8000-000000000099'],
            [1 => 'unregistered-sandbox-account'],
        ] as $changes) {
            $q = $registrar->prepare($writeSql); $q->execute(array_replace($args, $changes));
            Assert::assertSame('denied', $q->fetchColumn(), 'SQL refuses a different expected payment context');
        }
        Assert::assertSame('denied', $writer->registerEntry($order->id, $payment, $customer,
            $secondReference, 33000, $schedule[0]['due_date'], 'CREDIT_CARD'));
        Assert::assertSame(0, (int) $bootstrap->query('SELECT count(*) FROM portal_onepage.entry_payment_bindings')->fetchColumn());

        foreach (['payment_accounts', 'payment_customers', 'entry_payment_bindings', 'entry_payment_revocations'] as $table) {
            self::denySql($runtime, 'SELECT * FROM portal_onepage.'.$table);
            self::denySql($registrar, 'SELECT * FROM portal_onepage.'.$table);
        }
        self::denySql($runtime, $writeSql, $args);
        self::denySql($runtime, 'SET ROLE portal_onepage_psp_registrar');
        self::denySql($registrar, 'SET ROLE portal_onepage_payment_owner');
        Assert::assertSame('unavailable', (new PostgresOnepageEntryPayments($runtime, $database, $account))
            ->registerCustomer($order->id, $customer));
        Assert::assertNull((new PostgresOnepageEntryPayments($registrar, $database, $account, $businessReference))
            ->findInstallationPayment($order->id));

        // Class APIs refuse pre-existing transactions; the underlying SQL append
        // is also invisible before COMMIT and absent after a rollback.
        $registrar->beginTransaction();
        Assert::assertSame('unavailable', $writer->registerEntry($order->id, $payment, $customer,
            $reference, 33000, $schedule[0]['due_date'], 'CREDIT_CARD'));
        $q = $registrar->prepare($writeSql); $q->execute($args); Assert::assertSame('recorded', $q->fetchColumn());
        Assert::assertNull($source->findInstallationPayment($order->id));
        $registrar->rollBack();
        Assert::assertSame(0, (int) $bootstrap->query('SELECT count(*) FROM portal_onepage.entry_payment_bindings')->fetchColumn());
        Assert::assertSame('recorded', $writer->registerEntry($order->id, $payment, $customer,
            $reference, 33000, $schedule[0]['due_date'], 'CREDIT_CARD'));
        Assert::assertSame('replay', $writer->registerEntry($order->id, $payment, $customer,
            $reference, 33000, $schedule[0]['due_date'], 'CREDIT_CARD'));
        Assert::assertSame('denied', $writer->registerEntry($order->id, 'pay_AnotherEntry001', $customer,
            $reference, 33000, $schedule[0]['due_date'], 'CREDIT_CARD'));
        $fresh = new PostgresOnepageEntryPayments($connect('portal_onepage_runtime'), $database, $account, $businessReference);
        $binding = $fresh->findInstallationPayment($order->id);
        Assert::assertNotNull($binding);
        Assert::assertSame($order->companyReference, $binding->tenantRef);
        Assert::assertNotSame($order->companyId, $binding->tenantRef);
        Assert::assertSame($reference, $binding->externalReference, 'PHP and native SQL reference algorithms agree');
        Assert::assertSame(33000, $binding->amountCents);
        Assert::assertNull((new PostgresOnepageEntryPayments($runtime, $database, 'other-account', $businessReference))
            ->findInstallationPayment($order->id));

        $others = $bootstrap->prepare('SELECT session_sha256 FROM public.portal_auth_sessions WHERE identity_id<>?');
        $others->execute([$order->buyerId]);
        foreach ($others->fetchAll(PDO::FETCH_COLUMN) as $notOwner) {
            $q = $runtime->prepare('SELECT portal_onepage.read_entry_payment(?,?::uuid,?)');
            $q->execute([$notOwner, $order->id, $account]); Assert::assertNull($q->fetchColumn());
        }
        self::denySql($bootstrap, "UPDATE portal_onepage.entry_payment_bindings SET amount_cents=33000", [], 'P0001');
        self::denySql($bootstrap, 'DELETE FROM portal_onepage.payment_customers', [], 'P0001');
        $runtime->beginTransaction(); Assert::assertNull($bindings->findInstallationPayment($order->id)); $runtime->rollBack();
        $runtime->exec("SET default_transaction_isolation='repeatable read'");
        Assert::assertNull($bindings->findInstallationPayment($order->id));
        $runtime->exec("SET default_transaction_isolation='read committed'");

        $official = new class implements AsaasOfficialPaymentReader {
            public array $record = [];
            public ?Closure $duringRead = null;
            public function fetchPayment(string $paymentId): ?array
            {
                if ($this->duringRead !== null) ($this->duringRead)();
                return ($this->record['id'] ?? null) === $paymentId ? $this->record : null;
            }
        };
        $official->record = ['id' => $payment, 'customer' => $customer, 'externalReference' => $reference,
            'billingType' => 'CREDIT_CARD', 'status' => 'RECEIVED', 'value' => '330.00', 'deleted' => false,
            'refunds' => [], 'subscription' => null];
        $settlements = new AsaasVerifiedSettlementSource($source, $official);
        Assert::assertInstanceOf(PortalSettlementEvidence::class, $settlements->findCurrentSettledOrder($order->id));
        $official->record['status'] = 'CONFIRMED';
        Assert::assertNull($settlements->findCurrentSettledOrder($order->id));
        $official->record['status'] = 'RECEIVED';
        $official->record['customer'] = 'cus_OtherBuyer001';
        Assert::assertNull($settlements->findCurrentSettledOrder($order->id));
        $official->record['customer'] = $customer;
        $command = (new SitesCreateProjectEncoder($contracts))->build($order->id, 'native-entry-command', 'native-entry-key');
        Assert::assertSame('1', $command['schemaVersion']); Assert::assertSame($order->companyReference, $command['tenantRef']);
        Assert::assertArrayNotHasKey('paid', $command); Assert::assertArrayNotHasKey('canPublish', $command);
        try {
            (new PortalEd25519EntitlementIssuer($contracts, $settlements))->issue($order->id,
                'native-entry-command', 'native-entry-key', time(), sodium_crypto_sign_secretkey(sodium_crypto_sign_keypair()));
            Assert::fail('Missing independent revocation authority must deny signing.');
        } catch (DomainException) { Assert::assertTrue(true); }

        // Changing the native binding during the official read must invalidate
        // this very proof, not merely the next request. Revocation is terminal.
        $official->duringRead = static function () use ($writer, $order): void {
            Assert::assertSame('recorded', $writer->revokeEntry($order->id, 'source-revoked-during-read'));
        };
        Assert::assertNull($settlements->findCurrentSettledOrder($order->id));
        $official->duringRead = null;
        Assert::assertNull($source->findInstallationPayment($order->id));
        Assert::assertSame('replay', $writer->revokeEntry($order->id, 'source-revoked-during-read'));
        Assert::assertSame('denied', $writer->registerEntry($order->id, $payment, $customer,
            $reference, 33000, $schedule[0]['due_date'], 'CREDIT_CARD'));
        self::denySql($bootstrap, 'DELETE FROM portal_onepage.entry_payment_revocations', [], 'P0001');
        Assert::assertSame(1, (int) $bootstrap->query('SELECT count(*) FROM portal_onepage.entry_payment_bindings')->fetchColumn());
        Assert::assertSame(1, (int) $bootstrap->query('SELECT count(*) FROM portal_onepage.pending_orders')->fetchColumn());
        Assert::assertSame('pending_payment', $bootstrap->query('SELECT status FROM portal_onepage.pending_orders')->fetchColumn());
    }

    private static function denySql(PDO $connection, string $sql, array $args = [], string $state = '42501'): void
    {
        try {
            $q = $connection->prepare($sql); $q->execute($args); Assert::fail('Expected native SQL refusal.');
        } catch (PDOException $error) { Assert::assertSame($state, $error->getCode()); }
    }
}
