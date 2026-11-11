<?php

declare(strict_types=1);

namespace App\Presentation;

use LogicException;
use RuntimeException;
use Throwable;

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
        // F134: stage all nine files in a private sibling, then publish the
        // complete directory in one filesystem rename. No partial export.
        $staging = $directory . '.staging-' . bin2hex(random_bytes(10));
        if (is_dir($staging) || file_exists($staging) || is_link($staging)
            || !mkdir($staging, 0700, true)) {
            throw new RuntimeException('Cannot create private demo staging directory.');
        }
        chmod($staging, 0700);
        try {
            $files = [];
        $links = '<a href="index.html">Índice</a>';
        foreach (ClientSection::cases() as $section) {
            $links .= ' <a href="' . $section->value . '.html">' .
                htmlspecialchars($section->label(), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</a>';
        }
        $links .= ' <a href="delivery-review.html">Revisão de entregas (DEMO)</a>';
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
            if (file_put_contents($staging . DIRECTORY_SEPARATOR . $name, $html, LOCK_EX) === false) {
                throw new RuntimeException('Cannot write a demo page.');
            }
            chmod($staging . DIRECTORY_SEPARATOR . $name, 0600);
            $files[] = $name;
        }

        // F129: standalone operator preview. It deliberately is NOT a
        // ClientSection or route, avoiding an accidental customer entitlement.
        $reviewHtml = view('portal.demo.delivery-review')->render();
        if (!str_contains($reviewHtml, '<body>')
            || preg_match('~<\\s*(script|form|iframe|button|input)\\b~i', $reviewHtml) === 1
            || preg_match('~(?:https?:)?//~i', $reviewHtml) === 1) {
            throw new LogicException('The delivery-review preview is not safe for offline export.');
        }
        $reviewHtml = str_replace('<body>', '<body>' . $nav, $reviewHtml);
        $reviewName = 'delivery-review.html';
        if (file_put_contents($staging . DIRECTORY_SEPARATOR . $reviewName, $reviewHtml, LOCK_EX) === false) {
            throw new RuntimeException('Cannot write the review preview.');
        }
        chmod($staging . DIRECTORY_SEPARATOR . $reviewName, 0600);
        $files[] = $reviewName;

        $list = '';
        foreach (ClientSection::cases() as $section) {
            $label = htmlspecialchars($section->label(), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
            $list .= '<li><a href="' . $section->value . '.html">' . $label . '</a></li>';
        }
        $list .= '<li><a href="delivery-review.html">Revisão de entregas (DEMO)</a></li>';
        $index = '<!doctype html><html lang="pt-BR"><head><meta charset="utf-8">' .
            '<meta name="viewport" content="width=device-width,initial-scale=1">' .
            '<meta name="robots" content="noindex,nofollow">' .
            '<title>Imagine Portal — prévia interna offline</title>' .
            '<style>body{font:16px/1.6 system-ui;margin:30px auto;max-width:760px;padding:0 20px;color:#142239}' .
            'a{color:#225fc2}li{padding:7px 0}.warning{border:1px solid #c78b39;padding:15px}</style>' .
            '</head><body><h1>Imagine Portal — prévia interna offline</h1>' .
            '<p class="warning">Somente dados fictícios. Sem login, contrato, pagamento, cliente ou publicação.</p>' .
            '<nav aria-label="Telas ilustrativas"><ul>' . $list . '</ul></nav></body></html>';

        if (file_put_contents($staging . DIRECTORY_SEPARATOR . 'index.html', $index, LOCK_EX) === false) {
            throw new RuntimeException('Cannot write the demo index.');
        }
        chmod($staging . DIRECTORY_SEPARATOR . 'index.html', 0600);
        $files[] = 'index.html';

            // F135: audit a fixed nine-page synthetic package before the
            // atomic directory rename; never signal a completed partial set.
            (new DemoPortalArtifactManifest())->create($staging, $files);

            if (is_dir($directory) || file_exists($directory) || is_link($directory)
                || !rename($staging, $directory)) {
                throw new RuntimeException('Demo export target changed before commit.');
            }
            return $files;
        } catch (Throwable $error) {
            foreach (glob($staging . DIRECTORY_SEPARATOR . '*.html') ?: [] as $part) {
                if (is_file($part) && !is_link($part)) {
                    @unlink($part);
                }
            }
            $stagedManifest = $staging . DIRECTORY_SEPARATOR . 'manifest.json';
            if (is_file($stagedManifest) && !is_link($stagedManifest)) {
                @unlink($stagedManifest);
            }
            if (is_dir($staging) && !is_link($staging)) {
                @rmdir($staging);
            }
            throw $error;
        }
    }

    public static function authorizesCustomerAccess(): bool { return false; }
    public static function enablesWebRoutes(): bool { return false; }
}
