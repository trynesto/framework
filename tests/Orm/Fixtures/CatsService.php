<?php

declare(strict_types=1);

namespace Nesto\Tests\Orm\Fixtures;

use Nesto\Common\Attributes\Injectable;
use Nesto\Orm\Attributes\InjectRepository;
use Nesto\Orm\Repository;

#[Injectable]
final class CatsService
{
    public function __construct(#[InjectRepository(Cat::class)] public readonly Repository $cats) {}

    public function create(array $data): Cat
    {
        return $this->cats->save($this->cats->create($data));
    }

    /** @return list<Cat> */
    public function all(): array
    {
        return $this->cats->find(['order' => ['name' => 'ASC']]);
    }

    public function one(int $id): Cat
    {
        return $this->cats->findOneByOrFail(['id' => $id]);
    }
}
