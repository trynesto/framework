<?php

declare(strict_types=1);

namespace Nesto\Auth;

/**
 * Password hashing facade over password_hash() (bcrypt by default, argon2id when available and selected).
 *
 *   $hash = Hash::make('secret');
 *   Hash::check('secret', $hash);              // true
 *   if (Hash::needsRehash($hash)) { ... }      // algorithm / cost changed since the hash was made
 *   Hash::useArgon2id();                       // switch the default algorithm
 *   Hash::useBcrypt(cost: 12);
 */
final class Hash
{
    private static string $algo = PASSWORD_BCRYPT;
    /** @var array<string, int> */
    private static array $options = [];

    public static function make(string $password, array $options = []): string
    {
        $hash = password_hash($password, self::$algo, $options + self::$options);
        if (! is_string($hash)) {
            throw new \RuntimeException('Hashing failed.');
        }

        return $hash;
    }

    public static function check(string $password, ?string $hash): bool
    {
        if ($hash === null || $hash === '') {
            return false;
        }

        return password_verify($password, $hash);
    }

    public static function needsRehash(string $hash, array $options = []): bool
    {
        return password_needs_rehash($hash, self::$algo, $options + self::$options);
    }

    /** @return array{algo: ?string, algoName: string, options: array} */
    public static function info(string $hash): array
    {
        return password_get_info($hash);
    }

    public static function useBcrypt(int $cost = 10): void
    {
        self::$algo = PASSWORD_BCRYPT;
        self::$options = ['cost' => $cost];
    }

    public static function useArgon2id(array $options = []): void
    {
        if (! defined('PASSWORD_ARGON2ID')) {
            throw new \RuntimeException('argon2id is not available in this PHP build.');
        }
        self::$algo = PASSWORD_ARGON2ID;
        self::$options = $options;
    }

    public static function algorithm(): string
    {
        return self::$algo;
    }
}
