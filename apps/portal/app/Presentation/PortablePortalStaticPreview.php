<?php

declare(strict_types=1);

namespace App\Presentation;

use DOMDocument;
use DOMElement;
use LogicException;
use RuntimeException;
use Throwable;

/**
 * Generates a visual-only OFFLINE file:// preview of ten synthetic Blade
 * screens. No HTTP routes, CRM/Sites connectors, customer identity or writes.
 *
 * This class is deliberately unavailable outside local/testing environments.
 */
final class PortablePortalStaticPreview
{
    private const ORIGINAL_LOGO_BLOB = '678adeceb3a8ecdaeb6b576b9ee18d1bf0396f77';
    private const ORIGINAL_LOGO_SIZE = 5594;

    /** @return list<string> */
    private static function pageNames(): array
    {
        $pages = array_map(
            static fn (ClientSection $section): string => $section->value.'.html',
            ClientSection::cases(),
        );
        // The existing help/notices views are informational examples, not
        // authenticated ClientSection entries or customer navigation routes.
        array_push($pages, 'help.html', 'notices.html');
        return $pages;
    }

    /** @return list<string> */
    public function export(string $directory): array
    {
        if (!app()->environment(['local', 'testing'])) {
            throw new LogicException('Portable preview requires a disposable local/testing environment.');
        }
        if ($directory === '' || file_exists($directory) || is_dir($directory) || is_link($directory)) {
            throw new LogicException('Portable preview destination must not already exist.');
        }

        $logo = public_path('assets/logo-imagine.png');
        if (!is_file($logo) || is_link($logo) || filesize($logo) !== self::ORIGINAL_LOGO_SIZE) {
            throw new LogicException('Official Imagine logo asset is missing or unexpected.');
        }
        $bytes = file_get_contents($logo);
        if (!is_string($bytes) || strlen($bytes) !== self::ORIGINAL_LOGO_SIZE
            || sha1('blob '.strlen($bytes)."\0".$bytes) !== self::ORIGINAL_LOGO_BLOB) {
            throw new LogicException('Official Imagine logo does not match the approved Node-era asset.');
        }
        $logoDataUrl = 'data:image/png;base64,'.base64_encode($bytes);

        $staging = $directory.'.staging-'.bin2hex(random_bytes(12));
        if (!mkdir($staging, 0700) || !chmod($staging, 0700)) {
            throw new RuntimeException('Unable to create a private offline staging directory.');
        }
        try {
            $names = self::pageNames();
            $known = [...$names, 'index.html'];
            $nav = [];
            $records = [];

            foreach ($names as $name) {
                $html = view('portal.demo.'.substr($name, 0, -5))->render();
                if (!str_contains($html, '<body>') || !str_contains($html, 'noindex,nofollow')) {
                    throw new LogicException('Synthetic view was not rendered as an offline page.');
                }
                $replacements = 0;
                $html = str_replace(
                    'src="/assets/logo-imagine.png"',
                    'src="'.$logoDataUrl.'"',
                    $html,
                    $replacements,
                );
                if ($replacements !== 1) {
                    throw new LogicException('A single local official logo is mandatory.');
                }

                $html = $this->linkOfflineSidebar($html, $known);
                $html = str_replace('<body>', '<body>'.$this->navigation($known, $name), $html);
                $this->assertOfflineOnly($html, $known, $logoDataUrl);
                $this->writePage($staging, $name, $html);
                $records[] = $this->record($name, $html);
            }

            $index = $this->index($known);
            $this->assertOfflineOnly($index, $known, null);
            $this->writePage($staging, 'index.html', $index);
            $records[] = $this->record('index.html', $index);

            $manifest = [
                'schema' => 'imagine-portal-visual-only-preview-v1',
                'synthetic_only' => true,
                'logo_git_blob_sha1' => self::ORIGINAL_LOGO_BLOB,
                'authorizes_customer_access' => false,
                'authorizes_payment' => false,
                'authorizes_deployment' => false,
                'pages' => $records,
            ];
            $json = json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
            if (!is_string($json)) {
                throw new RuntimeException('Cannot encode offline manifest.');
            }
            $this->writePage($staging, 'manifest.json', $json."\n");

            $actual = array_values(array_diff(scandir($staging) ?: [], ['.', '..']));
            $expected = [...$known, 'manifest.json'];
            sort($actual, SORT_STRING);
            sort($expected, SORT_STRING);
            if ($actual !== $expected) {
                throw new LogicException('Offline preview inventory mismatch.');
            }
            if (file_exists($directory) || is_link($directory) || !rename($staging, $directory)) {
                throw new RuntimeException('Preview destination changed during final publish.');
            }
            return $known;
        } catch (Throwable $e) {
            foreach (scandir($staging) ?: [] as $entry) {
                if ($entry !== '.' && $entry !== '..' && is_file($staging.'/'.$entry)) {
                    @unlink($staging.'/'.$entry);
                }
            }
            @rmdir($staging);
            throw $e;
        }
    }

