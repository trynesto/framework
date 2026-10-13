<?php

declare(strict_types=1);

namespace Nesto\Prisma\Dmmf;

use Nesto\Prisma\PrismaException;

/**
 * A datasource / generator value that is either literal (`"file:./dev.db"`) or read from the environment
 * (`env("DATABASE_URL")`) — Prisma's `{ value, fromEnvVar }`.
 */
final class EnvValue
{
    public function __construct(public readonly ?string $value, public readonly ?string $fromEnvVar = null) {}

    /** The value now: the literal, or the environment variable ($_ENV, $_SERVER, getenv). */
    public function resolve(): ?string
    {
        if ($this->fromEnvVar === null) {
            return $this->value;
        }
        $value = $_ENV[$this->fromEnvVar] ?? $_SERVER[$this->fromEnvVar] ?? getenv($this->fromEnvVar);

        return is_string($value) && $value !== '' ? $value : null;
    }

    /** resolve(), or Prisma's error when the environment variable is missing. */
    public function require(string $what = 'datasource url'): string
    {
        $value = $this->resolve();
        if ($value === null) {
            throw new PrismaException($this->fromEnvVar !== null
                ? "Environment variable not found: {$this->fromEnvVar}."
                : "The $what is empty.");
        }

        return $value;
    }

    /** @return array{value: ?string, fromEnvVar: ?string} */
    public function toArray(): array
    {
        return ['value' => $this->value, 'fromEnvVar' => $this->fromEnvVar];
    }

    /** @param array{value?: ?string, fromEnvVar?: ?string}|null $data */
    public static function fromArray(?array $data): ?self
    {
        return $data === null ? null : new self($data['value'] ?? null, $data['fromEnvVar'] ?? null);
    }
}
