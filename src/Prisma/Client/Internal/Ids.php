<?php

declare(strict_types=1);

namespace Nesto\Prisma\Client\Internal;

/**
 * Client-side id defaults: uuid() (v4), uuid(7), cuid() (v1 layout: "c" + time + counter + fingerprint +
 * random, 25 chars), cuid(2) (24 chars), nanoid(n) and ulid(). Stateless apart from a process-wide cuid
 * counter, which is fine for workers (only uniqueness matters).
 *
 * @internal
 */
final class Ids
{
    private const BASE36 = '0123456789abcdefghijklmnopqrstuvwxyz';
    private const NANOID = '_-0123456789abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ';
    private const CROCKFORD = '0123456789ABCDEFGHJKMNPQRSTVWXYZ';

    private static int $counter = -1;

    public static function uuid4(): string
    {
        $bytes = random_bytes(16);
        $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
        $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);

        return self::formatUuid($bytes);
    }

    public static function uuid7(): string
    {
        $ms = (int) floor(microtime(true) * 1000);
        $bytes = substr(pack('J', $ms), 2, 6) . random_bytes(10);
        $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x70);
        $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);

        return self::formatUuid($bytes);
    }

    private static function formatUuid(string $bytes): string
    {
        $hex = bin2hex($bytes);

        return substr($hex, 0, 8) . '-' . substr($hex, 8, 4) . '-' . substr($hex, 12, 4) . '-' . substr($hex, 16, 4) . '-' . substr($hex, 20, 12);
    }

    public static function cuid(): string
    {
        if (self::$counter < 0) {
            self::$counter = random_int(0, 36 ** 4 - 1);
        }
        self::$counter = (self::$counter + 1) % (36 ** 4);
        $time = base_convert((string) (int) floor(microtime(true) * 1000), 10, 36);
        $fingerprint = str_pad(base_convert((string) (getmypid() ?: random_int(0, 1295)), 10, 36), 2, '0', STR_PAD_LEFT)
            . str_pad(base_convert((string) (crc32((string) gethostname()) % 1296), 10, 36), 2, '0', STR_PAD_LEFT);

        return 'c' . str_pad($time, 8, '0', STR_PAD_LEFT)
            . str_pad(base_convert((string) self::$counter, 10, 36), 4, '0', STR_PAD_LEFT)
            . substr($fingerprint, -4)
            . self::randomBase36(8);
    }

    public static function cuid2(int $length = 24): string
    {
        $letter = self::BASE36[random_int(10, 35)];
        $hash = base_convert(substr(hash('sha3-512', microtime() . random_bytes(32) . (string) getmypid()), 0, 15), 16, 36)
            . base_convert(substr(hash('sha3-512', random_bytes(32)), 0, 15), 16, 36);

        return $letter . substr(str_pad($hash, $length, self::randomBase36($length)), 0, $length - 1);
    }

    public static function nanoid(int $size = 21): string
    {
        $id = '';
        $bytes = random_bytes($size);
        for ($i = 0; $i < $size; $i++) {
            $id .= self::NANOID[ord($bytes[$i]) & 63];
        }

        return $id;
    }

    public static function ulid(): string
    {
        $ms = (int) floor(microtime(true) * 1000);
        $time = '';
        for ($i = 0; $i < 10; $i++) {
            $time = self::CROCKFORD[$ms % 32] . $time;
            $ms = intdiv($ms, 32);
        }
        $random = '';
        $bytes = random_bytes(16);
        for ($i = 0; $i < 16; $i++) {
            $random .= self::CROCKFORD[ord($bytes[$i]) & 31];
        }

        return $time . $random;
    }

    private static function randomBase36(int $length): string
    {
        $out = '';
        for ($i = 0; $i < $length; $i++) {
            $out .= self::BASE36[random_int(0, 35)];
        }

        return $out;
    }
}