    /**
     * Only the exported file:// bundle can navigate between static pages.
     * The original Blade app remains inert, without an auth/customer route.
     * @param list<string> $known
     */
    private function linkOfflineSidebar(string $html, array $known): string
    {
        $seen = [];
        $linked = preg_replace_callback(
            '~<span data-preview-section="([a-z-]+)"([^>]*)>([^<]*)</span>~u',
            static function (array $match) use ($known, &$seen): string {
                $section = ClientSection::tryFrom($match[1]);
                $target = $match[1].'.html';
                if ($section === null || !in_array($target, $known, true)
                    || isset($seen[$match[1]])) {
                    throw new LogicException('Unknown or duplicated sidebar destination.');
                }
                $seen[$match[1]] = true;
                return '<a href="'.$target.'" data-preview-section="'.$match[1].'"'
                    .$match[2].'>'.$match[3].'</a>';
            },
            $html,
            -1,
            $count,
        );
        if (!is_string($linked) || $count !== count(ClientSection::cases())
            || count($seen) !== count(ClientSection::cases())) {
            throw new LogicException('Incomplete offline sidebar navigation.');
        }
        $note = 'Navegação ilustrativa. As opções não abrem outras telas.';
        if (substr_count($linked, $note) !== 1) {
            throw new LogicException('Unexpected sidebar explanation.');
        }
        return str_replace(
            $note,
            'Prévia offline: abra somente as páginas locais. Nenhum acesso real está disponível.',
            $linked,
        );
    }

