<?php

declare(strict_types=1);

namespace Nesto\Http;

/**
 * The rules behind #[IsFile] / #[IsImage] (DTO properties) and #[UploadedFile('x', maxSize: …, types: …)].
 *
 *   new FileRules(maxSize: '5MB', types: ['jpg', 'png'])   // extensions…
 *   new FileRules(types: 'image/*')                        // …or MIME patterns
 *   new FileRules(image: true, maxFiles: 10)               // any image, up to 10 files
 *
 * Types are checked against the type detected from the content, never the client's claim.
 */
final class FileRules
{
    public const IMAGE_TYPES = ['jpg', 'png', 'gif', 'webp', 'avif'];

    /** Image extensions #[IsImage(types: …)] accepts (svg only when asked for explicitly). */
    private const KNOWN_IMAGES = ['jpg', 'png', 'gif', 'webp', 'avif', 'bmp', 'tiff', 'heic', 'heif', 'ico', 'svg'];

    /** Plain-text formats look identical by content (text/plain): for these the extension decides. */
    private const TEXT_MIMES = ['text/plain', 'text/csv', 'application/csv'];
    private const TEXT_EXTENSIONS = ['txt', 'csv', 'tsv', 'md', 'markdown', 'log'];

    private const ALIASES = ['jpeg' => 'jpg', 'jpe' => 'jpg', 'tif' => 'tiff', 'htm' => 'html'];

    /** @param list<string>|string|null $types extensions ("pdf") or MIME patterns ("image/*", "application/pdf") */
    public function __construct(
        public readonly int|string|null $maxSize = null,
        public readonly array|string|null $types = null,
        public readonly bool $image = false,
        public readonly ?int $maxFiles = null,
        public readonly ?int $minFiles = null,
    ) {}

    public function isEmpty(): bool
    {
        return $this->maxSize === null && $this->types === null && ! $this->image && $this->maxFiles === null && $this->minFiles === null;
    }

    /**
     * Messages for $value (a File or a list of Files) under $field; [] when valid.
     *
     * @return list<string>
     */
    public function check(mixed $value, string $field): array
    {
        if ($value === null) {
            return ["$field is required"];
        }
        if ($value === []) { // an empty list is valid unless minFiles asks for some
            return $this->minFiles !== null && $this->minFiles > 0
                ? [sprintf('%s must have at least %d file%s', $field, $this->minFiles, $this->minFiles === 1 ? '' : 's')]
                : [];
        }
        $many = is_array($value);
        $files = $many ? array_values($value) : [$value];
        $errors = [];
        if ($this->maxFiles !== null && count($files) > $this->maxFiles) {
            $errors[] = sprintf('%s must not have more than %d file%s', $field, $this->maxFiles, $this->maxFiles === 1 ? '' : 's');
        }
        if ($this->minFiles !== null && count($files) < $this->minFiles) {
            $errors[] = sprintf('%s must have at least %d file%s', $field, $this->minFiles, $this->minFiles === 1 ? '' : 's');
        }

        $types = $this->allowedTypes();
        $maxBytes = $this->maxSize === null ? null : self::bytes($this->maxSize);
        foreach ($files as $i => $file) {
            $label = $many ? "$field.$i" : $field;
            if (! $file instanceof File) {
                $errors[] = "$label must be a file";
                continue;
            }
            if (! $file->isValid()) {
                $errors[] = "$label failed to upload";
                continue;
            }
            if ($types !== null && ! $this->matches($file, $types)) {
                $errors[] = $this->image
                    ? sprintf('%s must be an image (%s)', $label, implode(', ', $types))
                    : sprintf('%s must be a file of type: %s', $label, implode(', ', $types));
            }
            if ($maxBytes !== null && $file->size() > $maxBytes) {
                $errors[] = sprintf('%s must not be larger than %s', $label, self::human($maxBytes));
            }
        }

        return $errors;
    }

    /** "5MB", "500 KB", "1.5G", "2048" or 2048 → bytes. */
    public static function bytes(int|string $size): int
    {
        if (is_int($size)) {
            return $size;
        }
        if (! preg_match('/^\s*(\d+(?:\.\d+)?)\s*([kmgt]?)i?b?\s*$/i', $size, $m)) {
            throw new \InvalidArgumentException("Invalid file size \"$size\" (use bytes or e.g. \"500KB\", \"5MB\").");
        }
        $power = ['' => 0, 'k' => 1, 'm' => 2, 'g' => 3, 't' => 4][strtolower($m[2])];

        return (int) round((float) $m[1] * (1024 ** $power));
    }

    public static function human(int $bytes): string
    {
        foreach (['GB' => 1 << 30, 'MB' => 1 << 20, 'KB' => 1 << 10] as $unit => $size) {
            if ($bytes >= $size) {
                return rtrim(rtrim(number_format($bytes / $size, 1, '.', ''), '0'), '.') . " $unit";
            }
        }

        return "$bytes bytes";
    }

    /** @return list<string>|null */
    private function allowedTypes(): ?array
    {
        if ($this->types === null) {
            return $this->image ? self::IMAGE_TYPES : null;
        }
        $types = array_values(array_map(
            static fn (string $t) => self::ALIASES[strtolower(ltrim(trim($t), '.'))] ?? strtolower(ltrim(trim($t), '.')),
            is_array($this->types) ? $this->types : explode(',', str_replace('|', ',', $this->types)),
        ));
        if ($this->image) {
            $wrong = array_filter($types, static fn (string $t) => ! in_array($t, self::KNOWN_IMAGES, true) && ! str_starts_with($t, 'image/'));
            if ($wrong !== []) {
                throw new \InvalidArgumentException(sprintf(
                    'IsImage only accepts image types, got: %s. Use #[IsFile(types: [%s])] for other files.',
                    implode(', ', $wrong),
                    implode(', ', array_map(static fn (string $t) => "'$t'", $types)),
                ));
            }
        }

        return $types;
    }

    /** @param list<string> $types */
    private function matches(File $file, array $types): bool
    {
        $mime = $file->mimeType();
        $detected = $file->detectedExtension(); // from the content only: an unknown type never matches by name
        $extension = $detected === null ? null : (self::ALIASES[$detected] ?? $detected);
        if ($this->image && ! $file->isImage()) {
            return false;
        }
        foreach ($types as $type) {
            if (str_contains($type, '/')) {
                if (preg_match('#^' . str_replace('\*', '[^/]+', preg_quote($type, '#')) . '$#i', $mime)) {
                    return true;
                }
            } elseif ($type === $extension) {
                return true;
            } elseif (in_array($type, self::TEXT_EXTENSIONS, true) && in_array($mime, self::TEXT_MIMES, true) && $type === self::clientExtension($file)) {
                return true; // csv / md / txt: same bytes, so the name decides (a .php is text/x-php and never gets here)
            }
        }

        return false;
    }

    private static function clientExtension(File $file): string
    {
        $extension = strtolower(pathinfo($file->originalName(), PATHINFO_EXTENSION));

        return self::ALIASES[$extension] ?? $extension;
    }
}
