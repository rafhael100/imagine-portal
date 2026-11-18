<?php
declare(strict_types=1);
namespace App\Domain\Commerce;

use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;

/** An immutable accepted contract. It is not payment, Sites or publication authority. */
final readonly class OnepageAcceptedOrder
{
    public function __construct(
        public string $id,
        public string $quoteId,
        public string $companyId,
        public string $buyerId,
        public string $companyReference,
        public string $publicationRef,
        public array $quoteSnapshot,
        public string $catalogVersion,
        public string $offerDigest,
        public array $terms,
        public string $termsDigest,
        public string $contractRef,
        public int $contractRevision,
        public string $contractText,
        public string $contractDigest,
        public string $acceptedAt,
    ) {
        foreach ([$id,$quoteId,$companyId,$buyerId] as $value) {
            if (!preg_match('/\A[0-9a-f]{8}(?:-[0-9a-f]{4}){3}-[0-9a-f]{12}\z/D',$value)) {
                throw new InvalidArgumentException('Invalid native order reference.');
            }
        }
        foreach ([$catalogVersion,$offerDigest,$termsDigest,$contractDigest] as $value) {
            if (!preg_match('/\A[a-f0-9]{64}\z/D',$value)) throw new InvalidArgumentException('Invalid order digest.');
        }
        foreach ([$companyReference,$publicationRef,$contractRef] as $value) {
            if (!preg_match('/\A[A-Za-z0-9][A-Za-z0-9._:-]{0,127}\z/D',$value)) {
                throw new InvalidArgumentException('Invalid published order reference.');
            }
        }
        if (preg_match('/\A(?:lab|demo|test)[-_:]/i',$contractRef)
            || preg_match('/\A(?:lab|demo|test)[-_:]/i',$publicationRef)
            || $contractRevision<1 || $contractText==='' || strlen($contractText)>65536
            || hash('sha256',$contractText)!==$contractDigest
            || ($quoteSnapshot['catalog_version']??null)!==$catalogVersion
            || ($quoteSnapshot['site']??null)!=='onepage' || ($quoteSnapshot['channels']??null)!==[]
            || ($terms['scope']??null)!=='onepage' || !is_string($terms['payment_policy']??null)
            || $terms['payment_policy']==='' || $terms['payment_policy']==='undecided') {
            throw new InvalidArgumentException('Published Onepage contract required.');
        }
        $format='Y-m-d\TH:i:s.u\Z';
        $date=DateTimeImmutable::createFromFormat('!'.$format,$acceptedAt,new DateTimeZone('UTC'));
        if (!$date || $date->format($format)!==$acceptedAt) throw new InvalidArgumentException('Invalid acceptance time.');
    }

    public static function fromRow(array $row): self
    {
        if (($row['status']??null)!=='pending_payment') throw new InvalidArgumentException('Accepted order required.');
        return new self($row['id'],$row['quote_id'],$row['company'],$row['buyer'],
            $row['company_reference'],$row['publication_ref'],$row['quote_snapshot'],
            $row['quote_snapshot']['catalog_version'],$row['offer_digest'],$row['terms'],$row['terms_digest'],
            $row['contract_ref'],$row['contract_revision'],$row['contract_text'],$row['contract_digest'],$row['accepted_at']);
    }
}
