<?php

declare(strict_types=1);

namespace App\Presentation;

use JsonException;
use LogicException;
use RuntimeException;

/**
 * F135: local-only integrity of the fixed synthetic HTML review bundle.
 *
 * SHA-256 here detects accidental corruption, NOT authenticity: an attacker
 * who may replace both a page and manifest can forge a matching digest.
 * This is not a signing system, a deploy gate, or customer authorization.
 */
final class DemoPortalArtifactManifest
{
    private const VERSION = 'imagine.portal.synthetic-preview.manifest.v1';
    private const MANIFEST_FILE = 'manifest.json';
    private const MAX_PAGE_BYTES = 2_097_152;
    private const MAX_MANIFEST_BYTES = 16_384;

    /** @return list<string> */
    public static function pageNames(): array
    {
        $pages = [];
        foreach (ClientSection::cases() as $section) {
            $pages[] = $section->value.'.html';
        }
        $pages[] = 'delivery-review.html';
        $pages[] = 'index.html';

        return $pages;
    }

    /** @param list<string> $pages */
    public function create(string $directory, array $pages): void
    {
        $this->checkEnvironment();
        if ($pages !== self::pageNames()) {
            throw new LogicException('Unexpected synthetic preview page inventory.');
        }
        if (!is_dir($directory) || is_link($directory)) {
            throw new LogicException('Preview staging directory is missing or a symlink.');
        }

        $records = [];
        foreach ($pages as $page) {
            $records[] = $this->inspectPage($directory, $page);
        }

        $document = [
            'schema' => self::VERSION,
            'synthetic_only' => true,
            'authorizes_customer_access' => false,
            'authorizes_deployment' => false,
            'pages' => $records,
        ];
        $payload = json_encode(
            $document,
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT,
        );
        if (strlen($payload) > self::MAX_MANIFEST_BYTES) {
            throw new RuntimeException('Manifest exceeds its byte limit.');
        }

        $file = $directory.DIRECTORY_SEPARATOR.self::MANIFEST_FILE;
        if (file_exists($file) || is_link($file)) {
            throw new LogicException('Synthetic preview manifest already exists.');
        }
        if (file_put_contents($file, $payload."\n", LOCK_EX) === false
            || !chmod($file, 0600)) {
            throw new RuntimeException('Unable to write private manifest.');
        }

        // Validate the *entire* candidate bundle prior to final rename.
        $this->verify($directory);
    }

    public function verify(string $directory): bool
    {
        $this->checkEnvironment();
        if (!is_dir($directory) || is_link($directory)) {
            throw new LogicException('Preview directory is not a regular directory.');
        }
        $actual = scandir($directory);
        if ($actual === false) {
            throw new RuntimeException('Unable to read preview directory.');
        }
        $actual = array_values(array_diff($actual, ['.', '..']));
        $expected = [...self::pageNames(), self::MANIFEST_FILE];
        sort($actual, SORT_STRING);
        sort($expected, SORT_STRING);
        if ($actual !== $expected) {
            throw new LogicException('Preview file inventory mismatch.');
        }

        $manifest = $directory.DIRECTORY_SEPARATOR.self::MANIFEST_FILE;
        if (!is_file($manifest) || is_link($manifest)) {
            throw new LogicException('Manifest must be a regular private file.');
        }
        $size = filesize($manifest);
        if (!is_int($size) || $size < 2 || $size > self::MAX_MANIFEST_BYTES) {
            throw new LogicException('Invalid manifest size.');
        }
        $bytes = file_get_contents($manifest);
        if (!is_string($bytes) || strlen($bytes) !== $size) {
            throw new RuntimeException('Manifest cannot be read completely.');
        }
        try {
            $document = json_decode($bytes, true, 12, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new LogicException('Manifest must be valid JSON.', 0, $exception);
        }
        if (!is_array($document)
            || array_keys($document) !== [
                'schema', 'synthetic_only',
                'authorizes_customer_access', 'authorizes_deployment', 'pages',
            ]
            || $document['schema'] !== self::VERSION
            || $document['synthetic_only'] !== true
            || $document['authorizes_customer_access'] !== false
            || $document['authorizes_deployment'] !== false
            || !is_array($document['pages'])
            || !array_is_list($document['pages'])
            || count($document['pages']) !== count(self::pageNames())) {
            throw new LogicException('Invalid synthetic preview manifest contract.');
        }

        foreach (self::pageNames() as $index => $name) {
            $entry = $document['pages'][$index];
            if (!is_array($entry)
                || array_keys($entry) !== ['name', 'bytes', 'sha256']
                || ($entry['name'] ?? null) !== $name
                || !is_int($entry['bytes'])
                || $entry['bytes'] <= 0
                || $entry['bytes'] > self::MAX_PAGE_BYTES
                || !is_string($entry['sha256'])
                || preg_match('/\A[a-f0-9]{64}\z/D', $entry['sha256']) !== 1) {
                throw new LogicException('Invalid preview manifest entry.');
            }

            if ($entry !== $this->inspectPage($directory, $name)) {
                throw new LogicException('Preview HTML does not match its manifest.');
            }
        }

        return true;
    }

    /** @return array{name:string,bytes:int,sha256:string} */
    private function inspectPage(string $directory, string $name): array
    {
        if (!in_array($name, self::pageNames(), true)) {
            throw new LogicException('Unexpected preview filename.');
        }

        $file = $directory.DIRECTORY_SEPARATOR.$name;
        if (!is_file($file) || is_link($file)) {
            throw new LogicException('Preview page must be a regular file.');
        }
        $length = filesize($file);
        $hash = hash_file('sha256', $file);
        if (!is_int($length) || $length <= 0 || $length > self::MAX_PAGE_BYTES
            || !is_string($hash)) {
            throw new LogicException('Invalid preview page size or digest.');
        }

        return ['name' => $name, 'bytes' => $length, 'sha256' => $hash];
    }

    private function checkEnvironment(): void
    {
        if (!app()->environment(['local', 'testing'])) {
            throw new LogicException('Synthetic preview integrity is local/testing only.');
        }
    }
}
