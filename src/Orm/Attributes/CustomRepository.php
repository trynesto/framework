<?php

declare(strict_types=1);

namespace Nesto\Orm\Attributes;

use Attribute;
use Nesto\Orm\OrmException;

/**
 * A custom repository: a class extending Repository with its own query methods (TypeORM 0.3's recommended
 * Nest pattern, `class UsersRepository extends Repository<User>`).
 *
 *   #[CustomRepository(User::class)]
 *   class UserRepository extends Repository
 *   {
 *       public function findActive(): array { return $this->findBy(['active' => true]); }
 *   }
 *
 * Register it with `OrmModule::forFeature([UserRepository::class])` (or `new OrmFeatureModule(UserRepository::class)`):
 * it is injected by class (`private UserRepository $users`), and `#[InjectRepository(User::class)]` returns it too.
 * A constructor of its own may take more dependencies; it passes the DataSource on:
 * `public function __construct(DataSource $ds, private Clock $clock) { parent::__construct($ds); }`.
 * In a transaction, `$tx->withRepository($users)` gives the same repository bound to the transactional manager.
 */
#[Attribute(Attribute::TARGET_CLASS)]
final class CustomRepository
{
    /** @param class-string $entity */
    public function __construct(public readonly string $entity) {}

    /** The entity of a custom repository class (its #[CustomRepository], inherited from parents). @return class-string */
    public static function entityOf(string $class): string
    {
        for ($ref = new \ReflectionClass($class); $ref !== false; $ref = $ref->getParentClass()) {
            $attribute = $ref->getAttributes(self::class)[0] ?? null;
            if ($attribute !== null) {
                return $attribute->newInstance()->entity;
            }
        }
        throw new OrmException("$class extends Repository without an entity: add #[CustomRepository(Entity::class)] to it, or pass the entity class to the parent constructor.");
    }

    public static function isCustomRepository(string $class): bool
    {
        if (! class_exists($class) || ! is_subclass_of($class, \Nesto\Orm\Repository::class)) {
            return false;
        }
        for ($ref = new \ReflectionClass($class); $ref !== false; $ref = $ref->getParentClass()) {
            if ($ref->getAttributes(self::class) !== []) {
                return true;
            }
        }

        return false;
    }
}
