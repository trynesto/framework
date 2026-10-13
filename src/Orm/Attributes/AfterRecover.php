<?php

declare(strict_types=1);

namespace Nesto\Orm\Attributes;

use Attribute;

/**
 * Entity listener (TypeORM's @AfterRecover): Runs after recover() cleared the entity's DeleteDateColumn.
 *
 *   #[AfterRecover] public function onEvent(): void { ... }
 *
 * The method may have any visibility; listeners of parent classes run first. Disabled by save($e, ['listeners' => false]).
 */
#[Attribute(Attribute::TARGET_METHOD)]
final class AfterRecover {}
