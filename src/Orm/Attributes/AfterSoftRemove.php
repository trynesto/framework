<?php

declare(strict_types=1);

namespace Nesto\Orm\Attributes;

use Attribute;

/**
 * Entity listener (TypeORM's @AfterSoftRemove): Runs after softRemove() stamped the entity's DeleteDateColumn.
 *
 *   #[AfterSoftRemove] public function onEvent(): void { ... }
 *
 * The method may have any visibility; listeners of parent classes run first. Disabled by save($e, ['listeners' => false]).
 */
#[Attribute(Attribute::TARGET_METHOD)]
final class AfterSoftRemove {}
