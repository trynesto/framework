<?php

declare(strict_types=1);

namespace Nesto\Http;

/**
 * Form bodies of PUT / PATCH / DELETE requests. PHP fills $_POST and $_FILES for POST only, so
 * Request::fromGlobals() parses the other methods here: with request_parse_body() on PHP 8.4+ (FrankenPHP),
 * else with the built-in parser below (PHP 8.3: php -S, FPM). Both return PHP's ($_POST, $_FILES) shapes.
 */
final class FormBodyParser
{
    /** @var list<string> temp files of uploads parsed here, removed at the end of the request */
    private static array $tempFiles = [];

    /**
     * Parse the current request body (php://input).
     *
     * @return array{0: array<string, mixed>, 1: array<string, mixed>} [post, files]
     */
    public static function fromGlobals(string $contentType): array
    {
        if (function_exists('request_parse_body')) {
            try {
                return request_parse_body();
            } catch (\Throwable) {
                return [[], []]; // over post_max_size & co: RouteHandler turns the missing body into a 413
            }
        }
        $limit = self::iniBytes((string) ini_get('post_max_size'));
        $length = (int) ($_SERVER['CONTENT_LENGTH'] ?? 0);
        if ($limit > 0 && $length > $limit) {
            return [[], []];
        }

        return self::parse((string) file_get_contents('php://input'), $contentType);
    }

    /**
     * @return array{0: array<string, mixed>, 1: array<string, mixed>} [post, files] for a multipart/form-data or
     *         application/x-www-form-urlencoded body
     */
    public static function parse(string $body, string $contentType): array
    {
        $type = strtolower(trim(explode(';', $contentType, 2)[0]));
        if ($type === 'application/x-www-form-urlencoded') {
            parse_str($body, $post);

            return [$post, []];
        }
        if ($type !== 'multipart/form-data' || ! preg_match('/boundary=(?:"([^"]+)"|([^;\s]+))/i', $contentType, $m)) {
            return [[], []];
        }

        return self::parseMultipart($body, $m[1] !== '' ? $m[1] : $m[2]);
    }

    /** @return array{0: array<string, mixed>, 1: array<string, mixed>} */
    private static function parseMultipart(string $body, string $boundary): array
    {
        $fields = [];
        $files = [];
        $maxFiles = (int) (ini_get('max_file_uploads') ?: 20);
        $maxSize = self::iniBytes((string) ini_get('upload_max_filesize'));
        $fileCount = 0;

        $parts = explode('--' . $boundary, $body);
        array_shift($parts); // preamble
        foreach ($parts as $part) {
            if (str_starts_with($part, '--')) {
                break; // closing delimiter
            }
            $part = str_starts_with($part, "\r\n") ? substr($part, 2) : (str_starts_with($part, "\n") ? substr($part, 1) : $part);
            $split = preg_split('/\r?\n\r?\n/', $part, 2);
            if (count($split) !== 2) {
                continue;
            }
            [$rawHeaders, $content] = $split;
            $content = str_ends_with($content, "\r\n") ? substr($content, 0, -2) : (str_ends_with($content, "\n") ? substr($content, 0, -1) : $content);
            $headers = [];
            foreach (preg_split('/\r?\n/', $rawHeaders) ?: [] as $line) {
                if (str_contains($line, ':')) {
                    [$name, $value] = explode(':', $line, 2);
                    $headers[strtolower(trim($name))] = trim($value);
                }
            }
            $disposition = self::dispositionParams($headers['content-disposition'] ?? '');
            $name = $disposition['name'] ?? null;
            if ($name === null || $name === '') {
                continue;
            }
            if (! array_key_exists('filename', $disposition)) {
                $fields[] = rawurlencode($name) . '=' . rawurlencode($content);
                continue;
            }
            if (++$fileCount > $maxFiles) {
                continue;
            }
            $spec = ['name' => $disposition['filename'], 'full_path' => $disposition['filename'], 'type' => $headers['content-type'] ?? '', 'tmp_name' => '', 'error' => UPLOAD_ERR_OK, 'size' => 0];
            if ($disposition['filename'] === '' && $content === '') {
                $spec['error'] = UPLOAD_ERR_NO_FILE;
            } elseif ($maxSize > 0 && strlen($content) > $maxSize) {
                $spec['error'] = UPLOAD_ERR_INI_SIZE;
            } elseif (($tmp = self::tempFile($content)) === null) {
                $spec['error'] = UPLOAD_ERR_CANT_WRITE;
            } else {
                $spec['tmp_name'] = $tmp;
                $spec['size'] = strlen($content);
            }
            self::setNested($files, $name, $spec);
        }
        parse_str(implode('&', $fields), $post);

        return [$post, $files];
    }

    /** @return array<string, string> lower-cased parameter => value of a Content-Disposition header */
    private static function dispositionParams(string $header): array
    {
        $params = [];
        preg_match_all('/;\s*([a-zA-Z0-9_*-]+)\s*=\s*(?:"((?:[^"\\\\]|\\\\.)*)"|([^;]*))/', $header, $matches, PREG_SET_ORDER);
        foreach ($matches as $m) {
            $value = isset($m[3]) && $m[3] !== '' ? trim($m[3]) : stripcslashes($m[2]);
            $params[strtolower($m[1])] = $value;
        }

        return $params;
    }

    /** `photos[]` / `docs[main]` → nested keys like PHP does for $_FILES (one spec per leaf, PSR-7 friendly). */
    private static function setNested(array &$tree, string $name, array $spec): void
    {
        if (! preg_match('/^([^\[]+)((?:\[[^\]]*\])*)$/', $name, $m)) {
            $tree[$name] = $spec;

            return;
        }
        preg_match_all('/\[([^\]]*)\]/', $m[2], $keys);
        $node = &$tree;
        foreach ([$m[1], ...$keys[1]] as $key) {
            if ($key === '') {
                $node[] = [];
                $key = array_key_last($node);
            }
            if (! isset($node[$key]) || ! is_array($node[$key]) || isset($node[$key]['tmp_name'])) {
                $node[$key] = [];
            }
            $node = &$node[$key];
        }
        $node = $spec;
    }

    private static function tempFile(string $content): ?string
    {
        $path = tempnam(sys_get_temp_dir(), 'php');
        if ($path === false || file_put_contents($path, $content) === false) {
            return null;
        }
        if (self::$tempFiles === []) {
            register_shutdown_function(static function (): void {
                foreach (self::$tempFiles as $file) {
                    @unlink($file);
                }
                self::$tempFiles = [];
            });
        }
        self::$tempFiles[] = $path;

        return $path;
    }

    private static function iniBytes(string $value): int
    {
        $value = trim($value);
        $number = (int) $value;

        return match (strtolower(substr($value, -1))) {
            'g' => $number << 30,
            'm' => $number << 20,
            'k' => $number << 10,
            default => $number,
        };
    }
}
