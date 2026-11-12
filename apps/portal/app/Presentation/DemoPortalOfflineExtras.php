<?php

declare(strict_types=1);

namespace App\Presentation;

/**
 * F140: additional READ-ONLY screenshots of already-existing Blade views.
 * This registry is NOT ClientSection, a Laravel route or a permission grant.
 */
final class DemoPortalOfflineExtras
{
    /** @return array<string,string> Local HTML slug => visible fictional label */
    public static function labels(): array
    {
        return [
            'help' => 'Central de Ajuda (DEMO)',
            'notices' => 'Central de Avisos (DEMO)',
        ];
    }

    /** @return list<string> */
    public static function fileNames(): array
    {
        return array_map(
            static fn (string $name): string => $name.'.html',
            array_keys(self::labels()),
        );
    }

    public static function authorizesAccess(): bool
    {
        return false;
    }
}
