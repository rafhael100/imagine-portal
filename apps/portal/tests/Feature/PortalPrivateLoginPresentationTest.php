<?php
declare(strict_types=1);
namespace Tests\Feature;
use Tests\TestCase;
final class PortalPrivateLoginPresentationTest extends TestCase
{
    public function test_unconfigured_initial_access_is_explicit_and_forms_are_hidden():void
    {
        $html=view('portal.auth.private-login',['organization'=>'','ready'=>false])->render();
        self::assertStringContainsString('Seu acesso está em preparação.',$html);
        self::assertStringContainsString('id="password-form"  hidden',$html);
        self::assertStringContainsString('Imagine Comunicação Digital',$html);
        self::assertStringContainsString('/_development/auth/logo.png',$html);
        self::assertStringNotContainsString('https://',$html);
        $this->get('/_development/auth/login')->assertNotFound();
    }
    public function test_configured_view_has_accessible_password_and_mfa_fields_without_prefilled_secrets():void
    {
        $html=view('portal.auth.private-login',['organization'=>'22222222-2222-4222-8222-222222222222','ready'=>true])->render();
        self::assertStringContainsString('autocomplete="current-password"',$html);
        self::assertStringContainsString('autocomplete="one-time-code"',$html);
        self::assertStringContainsString('aria-live="polite"',$html);
        self::assertStringContainsString('id="mfa-form" hidden',$html);
        self::assertStringNotContainsString('name="password" value=',$html);
        self::assertStringNotContainsString('localStorage',$html);
    }
}
