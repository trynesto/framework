<?php

declare(strict_types=1);

namespace Nesto\Orm;

use Nesto\Common\Exceptions\NotFoundException;

/**
 * Thrown by findOneByOrFail() / getOneOrFail(). Extends NotFoundException so an
 * unhandled one becomes a 404 response.
 */
class EntityNotFoundException extends NotFoundException
{
    public static function for(string $entity, mixed $criteria = null): self
    {
        $short = Mapping\Naming::shortName($entity);
        $suffix = $criteria === null ? '' : ' matching ' . json_encode($criteria, JSON_UNESCAPED_UNICODE);

        return new self("$short not found$suffix.");
    }
}
