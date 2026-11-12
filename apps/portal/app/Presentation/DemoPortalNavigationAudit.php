<?php

declare(strict_types=1);

namespace App\Presentation;

use DOMDocument;
use DOMElement;
use DOMXPath;
use LogicException;
use RuntimeException;

/**
 * F136/F352: local-only structural audit of all eleven synthetic HTML pages.
 * Not an accessibility certification, authentication or public crawler.
 */
final class DemoPortalNavigationAudit
{
    public const OFFLINE_CSP = "default-src 'none'; style-src 'unsafe-inline'; img-src data:; font-src 'none'; connect-src 'none'; form-action 'none'; base-uri 'none'; object-src 'none'";
    public function verify(string $directory): bool
    {
        if (!app()->environment(['local', 'testing'])) {
            throw new LogicException('Offline navigation audit is local/testing only.');
        }
        if (!is_dir($directory) || is_link($directory)) {
            throw new LogicException('Offline preview must be a regular private directory.');
        }

        $allowedPages = DemoPortalArtifactManifest::pageNames();
        $allowed = array_fill_keys($allowedPages, true);
        foreach ($allowedPages as $page) {
            $path = $directory.DIRECTORY_SEPARATOR.$page;
            if (!is_file($path) || is_link($path)) {
                throw new LogicException('Offline navigation page is missing or symlinked.');
            }
            $bytes = file_get_contents($path);
            if (!is_string($bytes) || $bytes === '' || strlen($bytes) > 2_097_152) {
                throw new RuntimeException('Offline page cannot be parsed safely.');
            }

            $doc = new DOMDocument('1.0', 'UTF-8');
            $previous = libxml_use_internal_errors(true);
            try {
                $loaded = $doc->loadHTML($bytes, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING);
            } finally {
                libxml_clear_errors();
                libxml_use_internal_errors($previous);
            }
            if (!$loaded) {
                throw new LogicException('Offline page is not parseable HTML.');
            }

            $xpath = new DOMXPath($doc);
            $html = $doc->getElementsByTagName('html')->item(0);
            if (!$html instanceof DOMElement || $html->getAttribute('lang') !== 'pt-BR') {
                throw new LogicException('Offline preview language must be pt-BR.');
            }
            if ($doc->getElementsByTagName('h1')->length !== 1
                || $doc->getElementsByTagName('main')->length !== 1) {
                throw new LogicException('Offline page must contain exactly one title and main landmark.');
            }
            $main = $doc->getElementsByTagName('main')->item(0);
            if (!$main instanceof DOMElement || $main->getAttribute('id') !== 'pi-content') {
                throw new LogicException('Offline main landmark must be the skip-link destination.');
            }
            $robots = $xpath->query('//meta[@name="robots" and @content="noindex,nofollow"]');
            if ($robots === false || $robots->length !== 1) {
                throw new LogicException('Offline preview must explicitly disallow indexing.');
            }
            $policies = $xpath->query('//meta[@http-equiv="Content-Security-Policy"]');
            $policy = $policies === false ? null : $policies->item(0);
            if ($policies === false || $policies->length !== 1
                || !$policy instanceof DOMElement
                || $policy->getAttribute('content') !== self::OFFLINE_CSP) {
                throw new LogicException('Private offline preview must use the restrictive CSP.');
            }
            $referrer = $xpath->query('//meta[@name="referrer" and @content="no-referrer"]');
            if ($referrer === false || $referrer->length !== 1) {
                throw new LogicException('Private offline preview cannot leak referrer context.');
            }
            $active = $xpath->query('//*[self::script or self::form or self::iframe or self::object or self::embed or self::link or self::base or self::input or self::button or self::audio or self::video or self::source]');
            $events = $xpath->query('//@*[starts-with(name(),"on")]');
            $refresh = $xpath->query('//meta[translate(@http-equiv,"ABCDEFGHIJKLMNOPQRSTUVWXYZ","abcdefghijklmnopqrstuvwxyz")="refresh"]');
            if ($active === false || $active->length !== 0
                || $events === false || $events->length !== 0
                || $refresh === false || $refresh->length !== 0) {
                throw new LogicException('Active or externally loaded markup is forbidden.');
            }
            foreach ($doc->getElementsByTagName('img') as $img) {
                if (!$img instanceof DOMElement
                    || !$img->hasAttribute('alt')
                    || preg_match('~\\Adata:image/(?:png|jpeg|webp);base64,[A-Za-z0-9+/=]+\\z~D',
                        $img->getAttribute('src')) !== 1) {
                    throw new LogicException('Offline images must be inert embedded raster data with alt text.');
                }
            }
            // F352: horizontal tables must be reachable by keyboard and
            // announced with the same accessible name as their <caption>.
            // This is a static export contract, not an accessibility certification.
            foreach ($doc->getElementsByTagName('table') as $table) {
                if (!$table instanceof DOMElement) {
                    throw new LogicException('Offline table is malformed.');
                }
                $region = $table->parentNode;
                $caption = $xpath->query('./caption', $table)?->item(0);
                $name = $caption instanceof DOMElement ? trim($caption->textContent) : '';
                if (!$region instanceof DOMElement
                    || !in_array('pi-table-wrap',
                        preg_split('/\s+/', trim($region->getAttribute('class'))) ?: [], true)
                    || $region->getAttribute('role') !== 'region'
                    || $region->getAttribute('tabindex') !== '0'
                    || $name === ''
                    || $region->getAttribute('aria-label') !== $name) {
                    throw new LogicException('Offline data table needs a named, keyboard-focusable scroll region.');
                }
                $headers = $xpath->query('./thead/tr/th', $table);
                if ($headers === false || $headers->length < 1) {
                    throw new LogicException('Offline data table needs column headers.');
                }
                foreach ($headers as $header) {
                    if (!$header instanceof DOMElement
                        || $header->getAttribute('scope') !== 'col'
                        || trim($header->textContent) === '') {
                        throw new LogicException('Offline table headers must have column scope and a name.');
                    }
                }
            }

            if ($doc->getElementsByTagName('nav')->length < 1) {
                throw new LogicException('Offline page lacks a navigation landmark.');
            }

            $links = $doc->getElementsByTagName('a');
            if ($links->length < 1) {
                throw new LogicException('Offline page lacks links for keyboard navigation.');
            }
            $hasSkip = false;
            $hasIndexLink = false;
            $selectedPageLinks = 0;
            foreach ($links as $link) {
                if (!$link instanceof DOMElement || !$link->hasAttribute('href')) {
                    throw new LogicException('Offline anchor must have an explicit destination.');
                }
                $href = $link->getAttribute('href');
                if ($link->hasAttribute('aria-current')) {
                    if ($link->getAttribute('aria-current') !== 'page' || $href !== $page) {
                        throw new LogicException('Offline current-page announcement is inconsistent.');
                    }
                    ++$selectedPageLinks;
                }
                if ($href === '#pi-content') {
                    $hasSkip = true;
                    continue;
                }
                if ($href === 'index.html') {
                    $hasIndexLink = true;
                }
                // Only exact locally generated page names are permitted.
                // No HTTP(S), javascript:, mailto:, fragment smuggling, ../
                // traversal, querystrings, or other arbitrary URLs.
                if (!isset($allowed[$href])) {
                    throw new LogicException('Offline preview contains an unapproved link.');
                }
            }
            if ($selectedPageLinks !== 1) {
                throw new LogicException('Offline page must mark exactly one current destination.');
            }
            if (!$hasSkip) {
                throw new LogicException('Offline page needs a skip-to-main link.');
            }
            if ($page !== 'index.html' && !$hasIndexLink) {
                throw new LogicException('Offline page needs a route to the local index.');
            }
        }
        return true;
    }
}
