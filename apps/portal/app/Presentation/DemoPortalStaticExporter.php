<?php

declare(strict_types=1);

namespace App\Presentation;

use LogicException;
use RuntimeException;

/**
 * F58: offline, read-only artifact generator for seven existing DEMO Blade
 * screens. This is NOT a customer-facing route or an authorization system.
 *
 * Only local/testing application environments may invoke it. Output must
 * be a new, caller-owned directory; existing files are never overwritten.
 */
final class DemoPortalStaticExporter
{
    /** @return list<string> Names of generated HTML files */
    public function export(string $directory): array
    {
        if (!app()->environment(['local', 'testing'])) {
            throw new LogicException('Demo export is prohibited outside local/testing environments.');
        }
        if (is_dir($directory) || file_exists($directory) || is_link($directory)) {
            throw new LogicException('Demo export target must not exist.');
        }
        if (!mkdir($directory, 0700, true)) {
            throw new RuntimeException('Cannot create dedicated demo export directory.');
        }
        chmod($directory, 0700);

        $files = [];
        $links = '<a href="index.html">Índice</a>';
        foreach (ClientSection::cases() as $section) {
            $links .= ' <a href="' . $section->value . '.html">' .
                htmlspecialchars($section->label(), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</a>';
        }
        $nav = '<nav aria-label="Navegação da prévia offline" style="background:#fff;padding:14px 20px;border-bottom:1px solid #ddd;display:flex;gap:14px;flex-wrap:wrap">' .
            $links . '</nav>';

        foreach (ClientSection::cases() as $section) {
            $html = view('portal.demo.' . $section->value)->render();
            if (!str_contains($html, '<body>') ||
                preg_match('~<\s*(script|form|iframe|button|input)\b~i', $html) === 1 ||
                preg_match('~(?:https?:)?//~i', $html) === 1) {
                throw new LogicException('The demo view is not suitable for offline static export.');
            }
            $html = str_replace('<body>', '<body>' . $nav, $html);
            $name = $section->value . '.html';
            if (file_put_contents($directory . DIRECTORY_SEPARATOR . $name, $html, LOCK_EX) === false) {
                throw new RuntimeException('Cannot write a demo page.');
            }
            chmod($directory . DIRECTORY_SEPARATOR . $name, 0600);
            $files[] = $name;
        }

        $list = '';
        foreach (ClientSection::cases() as $section) {
            $label = htmlspecialchars($section->label(), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
            $list .= '<li><a href="' . $section->value . '.html">' . $label . '</a></li>';
        }
        $index = '<!doctype html><html lang="pt-BR"><head><meta charset="utf-8">' .
            '<meta name="viewport" content="width=device-width,initial-scale=1">' .
            '<meta name="robots" content="noindex,nofollow">' .
            '<title>Imagine Portal — prévia interna offline</title>' .
            '<style>body{font:16px/1.6 system-ui;margin:30px auto;max-width:760px;padding:0 20px;color:#142239}' .
            'a{color:#225fc2}li{padding:7px 0}.warning{border:1px solid #c78b39;padding:15px}</style>' .
            '</head><body><h1>Imagine Portal — prévia interna offline</h1>' .
            '<p class="warning">Somente dados fictícios. Sem login, contrato, pagamento, cliente ou publicação.</p>' .
            '<nav aria-label="Telas ilustrativas"><ul>' . $list . '</ul></nav></body></html>';

        if (file_put_contents($directory . DIRECTORY_SEPARATOR . 'index.html', $index, LOCK_EX) === false) {
            throw new RuntimeException('Cannot write the demo index.');
        }
        chmod($directory . DIRECTORY_SEPARATOR . 'index.html', 0600);
        $files[] = 'index.html';

        return $files;
    }

    public static function authorizesCustomerAccess(): bool { return false; }
    public static function enablesWebRoutes(): bool { return false; }
}
