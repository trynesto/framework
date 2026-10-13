<?php

declare(strict_types=1);

namespace Nesto\Prisma\Dmmf;

use Nesto\Prisma\Schema\SchemaLoader;

/**
 * Compiled-schema cache for the runtime: the Document of a schema file is written once as a PHP array file
 * (opcache keeps it in shared memory) and reused until the schema changes, so requests — and FrankenPHP
 * workers — never parse or validate schema.prisma.
 *
 *   $document = DmmfCache::load('prisma/schema.prisma', 'storage/cache/prisma-dmmf.php');
 *   DmmfCache::write($document, 'storage/cache/prisma-dmmf.php', $hashOfTheSchema);
 *
 * The file records a hash of the schema text; load() recompiles when the hash differs (pass
 * `checkSource: false` in production to trust the cache file without reading the schema).
 */
final class DmmfCache
{
    /** Hash identifying a schema text (and the cache format). */
    public static function hash(string $source): string
    {
        return hash('sha256', Document::FORMAT_VERSION . "\0" . $source);
    }

    public static function load(string $schemaFile, string $cacheFile, bool $checkSource = true): Document
    {
        if (! $checkSource && is_file($cacheFile)) {
            $cached = self::read($cacheFile);
            if ($cached !== null) {
                return Document::fromArray($cached['document']);
            }
        }
        $source = (string) @file_get_contents($schemaFile);
        $hash = self::hash($source);
        $cached = is_file($cacheFile) ? self::read($cacheFile) : null;
        if ($cached !== null && $cached['hash'] === $hash) {
            return Document::fromArray($cached['document']);
        }
        $document = SchemaLoader::load($source, $schemaFile);
        self::write($document, $cacheFile, $hash);

        return $document;
    }

    public static function write(Document $document, string $cacheFile, string $hash): void
    {
        $dir = dirname($cacheFile);
        if (! is_dir($dir)) {
            @mkdir($dir, 0777, true);
        }
        $php = "<?php\n\n// Compiled from schema.prisma by Nesto — do not edit.\nreturn " . var_export(['hash' => $hash, 'document' => $document->toArray()], true) . ";\n";
        $temp = $cacheFile . '.' . bin2hex(random_bytes(4)) . '.tmp';
        file_put_contents($temp, $php);
        rename($temp, $cacheFile); // atomic for concurrent workers
        if (function_exists('opcache_invalidate')) {
            @opcache_invalidate($cacheFile, true);
        }
    }

    /** @return array{hash: string, document: array<string, mixed>}|null */
    private static function read(string $cacheFile): ?array
    {
        $data = @include $cacheFile;
        if (! is_array($data) || ! isset($data['hash'], $data['document']) || ($data['document']['nesto']['version'] ?? null) !== Document::FORMAT_VERSION) {
            return null;
        }

        return $data;
    }
}
