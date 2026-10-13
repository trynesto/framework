<?php

declare(strict_types=1);

namespace Nesto\Orm\Attributes;

use Attribute;

/**
 * Entity listener (TypeORM's @BeforeInsert): Runs before the entity is INSERTed by save(); changes it makes are written.
 *
 *   #[BeforeInsert] public function onEvent(): void { ... }
 *
 * The method may have any visibility; listeners of parent classes run first. Disabled by save($e, ['listeners' => false]).
 */
#[Attribute(Attribute::TARGET_METHOD)]
final class BeforeInsert {}
