# Nesto

The [NestJS](https://nestjs.com) architecture, written in PHP 8.3. Modules, controllers, providers and dependency injection, guards, pipes, interceptors and exception filters: the same concepts and names as Nest, with PHP attributes in place of decorators. Everything speaks PSR (7, 11, 15, 3, 14, 16), so any Composer package plugs in.

**Documentation: [modulaphp.g4t.io](https://modulaphp.g4t.io)**

## Installation

```bash
composer global require nesto/framework
nesto new my-api
cd my-api && php nesto start
```

Open http://127.0.0.1:3000/api and you get `Hello World!`. Make sure Composer's global bin directory (`composer global config bin-dir --absolute`) is on your `PATH`.

`nesto new` creates the project, its `.env`, and installs the dependencies (`--skip-install` skips that step).

## A taste

```php
use Nesto\Common\Attributes\{Body, Controller, Get, Injectable, Module, Param, Post};
use Nesto\Validation\Attributes\{IsInt, IsOptional, IsString, Length, Min};

final class CreateCatDto
{
    #[IsString, Length(min: 2, max: 50)]
    public string $name;

    #[IsOptional, IsInt, Min(0)]
    public ?int $age = null;
}

#[Injectable]
final class CatsService
{
    private array $cats = [];

    public function create(CreateCatDto $dto): array
    {
        return $this->cats[] = ['id' => count($this->cats) + 1, 'name' => $dto->name, 'age' => $dto->age];
    }

    public function findAll(): array
    {
        return $this->cats;
    }
}

#[Controller('cats')]
final class CatsController
{
    public function __construct(private readonly CatsService $cats) {}

    #[Get]
    public function findAll(): array
    {
        return $this->cats->findAll();
    }

    #[Get('{id}')]
    public function findOne(#[Param('id')] int $id): array
    {
        return ['id' => $id];
    }

    #[Post]
    public function create(#[Body] CreateCatDto $dto): array
    {
        return $this->cats->create($dto);
    }
}

#[Module(controllers: [CatsController::class], providers: [CatsService::class])]
final class CatsModule {}
```

Or let the CLI write it: `php nesto g resource cats` generates the module, controller, service, DTOs and tests, and registers the module in `AppModule`.

## Features

- Modules, dependency injection (singleton, request and transient scopes), dynamic and global modules, lifecycle hooks
- Guards, pipes, interceptors, exception filters and middleware, with the same request lifecycle as Nest
- Validation and transformation (class-validator and class-transformer style attributes), file uploads, storage disks (local, S3)
- A TypeORM-style ORM (entities, repositories, query builder, relations, migrations) and a Prisma-style client generated from a `schema.prisma`, on PostgreSQL, MySQL, MariaDB and SQLite
- Config and `.env`, JWT and Passport-style authentication, OpenAPI (Swagger UI)
- Events, queues, task scheduling, caching, rate limiting, logging
- A testing module with supertest-style HTTP assertions
- A CLI: generators, migrations, Prisma commands, a development server, and production serving in [FrankenPHP](https://frankenphp.dev) worker mode

## The console

Every project has a `nesto` file at its root, Nesto's `artisan`:

```bash
php nesto list                 # every command
php nesto g resource cats      # generate a CRUD resource
php nesto routes               # list the routes
php nesto migration:run        # run the migrations
php nesto prisma:generate      # generate the Prisma client
php nesto start                # development server
php nesto start:prod           # production server (FrankenPHP worker mode)
```

Inside a project, the global `nesto` binary runs the same commands: `nesto routes`.

## Requirements

- PHP 8.3 or newer, with `pdo` and the PDO driver of your database (`pdo_pgsql`, `pdo_mysql` or `pdo_sqlite`)
- Composer 2

## Contributing

```bash
git clone https://github.com/trynesto/framework nesto && cd nesto
composer install
vendor/bin/phpunit
```

The database driver tests also run against PostgreSQL, MySQL and MariaDB when `NESTO_TEST_PGSQL_URL`, `NESTO_TEST_MYSQL_URL` and `NESTO_TEST_MARIADB_URL` point at throwaway databases whose name contains `test` (for example `postgres://postgres@127.0.0.1:5432/nesto_test`). To try a change in an application, create one against your checkout: `bin/nesto new demo --dev`.

## License

Nesto is open-source software licensed under the [MIT license](LICENSE).
