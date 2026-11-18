<?php
declare(strict_types=1);

namespace Imagine\Lab\OnepageSandboxRecovery;

use Throwable;

/** Future adapter: independent Sandbox reads, with account/environment attested out of band.
 * No HTTP implementation or acquisition credentials in this laboratory. */
interface SandboxReads
{
    public function listByExternalReference(string $reference): ScopedRead;
    public function paymentById(string $id): ScopedRead;
}

/** Metadata must come from verified connection context, not the provider response body. */
final readonly class ScopedRead
{
    public function __construct(public string $account, public string $environment, public array $body) {}
}

/** A candidate to persist following independent authority checks; not proof of payment. */
final readonly class RecoveredLine
{
    public function __construct(
        public string $order,
        public string $line,
        public string $account,
        public string $externalReference,
        public string $paymentId,
    ) {}
}

enum RecoveryCode: string
{
    case LINKED_CANDIDATE = 'linked_candidate';
    case NOT_FOUND_YET = 'not_found_yet';
    case CONFLICT_OR_UNAVAILABLE = 'conflict_or_unavailable';
}

/** Only the status is public output; projection is internal, not for logging. */
final readonly class RecoveryResult
{
    private function __construct(public RecoveryCode $code, private ?RecoveredLine $candidate = null) {}
    public static function linked(RecoveredLine $line): self { return new self(RecoveryCode::LINKED_CANDIDATE,$line); }
    public static function absent(): self { return new self(RecoveryCode::NOT_FOUND_YET); }
    public static function quarantine(): self { return new self(RecoveryCode::CONFLICT_OR_UNAVAILABLE); }
    public function linkedCandidate(): ?RecoveredLine { return $this->candidate; }
    public function __debugInfo(): array { return ['code'=>$this->code->value]; }
    public function __serialize(): array { throw new \LogicException('Recovery output is private.'); }
    public function __unserialize(array $data): void { throw new \LogicException('Recovery output is private.'); }
}

final class Recovery
{
    /** Compatibility: private binding candidate or null, never a retry signal. */
    public static function one(array $reserved, SandboxReads $reads): ?RecoveredLine
    {
        return self::inspect($reserved,$reads)->linkedCandidate();
    }

    /** $reserved is an immutable attempted ledger projection, never HTTP input.
     * No outcome allows resend, release or settlement. */
    public static function inspect(array $reserved, SandboxReads $reads): RecoveryResult
    {
        foreach (['order_id','line','account','environment','customer','reference','due_date','amount_cents','attempted','payment_id'] as $field) {
            if (!array_key_exists($field,$reserved)) return RecoveryResult::quarantine();
        }
        $ref = $reserved['reference'];
        $stored = $reserved['payment_id'];
        if ($reserved['environment'] !== 'sandbox' || $reserved['attempted'] !== true
            || !self::matches($reserved['order_id'],'[0-9a-f]{8}(?:-[0-9a-f]{4}){3}-[0-9a-f]{12}')
            || !in_array($reserved['line'],['setup-1','setup-2','setup-3'],true)
            || !self::matches($reserved['account'],'lab-[A-Za-z0-9-]{1,60}')
            || !self::matches($reserved['customer'],'cus_[A-Za-z0-9]{1,100}')
            || !self::matches($ref,'imagine:lab-op-v1:[0-9a-f]{64}')
            || !self::date($reserved['due_date'])
            || !is_int($reserved['amount_cents']) || $reserved['amount_cents'] < 1 || $reserved['amount_cents'] > 100000000
            || ($stored !== null && !self::paymentId($stored))) return RecoveryResult::quarantine();
        try {
            $list = $reads->listByExternalReference($ref);
            $page = $list->body;
            if (!self::sameAccount($list,$reserved)
                || ($page['object'] ?? null) !== 'list' || ($page['hasMore'] ?? null) !== false
                || ($page['offset'] ?? null) !== 0
                || !is_array($items = $page['data'] ?? null) || !array_is_list($items)
                || ($page['totalCount'] ?? null) !== count($items)) return RecoveryResult::quarantine();
            // An existing pinned ID makes an empty result a conflict, not absence.
            if ($items === []) return $stored === null ? RecoveryResult::absent() : RecoveryResult::quarantine();
            if (count($items) !== 1 || !is_array($items[0]) || !self::matchesLine($items[0],$reserved)) {
                return RecoveryResult::quarantine();
            }
            $id = $items[0]['id'];
            if ($stored !== null && $stored !== $id) return RecoveryResult::quarantine();
            $exact = $reads->paymentById($id);
            if (!self::sameAccount($exact,$reserved) || ($exact->body['id'] ?? null) !== $id
                || !self::matchesLine($exact->body,$reserved)) return RecoveryResult::quarantine();
            return RecoveryResult::linked(new RecoveredLine($reserved['order_id'],$reserved['line'],$reserved['account'],$ref,$id));
        } catch (Throwable) {
            return RecoveryResult::quarantine();
        }
    }

    private static function sameAccount(ScopedRead $r,array $reserved): bool
    {
        return $r->environment === 'sandbox' && $r->account === $reserved['account'];
    }

    /** Check identity only. Status is not interpreted or returned. */
    private static function matchesLine(array $p,array $r): bool
    {
        return ($p['object'] ?? null) === 'payment' && self::paymentId($p['id'] ?? null)
            && ($p['customer'] ?? null) === $r['customer'] && ($p['dueDate'] ?? null) === $r['due_date']
            && self::matches($p['status'] ?? null,'[A-Z_]{1,64}') && ($p['externalReference'] ?? null) === $r['reference'] && ($p['deleted'] ?? null) === false
            && ($p['installment'] ?? null) === null && ($p['installmentNumber'] ?? null) === null
            && ($p['subscription'] ?? null) === null
            && (!array_key_exists('currency',$p) || $p['currency'] === 'BRL')
            && self::cents($p['value'] ?? null) === $r['amount_cents'];
    }

    private static function paymentId(mixed $v): bool { return self::matches($v,'pay_[A-Za-z0-9]{1,100}'); }
    private static function matches(mixed $v,string $pattern): bool
    {
        return is_string($v) && preg_match('/\A'.$pattern.'\z/D',$v) === 1;
    }
    private static function date(mixed $v): bool
    {
        return is_string($v) && preg_match('/\A([0-9]{4})-([0-9]{2})-([0-9]{2})\z/D',$v,$m) === 1
            && checkdate((int)$m[2],(int)$m[3],(int)$m[1]);
    }
    /** Same lossless decimal convention as the #925 synthetic producer. */
    private static function cents(mixed $v): ?int
    {
        if (is_int($v)) return $v > 0 && $v <= 1000000 ? $v * 100 : null;
        if (!is_string($v) || preg_match('/\A(0|[1-9][0-9]{0,6})(?:\.([0-9]{1,2}))?\z/D',$v,$m) !== 1) return null;
        return (int)$m[1] * 100 + (int)str_pad($m[2] ?? '',2,'0');
    }
}