    /** @param list<string> $known */
    private function navigation(array $known, string $current): string
    {
        $labels = ['index.html' => 'Índice'];
        foreach (ClientSection::cases() as $section) {
            $labels[$section->value.'.html'] = $section->label();
        }
        $labels['help.html'] = 'Central de Ajuda';
        $labels['notices.html'] = 'Central de Avisos';
        $links = '';
        foreach ($known as $name) {
            $label = htmlspecialchars($labels[$name], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
            $selected = $name === $current;
            $active = $selected ? ' aria-current="page"' : '';
            $colors = $selected
                ? 'background:#fff1e0;border-color:#f9941d;font-weight:700;'
                : 'background:#fff;border-color:#e8e8e8;font-weight:500;';
            $links .= '<a href="'.$name.'"'.$active
                .' style="display:inline-block;flex:none;white-space:nowrap;'
                .'padding:7px 10px;border:1px solid;border-radius:8px;'
                .'color:#994b00;text-decoration:none;font:12px/1.5 Arial,sans-serif;'
                .$colors.'">'.$label.'</a>';
        }
        return '<nav class="pi-offline-nav" aria-label="Navegação exclusivamente offline" '
            .'style="box-sizing:border-box;max-width:100%;width:100%;display:flex;'
            .'flex-wrap:nowrap;align-items:center;gap:8px;overflow-x:auto;'
            .'overscroll-behavior-x:contain;scrollbar-width:thin;padding:10px 12px;'
            .'background:#fff;border-bottom:2px solid #f9941d">'
            .'<strong style="color:#646b65;font:700 11px Arial,sans-serif;'
            .'white-space:nowrap;flex:none">Prévia offline</strong>'
            .$links.'</nav>';
    }

    /** @param list<string> $known */
    private function index(array $known): string
    {
        $links = '';
        foreach (ClientSection::cases() as $section) {
            $name = $section->value.'.html';
            $label = htmlspecialchars($section->label(), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
            $links .= '<li><a href="'.$name.'">'.$label.'</a></li>';
        }
        $links .= '<li><a href="help.html">Central de Ajuda</a></li>'
            .'<li><a href="notices.html">Central de Avisos</a></li>';
        return '<!DOCTYPE html><html lang="pt-BR"><head><meta charset="utf-8">'
            .'<meta name="viewport" content="width=device-width,initial-scale=1">'
            .'<meta name="robots" content="noindex,nofollow">'
            .'<meta name="referrer" content="no-referrer">'
            .'<meta http-equiv="Content-Security-Policy" content="default-src &#039;none&#039;; '
            .'img-src data:; style-src &#039;unsafe-inline&#039;; form-action &#039;none&#039;">'
            .'<title>Imagine — prévia offline da identidade original</title>'
            .'<style>body{font:15px/1.6 Arial,sans-serif;background:#f7f7f8;color:#171b19;'
            .'max-width:800px;margin:35px auto;padding:20px}main{background:#fff;padding:28px;'
            .'border:1px solid #e8e8e8;border-top:5px solid #f9941d;border-radius:10px}'
            .'a{color:#994b00}li{margin:10px 0}a:focus-visible{outline:3px solid #994b00}</style>'
            .'</head><body><a href="#pi-content">Pular para o conteúdo</a>'
            .$this->navigation($known, 'index.html')
            .'<main id="pi-content"><h1>Imagine Portal · prévia visual</h1>'
            .'<p>Esta é uma prévia <strong>offline e fictícia</strong> da identidade original Imagine. '
            .'Não há login, contratos assinados, clientes, pagamentos ou publicação de sites.</p>'
            .'<ul>'.$links.'</ul></main></body></html>';
    }

    /** @param list<string> $known */
    private function assertOfflineOnly(string $html, array $known, ?string $expectedLogo): void
    {
        if (strlen($html) > 1_000_000 || str_contains($html, 'http://')
            || str_contains($html, 'https://')
            || preg_match('~<\s*(?:script|form|input|button|iframe|object|embed)\b~i', $html)) {
            throw new LogicException('Unsafe element, external URL or oversized synthetic page.');
        }
        $document = new DOMDocument('1.0', 'UTF-8');
        $prior = libxml_use_internal_errors(true);
        try {
            if (!$document->loadHTML('<?xml encoding="UTF-8">'.$html, LIBXML_NONET)) {
                throw new LogicException('Cannot parse offline HTML.');
            }
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($prior);
        }
        if ($document->getElementsByTagName('main')->length !== 1
            || $document->getElementsByTagName('h1')->length !== 1
            || $document->getElementsByTagName('title')->length !== 1) {
            throw new LogicException('Offline preview requires exactly one main, heading and title.');
        }
        // A keyboard user must be able to reach every wide table's labelled
        // scrolling region. Export cannot silently ship inaccessible tables.
        foreach ($document->getElementsByTagName('table') as $table) {
            if (!$table instanceof DOMElement) {
                throw new LogicException('Invalid offline table.');
            }
            $region = $table->parentNode;
            $caption = $table->getElementsByTagName('caption')->item(0);
            $headers = $table->getElementsByTagName('th');
            $classes = $region instanceof DOMElement
                ? (preg_split('/\s+/', trim($region->getAttribute('class'))) ?: [])
                : [];
            if (!$region instanceof DOMElement
                || !in_array('pi-table-wrap', $classes, true)
                || $region->getAttribute('role') !== 'region'
                || $region->getAttribute('tabindex') !== '0'
                || !$caption instanceof DOMElement
                || trim($caption->textContent) === ''
                || $region->getAttribute('aria-label') !== trim($caption->textContent)
                || $headers->length < 1) {
                throw new LogicException('Offline table requires a named keyboard-focusable region.');
            }
            foreach ($headers as $header) {
                if (!$header instanceof DOMElement
                    || $header->getAttribute('scope') !== 'col'
                    || trim($header->textContent) === '') {
                    throw new LogicException('Offline table has missing column header semantics.');
                }
            }
        }

        $images = $document->getElementsByTagName('img');
        if ($images->length !== ($expectedLogo === null ? 0 : 1)) {
            throw new LogicException('Offline preview has an unexpected image.');
        }
        if ($expectedLogo !== null && (!$images->item(0) instanceof DOMElement
            || $images->item(0)->getAttribute('src') !== $expectedLogo)) {
            throw new LogicException('Offline preview image is not the original logo.');
        }
        foreach (['a' => 'href', 'link' => 'href', 'source' => 'src', 'video' => 'src'] as $tag => $attribute) {
            foreach ($document->getElementsByTagName($tag) as $node) {
                if (!$node instanceof DOMElement || !$node->hasAttribute($attribute)) {
                    continue;
                }
                $value = $node->getAttribute($attribute);
                if ($tag !== 'a' || ($value !== '#pi-content' && !in_array($value, $known, true))) {
                    throw new LogicException('Unapproved external or broken offline reference.');
                }
            }
        }
    }

    private function writePage(string $directory, string $name, string $payload): void
    {
        if (preg_match('/\A[a-z0-9-]+\.(?:html|json)\z/D', $name) !== 1) {
            throw new LogicException('Unapproved offline filename.');
        }
        $filename = $directory.'/'.$name;
        if (file_exists($filename) || is_link($filename)
            || file_put_contents($filename, $payload, LOCK_EX) !== strlen($payload)
            || !chmod($filename, 0600)) {
            throw new RuntimeException('Cannot write a private offline preview page.');
        }
    }

    /** @return array{name:string,bytes:int,sha256:string} */
    private function record(string $name, string $html): array
    {
        return ['name' => $name, 'bytes' => strlen($html), 'sha256' => hash('sha256', $html)];
    }

    public static function enablesWebRoutes(): bool { return false; }
    public static function authorizesCustomerAccess(): bool { return false; }
}
