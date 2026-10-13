<?php

declare(strict_types=1);

namespace Nesto\Orm\Attributes;

use Attribute;

/**
 * Entity listener (TypeORM's @AfterLoad): runs after the entity was loaded by find*(), QueryBuilder
 * getMany()/getOne(), relation loading or a lazy relation, once its loaded relations are attached
 * (related entities get their AfterLoad first).
 *
 *   #[AfterLoad] public function computeFullName(): void { $this->fullName = "$this->first $this->last"; }
 *
 * The method may have any visibility; listeners of parent classes run first.
 */
#[Attribute(Attribute::TARGET_METHOD)]
final class AfterLoad {}
