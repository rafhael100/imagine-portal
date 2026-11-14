<?php

declare(strict_types=1);

namespace App\Presentation;

use LogicException;
use RuntimeException;
use Throwable;

/**
 * F58: offline, read-only artifact generator for eleven local DEMO Blade
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
        // F139: exclusive, same-parent claim prevents competing export
        // processes from racing to publish the same local destination.
        // An abandoned claim after a crash is intentionally fail-closed.
        $claimFile = $directory . '.exporting';
        $claimHandle = @fopen($claimFile, 'x+b');
        if ($claimHandle === false) {
            throw new LogicException('Preview export already reserved or claim is stale.');
        }
        try {
        // F134: stage all preview files in a private sibling, then publish the
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
        foreach (DemoPortalOfflineExtras::labels() as $slug => $label) {
            $links .= ' <a href="' . $slug . '.html">' .
                htmlspecialchars($label, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</a>';
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
            $html = $this->withEmbeddedApprovedLogo($html);
            // F138: mark the current exported page without adding JS routes
            // or falsely granting a clickable customer Portal menu.
            $pageNav = str_replace(
                'href="' . $section->value . '.html"',
                'href="' . $section->value . '.html" aria-current="page" style="font-weight:700;text-decoration:underline"',
                $nav,
            );
            $html = str_replace('<body>', '<body>' . $pageNav, $html);
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
        $reviewHtml = $this->withEmbeddedApprovedLogo($reviewHtml);
        $reviewNav = str_replace(
            'href="delivery-review.html"',
            'href="delivery-review.html" aria-current="page" style="font-weight:700;text-decoration:underline"',
            $nav,
        );
        $reviewHtml = str_replace('<body>', '<body>' . $reviewNav, $reviewHtml);
        $reviewName = 'delivery-review.html';
        if (file_put_contents($staging . DIRECTORY_SEPARATOR . $reviewName, $reviewHtml, LOCK_EX) === false) {
            throw new RuntimeException('Cannot write the review preview.');
        }
        chmod($staging . DIRECTORY_SEPARATOR . $reviewName, 0600);
        $files[] = $reviewName;

        // F140: two already-implemented illustrations become traversable
        // offline only. No changes to ClientSection, permissions or routes.
        foreach (DemoPortalOfflineExtras::labels() as $slug => $label) {
            $extraHtml = view('portal.demo.'.$slug)->render();
            if (!str_contains($extraHtml, '<body>')
                || preg_match('~<\\s*(script|form|iframe|button|input)\\b~i', $extraHtml) === 1
                || preg_match('~(?:https?:)?//~i', $extraHtml) === 1) {
                throw new LogicException('Extra demo illustration is not safe for offline export.');
            }
            $extraHtml = $this->withEmbeddedApprovedLogo($extraHtml);
            $extraNav = str_replace(
                'href="' . $slug . '.html"',
                'href="' . $slug . '.html" aria-current="page" style="font-weight:700;text-decoration:underline"',
                $nav,
            );
            $extraHtml = str_replace('<body>', '<body>' . $extraNav, $extraHtml);
            $extraName = $slug . '.html';
            if (file_put_contents($staging . DIRECTORY_SEPARATOR . $extraName, $extraHtml, LOCK_EX) === false) {
                throw new RuntimeException('Cannot write additional preview page.');
            }
            chmod($staging . DIRECTORY_SEPARATOR . $extraName, 0600);
            $files[] = $extraName;
        }

        $list = '<li><a href="index.html" aria-current="page" style="font-weight:700;text-decoration:underline">Índice da prévia</a></li>';
        foreach (ClientSection::cases() as $section) {
            $label = htmlspecialchars($section->label(), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
            $list .= '<li><a href="' . $section->value . '.html">' . $label . '</a></li>';
        }
        $list .= '<li><a href="delivery-review.html">Revisão de entregas (DEMO)</a></li>';
        foreach (DemoPortalOfflineExtras::labels() as $slug => $label) {
            $list .= '<li><a href="' . $slug . '.html">' .
                htmlspecialchars($label, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</a></li>';
        }
        // F141: a useful offline-only walkthrough, not a customer
        // workflow. Every link must already belong to the closed manifest.
        $allowed = array_fill_keys(DemoPortalArtifactManifest::pageNames(), true);
        $walkthroughSeen = [];
        $walkthrough = '<section aria-labelledby="pi-review-route"><h2 id="pi-review-route">Roteiro de avaliação interna</h2>' .
            '<p>Estas etapas são apenas uma sequência sugerida para avaliar dez telas fictícias. Não registram tarefas nem ações de clientes.</p><ol>';
        foreach (DemoPortalReviewWalkthrough::steps() as $step) {
            $href = $step['href'];
            if (!isset($allowed[$href]) || $href === 'index.html' || isset($walkthroughSeen[$href])) {
                throw new LogicException('Offline walkthrough references an unapproved or repeated page.');
            }
            $walkthroughSeen[$href] = true;
            $title = htmlspecialchars($step['title'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
            $description = htmlspecialchars($step['description'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
            $walkthrough .= '<li><a href="' . $href . '">' . $title . '</a><p>' . $description . '</p></li>';
        }
        if (count($walkthroughSeen) !== count(DemoPortalArtifactManifest::pageNames()) - 1) {
            throw new LogicException('Offline walkthrough does not include every synthetic screen.');
        }
        $walkthrough .= '</ol></section>';

        $index = '<!doctype html><html lang="pt-BR"><head><meta charset="utf-8">' .
            '<meta name="viewport" content="width=device-width,initial-scale=1">' .
            '<meta name="robots" content="noindex,nofollow">' .
            '<meta name="referrer" content="no-referrer">' .
            '<meta http-equiv="Content-Security-Policy" content="' .
            htmlspecialchars(DemoPortalNavigationAudit::OFFLINE_CSP, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '">' .
            '<title>Imagine Portal — prévia interna offline</title>' .
            '<style>body{font:16px/1.6 system-ui;margin:30px auto;max-width:760px;padding:0 20px;color:#171b19}' .
            'a{color:#8b4600}li{padding:7px 0}.warning{border:1px solid #c78b39;padding:15px}</style>' .
            '</head><body><a href="#pi-content">Pular para o conteúdo</a>' .
            '<main id="pi-content"><h1>Imagine Portal — prévia interna offline</h1>' .
            '<p class="warning">Somente dados fictícios. Sem login, contrato, pagamento, cliente ou publicação.</p>' .
            '<nav aria-label="Telas ilustrativas"><ul>' . $list . '</ul></nav>' .
            $walkthrough . '</main></body></html>';

        if (file_put_contents($staging . DIRECTORY_SEPARATOR . 'index.html', $index, LOCK_EX) === false) {
            throw new RuntimeException('Cannot write the demo index.');
        }
        chmod($staging . DIRECTORY_SEPARATOR . 'index.html', 0600);
        $files[] = 'index.html';

            // F136: verify all offline links, skip targets and landmarks
            // before the manifest is signed by local hashes and committed.
            (new DemoPortalNavigationAudit())->verify($staging);

            // F135: audit a fixed synthetic page package before the
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
        } finally {
            // F248: exclusive creation establishes ownership of one inode, not
            // a permanent right to unlink whichever file later occupies this
            // pathname. A local operator could replace a stale-looking claim
            // during rendering. Never remove a successor's reservation.
            $owned = fstat($claimHandle);
            fclose($claimHandle);
            clearstatcache(true, $claimFile);
            $current = @lstat($claimFile);
            if (is_array($owned) && is_array($current)
                && $owned['dev'] === $current['dev']
                && $owned['ino'] === $current['ino']
                && is_file($claimFile) && !is_link($claimFile)) {
                @unlink($claimFile);
            }
        }
    }

    /**
     * F398: the online-ish private Blade uses the same-origin approved logo,
     * while the CLOSED offline exporter must embed the exact original PNG.
     * Keep the exported CSP more restrictive (data: only) and never insert
     * external URLs, scripts, forms or browser calls into eleven demo pages.
     */
    private function withEmbeddedApprovedLogo(string $html): string
    {
        $needle='src="/_development/branding/logo-imagine.png"';
        if (substr_count($html,$needle)!==1) {
            throw new LogicException('Approved Imagine logo missing or repeated.');
        }
        $paths=[
            public_path('_development/branding/logo-imagine.png'),
            dirname(base_path(),2).'/assets/logo-imagine.png',
        ];
        $image=null;
        foreach($paths as $path){
            if (!is_file($path)||is_link($path)){
                continue;
            }
            $size=filesize($path);
            if(!is_int($size)||$size<1024||$size>65536){
                throw new LogicException('Approved Imagine image must be a small local PNG.');
            }
            $bytes=file_get_contents($path);
            if(!is_string($bytes)||substr($bytes,0,8)!=="\x89PNG\r\n\x1a\n"){
                throw new LogicException('Approved Imagine image is not PNG.');
            }
            if($image!==null&&!hash_equals(hash('sha256',$image),hash('sha256',$bytes))){
                throw new LogicException('Imagine logo copies differ; refusing offline export.');
            }
            $image=$bytes;
        }
        if(!is_string($image)){
            throw new RuntimeException('Approved Imagine logo is unavailable to offline exporter.');
        }
        $html=str_replace($needle,
            'src="data:image/png;base64,'.base64_encode($image).'"',$html);
        // Online Blade can load same-origin assets, but exported offline
        // pages must remain self-contained under data-only image CSP.
        $live="img-src 'self' data:";
        if(substr_count($html,$live)!==1){
            throw new LogicException('Unexpected online image policy for demo export.');
        }
        return str_replace($live,'img-src data:',$html);
    }

    public static function authorizesCustomerAccess(): bool { return false; }
    public static function enablesWebRoutes(): bool { return false; }
}
