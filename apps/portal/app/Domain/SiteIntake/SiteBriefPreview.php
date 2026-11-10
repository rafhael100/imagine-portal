<?php

declare(strict_types=1);

namespace App\Domain\SiteIntake;

use App\Domain\Organizations\OrganizationId;
use App\Domain\ServicePortfolio\PortfolioProduct;

/**
 * F42: closed-key Sites Onepage/Landing Page brief validation preview.
 *
 * Incoming data is UNTRUSTED. No fields for names, phones, emails,
 * passwords, CNPJ, files, payment, pricing, login or customer identity.
 * Selected organization is a UI preview input, NOT an IAM membership.
 */
final class SiteBriefPreview
{
    /** @var list<string> */
    private const REQUIRED = [
        'reference','organization','product','objective',
        'audience','call_to_action','revision',
    ];

    /**
     * @param array<array-key,mixed> $payload An untrusted synthetic form.
     */
    public function evaluate(array $payload,OrganizationId $selectedOrganization): SiteBriefPreviewResult
    {
        $keys=array_keys($payload);
        if (count($keys)!==count(self::REQUIRED)) {
            return SiteBriefPreviewResult::blocked('unexpected_fields');
        }
        sort($keys,SORT_STRING);
        $expected=self::REQUIRED;
        sort($expected,SORT_STRING);
        if ($keys!==$expected) {
            return SiteBriefPreviewResult::blocked('unexpected_fields');
        }

        // Do not even examine product or text of another selected company.
        $org=$payload['organization'];
        if (!is_string($org)
            || !in_array($org,['org-a','org-b'],true)
            || $selectedOrganization->value!==$org) {
            return SiteBriefPreviewResult::blocked('mixed_organization');
        }

        $reference=$payload['reference'];
        if (!is_string($reference)
            || preg_match('/\ADEMO-SITE-REQ-[A-Z0-9-]{1,48}\z/D',$reference)!==1) {
            return SiteBriefPreviewResult::blocked('invalid_reference');
        }
        $product=$payload['product'];
        if (!is_string($product)) {
            return SiteBriefPreviewResult::blocked('unsupported_product');
        }
        $chosen=PortfolioProduct::tryFrom($product);
        if (!in_array($chosen,[PortfolioProduct::Onepage,PortfolioProduct::LandingPage],true)) {
            return SiteBriefPreviewResult::blocked('unsupported_product');
        }
        $revision=$payload['revision'];
        if (!is_int($revision) || $revision<0 || $revision>100000) {
            return SiteBriefPreviewResult::blocked('invalid_revision');
        }

        $content=[];
        foreach ([
            'objective'=>[12,500],
            'audience'=>[8,180],
            'call_to_action'=>[4,100],
        ] as $field=>[$minimum,$maximum]) {
            $value=$payload[$field];
            if (!is_string($value) || strlen($value)>4096
                || preg_match('//u',$value)!==1) {
                return SiteBriefPreviewResult::blocked('invalid_content');
            }
            // Bidi controls, zero-width controls, HTML tags and nonprintable
            // characters are rejected, not silently stripped from the brief.
            if (preg_match('/[\p{C}<>]/u',$value)===1) {
                return SiteBriefPreviewResult::blocked('unsafe_content');
            }
            $normalized=preg_replace('/\s+/u',' ',trim($value));
            if (!is_string($normalized)) {
                return SiteBriefPreviewResult::blocked('invalid_content');
            }
            $characters=preg_match_all('/./us',$normalized);
            if (!is_int($characters)
                || $characters<$minimum
                || $characters>$maximum) {
                return SiteBriefPreviewResult::blocked('content_out_of_bounds');
            }
            $content[$field]=$normalized;
        }

        return new SiteBriefPreviewResult('preview_ready',new SiteBriefDraft(
            $reference,$selectedOrganization,$chosen,
            $content['objective'],$content['audience'],
            $content['call_to_action'],$revision,
        ));
    }
}
