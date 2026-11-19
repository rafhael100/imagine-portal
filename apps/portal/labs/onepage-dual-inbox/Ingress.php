<?php
declare(strict_types=1);
namespace PrivateOnepageIngress;

/** Local laboratory only. No routes, environment loading, SQL or HTTP clients. */
final readonly class ApprovedCase
{
    public function __construct(
        public string $account, public string $order, public string $payment,
        public string $revision, public string $environment = 'sandbox',
    ) {
        if ($environment !== 'sandbox'
            || preg_match('/\A[0-9a-f]{8}(?:-[0-9a-f]{4}){3}-[0-9a-f]{12}\z/D', $account) !== 1
            || preg_match('/\A[0-9a-f]{8}(?:-[0-9a-f]{4}){3}-[0-9a-f]{12}\z/D', $order) !== 1
            || preg_match('/\Apay_[A-Za-z0-9_]{6,64}\z/D', $payment) !== 1
            || preg_match('/\A[a-zA-Z0-9_-]{1,128}\z/D', $revision) !== 1) {
            throw new \InvalidArgumentException('CASE_REFUSED');
        }
    }
}

interface CaseRegistry
{
    /** Trusted persisted binding, never constructed/adopted from event fields. */
    public function isCurrent(ApprovedCase $case): bool;
}

interface InboxPort
{
    /**
     * Atomically revalidate the EXACT case/revision against trusted binding,
     * then insert-or-compare under unique(account,event_id), and COMMIT.
     * Return recorded/replay ONLY after confirmed durable commit.
     * Duplicate equality includes event_type, payment_id and payload_sha256.
     * Same ID/different content => conflict, WITHOUT overwriting.
     * Binding missing/revoked/changed => unarmed, WITHOUT inserting.
     * Exception/uncertain commit => caller retries same event, never adopts it.
     * Store only normalized metadata; never raw body, token or customer data.
     * No posting, ACK to consumer queue, briefing release or historical writes.
     * @param array{event_id:string,event_type:string,payment_id:string,payload_sha256:string} $event
     * @return 'recorded'|'replay'|'conflict'|'unarmed'
     */
    public function captureCommitted(ApprovedCase $case, array $event): string;
}

/**
 * Authentication policy reused from #768 AsaasWebhookPgInbox::capture:
 * commit 563e281c0e789e6bcd772aa1095a7ec33490b544,
 * blob 200768d4a85bdb114b83590c4ee25e5adeb9b690.
 * Extracted pure policy, not an invocation/copy of its PDO constructor.
 * Header cardinality must be retained by any future HTTP adapter.
 */
final readonly class Pinned768Authentication
{
    public function __construct(private string $expectedToken)
    {
        if (strlen($expectedToken) < 32 || strlen($expectedToken) > 255
            || preg_match('/[\x00-\x20\x7f]/', $expectedToken)) {
            throw new \InvalidArgumentException('AUTH_CONFIGURATION_REFUSED');
        }
    }

    public function accepts(array $tokens): bool
    {
        return array_is_list($tokens) && count($tokens) === 1
            && is_string($tokens[0]) && hash_equals($this->expectedToken, $tokens[0]);
    }
}

final readonly class Ingress
{
    public function __construct(
        // Trusted server composition pairs this token with the configured case account.
        private Pinned768Authentication $authentication,
        private CaseRegistry $registry,
        private InboxPort $inbox,
        private ?ApprovedCase $case = null,
        private bool $enabled = false,
    ) {}

    /** Pure laboratory entry point; outcome is NOT proof of payment. */
    public function receive(string $method, string $contentType, array $tokens, string $rawBody): string
    {
        // No registry/inbox access, JSON parsing or binding disclosure before auth.
        if (!$this->authentication->accepts($tokens)) return 'unauthorized';
        if (!$this->enabled || $this->case === null) return 'disabled';
        if ($method !== 'POST'
            || preg_match('/\Aapplication\/json(?:\s*;\s*charset=utf-8)?\z/iD', trim($contentType)) !== 1
            || strlen($rawBody) < 2 || strlen($rawBody) > 32768) return 'invalid';
        try {
            $v = json_decode($rawBody, true, 12, JSON_THROW_ON_ERROR);
            if (!is_array($v) || array_is_list($v)
                || !is_string($v['id'] ?? null)
                || preg_match('/\Aevt_[A-Za-z0-9_&-]{8,128}\z/D', $v['id']) !== 1
                || !in_array($v['event'] ?? null, ['PAYMENT_CREATED','PAYMENT_CONFIRMED','PAYMENT_RECEIVED'], true)
                || (array_key_exists('account', $v) && (
                    !is_array($v['account']) || array_is_list($v['account'])
                    || ($v['account']['id'] ?? null) !== $this->case->account))
                || !is_array($v['payment'] ?? null) || array_is_list($v['payment'])
                || !is_string($v['payment']['id'] ?? null)
                || preg_match('/\Apay_[A-Za-z0-9_]{6,64}\z/D', $v['payment']['id']) !== 1
                || ($v['payment']['id'] ?? null) !== $this->case->payment) return 'incompatible';
            // Account authority is server configuration after token verification.
            // Optional payload account is only a consistency check; IDs are never trimmed/coerced.
            // An early/unbound event gets no durable ACK and no automatic adoption.
            // Neither value nor externalReference is a binding lookup authority.
            if (!$this->registry->isCurrent($this->case)) return 'unarmed';
            $result = $this->inbox->captureCommitted($this->case, [
                'event_id'=>$v['id'], 'event_type'=>$v['event'],
                'payment_id'=>$this->case->payment, 'payload_sha256'=>hash('sha256', $rawBody),
            ]);
            return match ($result) {
                'recorded'=>'captured', 'replay'=>'duplicate',
                'conflict'=>'conflict', 'unarmed'=>'unarmed', default=>'retry',
            };
        } catch (\Throwable) {
            return 'retry'; // No exception details, raw payload or identifiers escape.
        }
    }
}
