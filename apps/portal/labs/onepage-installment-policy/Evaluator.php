<?php
declare(strict_types=1);

namespace Imagine\Lab\OnepageInstallmentPolicy;

use App\Homologation\Commerce\ApprovedOfferCatalog;
use App\Homologation\Commerce\BillingSchedule;
use DateTimeImmutable;
use Throwable;

require_once __DIR__.'/../../app/Homologation/Commerce/ApprovedOfferCatalog.php';
require_once __DIR__.'/../../app/Homologation/Commerce/BillingSchedule.php';
require_once __DIR__.'/CalendarArrears.php';

enum Outcome
{
    case REVIEW_UNVERIFIED;       // no new authority and no finding of delinquency
    case WAIT_FOR_ENTRY;
    case REVIEW_INITIAL_ACTIVATION_GRACE;
    case INITIAL_FINANCIAL_CONDITION_MET;
    case NO_FINANCIAL_BLOCK_ON_EXISTING_RELEASE;
    case VERIFIED_OVERDUE_MORE_THAN_SEVEN_DAYS;
}

/** Pure, unregistered advisory. Neither a proof verifier nor a release capability. */
final class Evaluator
{
    /**
     * Caller must obtain order and prior-release state from native authorities,
     * immutable schedule independently, and complete current verified evidence
     * for the same instant from reconciliation. Never invoke from request data.
     * This class checks consistency; an array cannot substantiate its own origin.
     *
     * Scope: order_ref, tenant_ref, company_ref, contract_ref, contract_revision,
     * product_code, offer_digest and catalog_version. Use canonical native refs;
     * this class does not infer that a company ID is a Sites tenant ID.
     *
     * Schedule: scope, rows (native immutable setup items).
     * Evidence: scope, as_of (DateTimeImmutable), rows, one per setup item.
     * Each evidence row carries scope, key, due_date, amount_cents, state,
     * evidence_ref, payment_ref, paid_at and exception_state (no_exception_reported
     * or exception_claimed). verified_paid means the full item
     * has a current confirmed, non-reversed payment; verified_unpaid includes
     * confirmed open/not-fully-paid status. Unverified or conflicting is unknown.
     *
     * Optional effective reference must come from a separate verified source:
     * scope, as_of, rows (key, contractual_due_date, effective_arrears_date).
     * A supplied projection must match that day's evidence; it never alters the
     * underlying contractual due date. This is not a source authenticator.
     */
    public static function evaluate(
        array $scope,
        DateTimeImmutable $acceptedAt,
        DateTimeImmutable $asOf,
        bool $priorRelease,
        array $schedule,
        array $evidence,
        ?array $effectiveReference = null,
    ): Outcome {
        try {
            return self::evaluateChecked($scope, $acceptedAt, $asOf, $priorRelease, $schedule, $evidence, $effectiveReference);
        } catch (Throwable) {
            return Outcome::REVIEW_UNVERIFIED;
        }
    }

