<?php

declare(strict_types=1);

namespace App\Presentation;

use DOMDocument;
use DOMElement;
use DOMXPath;
use LogicException;
use RuntimeException;

/**
 * F136: local-only structural audit of the nine synthetic HTML pages.
 * Not an accessibility certification, authentication or public crawler.
 */
final class DemoPortalNavigationAudit
{
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
            if ($doc->getElementsByTagName('nav')->length < 1) {
                throw new LogicException('Offline page lacks a navigation landmark.');
            }

            $links = $doc->getElementsByTagName('a');
            if ($links->length < 1) {
                throw new LogicException('Offline page lacks links for keyboard navigation.');
            }
            $hasSkip = false;
            $hasIndexLink = false;
            foreach ($links as $link) {
                if (!$link instanceof DOMElement || !$link->hasAttribute('href')) {
                    throw new LogicException('Offline anchor must have an explicit destination.');
                }
                $href = $link->getAttribute('href');
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
