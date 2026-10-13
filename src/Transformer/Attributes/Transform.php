<?php

declare(strict_types=1);

namespace Nesto\Transformer\Attributes;

use Attribute;

/**
 * Custom conversion of a property value — class-transformer's @Transform().
 * Attributes cannot hold closures, so pass a static callable tuple or an invokable class name.
 *
 *   #[Transform([Str::class, 'trim'])]                      // Str::trim($value, $source, $key)
 *   public string $name;
 *
 *   #[Transform(MoneyFormatter::class, toPlainOnly: true)]  // (new MoneyFormatter)($value, $source, $key)
 *   public int $cents;
 *
 * The callable receives ($value, $source, $key): $source is the plain array (plainToInstance)
 * or the object (instanceToPlain). Its return value is used as-is (no further casting).
 */
#[Attribute(Attribute::TARGET_PROPERTY)]
final class Transform
{
    /** @param array{0: class-string, 1: string}|class-string|string $callable */
    public function __construct(
        public readonly array|string $callable,
        public readonly bool $toPlainOnly = false,
        public readonly bool $toClassOnly = false,
    ) {}

    /** Resolves the configured callable into something PHP can invoke. */
    public function resolve(): callable
    {
        $c = $this->callable;
        if (is_array($c)) {
            return $c;
        }
        if (class_exists($c) && method_exists($c, '__invoke')) {
            return new $c;
        }
        if (is_callable($c)) {
            return $c;
        }

        throw new \LogicException(sprintf('#[Transform]: "%s" is not a static callable or an invokable class.', $c));
    }
}