    private static function evaluateChecked(
        array $scope,
        DateTimeImmutable $acceptedAt,
        DateTimeImmutable $asOf,
        bool $priorRelease,
        array $schedule,
        array $evidence,
        ?array $effectiveReference,
    ): Outcome {
        $unknown = Outcome::REVIEW_UNVERIFIED;
        $catalog = new ApprovedOfferCatalog();
        if (!self::keys($scope, ['order_ref','tenant_ref','company_ref','contract_ref','contract_revision',
            'product_code','offer_digest','catalog_version'])
            || $scope['product_code'] !== 'onepage' || $scope['catalog_version'] !== $catalog->version()
            || !is_int($scope['contract_revision']) || $scope['contract_revision'] < 1
            || !is_string($scope['offer_digest']) || preg_match('/\A[a-f0-9]{64}\z/D', $scope['offer_digest']) !== 1
            || $acceptedAt > $asOf
            || !self::keys($schedule, ['scope','rows']) || !self::same($schedule['scope'], $scope)
            || !self::keys($evidence, ['scope','as_of','rows']) || !self::same($evidence['scope'], $scope)
            || !$evidence['as_of'] instanceof DateTimeImmutable || $evidence['as_of'] != $asOf
            || !is_array($evidence['rows']) || !array_is_list($evidence['rows']) || count($evidence['rows']) !== 3) {
            return $unknown;
        }
        foreach (['order_ref','tenant_ref','company_ref','contract_ref'] as $field) {
            if (!self::ref($scope[$field])) return $unknown;
        }

        // Reuse the pinned catalog and projection; never recreate missing native terms.
        $projected = BillingSchedule::project($catalog->quote('onepage'), $acceptedAt->format(DATE_ATOM), 1);
        $setup = array_values(array_filter($projected, static fn(array $row): bool => $row['kind'] === 'setup'));
        if ($schedule['rows'] !== $setup || array_column($setup, 'key') !== ['setup-1','setup-2','setup-3']) {
            return $unknown;
        }

        $byKey = array_column($setup, null, 'key');
        $effective = [];
        if ($effectiveReference !== null) {
            if (!self::keys($effectiveReference, ['scope','as_of','rows'])
                || !self::same($effectiveReference['scope'], $scope)
                || !$effectiveReference['as_of'] instanceof DateTimeImmutable
                || $effectiveReference['as_of'] != $asOf
                || !is_array($effectiveReference['rows']) || !array_is_list($effectiveReference['rows'])
                || count($effectiveReference['rows']) < 1 || count($effectiveReference['rows']) > count($byKey)) {
                return $unknown;
            }
            foreach ($effectiveReference['rows'] as $reference) {
                if (!self::keys($reference, ['key','contractual_due_date','effective_arrears_date'])
                    || !is_string($reference['key']) || !isset($byKey[$reference['key']])
                    || isset($effective[$reference['key']])
                    || $reference['contractual_due_date'] !== $byKey[$reference['key']]['due_date']
                    || !self::date($reference['effective_arrears_date'])
                    || $reference['effective_arrears_date'] < $reference['contractual_due_date']) {
                    return $unknown;
                }
                $effective[$reference['key']] = $reference['effective_arrears_date'];
            }
        }
        $keys = $evidenceRefs = $paymentRefs = $unpaid = [];
        $entryPaid = false;
        foreach ($evidence['rows'] as $row) {
            if (!self::keys($row, ['scope','key','due_date','amount_cents','state','evidence_ref','payment_ref','paid_at','exception_state'])
                || !self::same($row['scope'], $scope) || !is_string($row['key'])
                || !isset($byKey[$row['key']]) || isset($keys[$row['key']])
                || !self::ref($row['evidence_ref']) || isset($evidenceRefs[$row['evidence_ref']])) {
                return $unknown;
            }
            $item = $byKey[$row['key']];
            if ($row['due_date'] !== $item['due_date'] || $row['amount_cents'] !== $item['amount_cents']) {
                return $unknown;
            }
            $referenceDate = $effective[$item['key']] ?? null;
            if (!in_array($row['exception_state'], ['no_exception_reported','exception_claimed'], true)
                || ($row['exception_state'] === 'exception_claimed' && $referenceDate === null)) {
                return $unknown;
            }
            $keys[$item['key']] = $evidenceRefs[$row['evidence_ref']] = true;
            if ($row['state'] === 'verified_paid') {
                if (!self::ref($row['payment_ref']) || isset($paymentRefs[$row['payment_ref']])
                    || !$row['paid_at'] instanceof DateTimeImmutable
                    || $row['paid_at'] < $acceptedAt || $row['paid_at'] > $asOf) {
                    return $unknown;
                }
                $paymentRefs[$row['payment_ref']] = true;
                $entryPaid = $entryPaid || $item['key'] === 'setup-1';
                continue;
            }
            if ($row['state'] !== 'verified_unpaid' || $row['payment_ref'] !== null || $row['paid_at'] !== null) {
                return $unknown;
            }
            $unpaid[] = $referenceDate ?? $item['due_date'];
        }

        $arrears = CalendarArrears::classify($unpaid, $asOf);
        if ($arrears === Arrears::MORE_THAN_SEVEN_DAYS) return Outcome::VERIFIED_OVERDUE_MORE_THAN_SEVEN_DAYS;
        if ($priorRelease) return Outcome::NO_FINANCIAL_BLOCK_ON_EXISTING_RELEASE;
        if (!$entryPaid) return Outcome::WAIT_FOR_ENTRY;
        if ($arrears === Arrears::DAYS_ONE_TO_SEVEN) return Outcome::REVIEW_INITIAL_ACTIVATION_GRACE;
        return Outcome::INITIAL_FINANCIAL_CONDITION_MET;
    }

    private static function date(mixed $value): bool
    {
        return is_string($value) && preg_match('/\A([0-9]{4})-([0-9]{2})-([0-9]{2})\z/D', $value, $parts) === 1
            && checkdate((int)$parts[2], (int)$parts[3], (int)$parts[1]);
    }

    private static function ref(mixed $value): bool
    {
        return is_string($value) && preg_match('/\A[A-Za-z0-9][A-Za-z0-9._:-]{0,127}\z/D', $value) === 1;
    }

    private static function keys(mixed $value, array $expected): bool
    {
        if (!is_array($value)) return false;
        $actual = array_keys($value);
        sort($actual, SORT_STRING);
        sort($expected, SORT_STRING);
        return $actual === $expected;
    }

    private static function same(mixed $candidate, array $scope): bool
    {
        if (!is_array($candidate)) return false;
        ksort($candidate);
        ksort($scope);
        return $candidate === $scope;
    }
}
