<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Identity\Security\PortalEmailDeliveryPolicy;
use Tests\TestCase;

/** No real mail transport, operator, SMTP credentials or delivery. */
final class PortalEmailDeliveryPolicyTest extends TestCase
{
    public function test_fresh_portal_configuration_cannot_send_verification_bearers(): void
    {
        self::assertSame('array',config('mail.default'));
        self::assertSame('array',config('mail.mailers.array.transport'));
        self::assertSame(['smtp','array'],config('mail.mailers.failover.mailers'));

        $c=config('portal_email_delivery');
        self::assertIsArray($c);
        foreach ([
            'enabled','approved_operator','appsec_approved',
            'sender_verified','external_witness_ready','tls_transport_verified',
        ] as $name) {
            self::assertFalse($c[$name],$name.' must never be enabled by default');
        }

        self::assertFalse(PortalEmailDeliveryPolicy::transportPrerequisitesSatisfied(
            (string)config('mail.default'),
            $c['tls_transport_verified'],
            $c['sender_verified'],
            $c['approved_operator'],
            $c['appsec_approved'],
            $c['external_witness_ready'],
        ));
        self::assertFalse(PortalEmailDeliveryPolicy::grantsPortalAccess());
    }

    public function test_sensitive_bearer_never_falls_back_to_log_failover_transport(): void
    {
        $content=file_get_contents(base_path('.env.example'));
        self::assertIsString($content);
        self::assertMatchesRegularExpression('/^MAIL_MAILER=array$/m',$content);
        self::assertStringNotContainsString('MAIL_MAILER=log',$content);

        $fallback=config('mail.mailers.failover.mailers');
        self::assertIsArray($fallback);
        self::assertNotContains('log',$fallback);
        self::assertNotContains('sendmail',$fallback);
        self::assertNotContains('roundrobin',$fallback);
    }

    public function test_all_common_unsafe_or_non_delivering_mailers_are_refused(): void
    {
        foreach (['array','log','sendmail','failover','roundrobin',
            'postmark','resend','ses','mailgun','','SMTP','smtp '] as $transport) {
            self::assertFalse(PortalEmailDeliveryPolicy::transportPrerequisitesSatisfied(
                $transport,true,true,true,true,true,
            ),$transport.' cannot transport secret proofs by policy');
        }
        self::assertTrue(PortalEmailDeliveryPolicy::transportPrerequisitesSatisfied(
            'smtp',true,true,true,true,true,
        ));
        self::assertFalse(PortalEmailDeliveryPolicy::grantsPortalAccess());
    }

    public function test_each_missing_independent_prerequisite_keeps_smtp_denied(): void
    {
        for ($missing=0;$missing<5;$missing++) {
            $gates=[true,true,true,true,true];
            $gates[$missing]=false;
            self::assertFalse(PortalEmailDeliveryPolicy::transportPrerequisitesSatisfied(
                'smtp',...$gates,
            ),'Any missing security approval must deny SMTP readiness');
        }
    }

    public function test_policy_is_not_an_issuer_mail_transport_or_customer_auth(): void
    {
        foreach (['send','deliver','mail','issueToken','authorizeHttp',
            'createUser','grantPermission','issueSession'] as $method) {
            self::assertFalse(method_exists(PortalEmailDeliveryPolicy::class,$method));
        }
    }
}
