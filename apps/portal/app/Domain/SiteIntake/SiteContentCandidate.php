<?php

declare(strict_types=1);

namespace App\Domain\SiteIntake;

/**
 * F43: immutable, synthetic generated-copy candidate bound to an F42
 * Sites brief and its exact content revision.
 *
 * The SHA-256 is a stable FINGERPRINT, NOT a signature, proof of
 * customer consent, proof of AI model identity, or publishing permit.
 */
final readonly class SiteContentCandidate
{
    public string $fingerprint;
    public string $headline;
    public string $description;
    public string $callToAction;

    public function __construct(
        public SiteBriefDraft $brief,
        public int $contentRevision,
        string $headline,
        string $description,
        string $callToAction,
    ) {
        if ($contentRevision < 1 || $contentRevision > 100000) {
            throw new \InvalidArgumentException('Invalid synthetic Sites content revision.');
        }
        $this->headline=self::normalizedText($headline,10,120);
        $this->description=self::normalizedText($description,30,1000);
        $this->callToAction=self::normalizedText($callToAction,4,100);

        $this->fingerprint=hash('sha256',json_encode([
            'schema'=>'portal-f43-copy-v1',
            'reference'=>$brief->reference,
            'organization'=>$brief->organizationId->value,
            'product'=>$brief->product->value,
            'brief_revision'=>$brief->revision,
            'content_revision'=>$contentRevision,
            'headline'=>$this->headline,
            'description'=>$this->description,
            'call_to_action'=>$this->callToAction,
        ],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR));
    }

    private static function normalizedText(string $value,int $min,int $max): string
    {
        if (strlen($value)>4096 || preg_match('//u',$value)!==1
            || preg_match('/[\p{C}<>]/u',$value)===1) {
            throw new \InvalidArgumentException('Unsafe synthetic Sites copy content.');
        }
        $normalized=preg_replace('/\s+/u',' ',trim($value));
        $length=is_string($normalized) ? preg_match_all('/./us',$normalized) : false;
        if (!is_int($length) || $length<$min || $length>$max) {
            throw new \InvalidArgumentException('Out-of-bounds synthetic Sites copy text.');
        }
        return $normalized;
    }

    public function canPublish(): bool
    {
        return false;
    }

    public function canCharge(): bool
    {
        return false;
    }
}
