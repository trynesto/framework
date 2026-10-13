<p align="center">
    <a href="https://modulaphp.g4t.io" target="_blank">
        <picture>
            <source media="(prefers-color-scheme: dark)" srcset="https://raw.githubusercontent.com/trynesto/framework/main/.github/art/nesto-logo-dark.png">
            <img src="https://raw.githubusercontent.com/trynesto/framework/main/.github/art/nesto-logo-light.png" width="400" alt="Nesto Logo">
        </picture>
    </a>
</p>

<p align="center">
<a href="https://github.com/trynesto/framework/actions/workflows/tests.yml"><img src="https://github.com/trynesto/framework/actions/workflows/tests.yml/badge.svg" alt="Build Status"></a>
<a href="https://packagist.org/packages/nesto/framework"><img src="https://img.shields.io/packagist/dt/nesto/framework" alt="Total Downloads"></a>
<a href="https://packagist.org/packages/nesto/framework"><img src="https://img.shields.io/packagist/v/nesto/framework" alt="Latest Stable Version"></a>
<a href="https://packagist.org/packages/nesto/framework"><img src="https://img.shields.io/packagist/l/nesto/framework" alt="License"></a>
</p>

## About Nesto

Nesto is a PHP framework for building APIs with a clear structure. Your application is a tree of modules; each module
declares its controllers and the services it provides, and everything is wired together with PHP attributes and
constructor injection. Nesto takes care of the plumbing so you can focus on your domain:

- [Modules and dependency injection](https://modulaphp.g4t.io/docs/#/modules), with singleton, request and transient scopes.
- [Guards](https://modulaphp.g4t.io/docs/#/guards), [pipes](https://modulaphp.g4t.io/docs/#/pipes), [interceptors](https://modulaphp.g4t.io/docs/#/interceptors) and [exception filters](https://modulaphp.g4t.io/docs/#/exception-filters) around every request.
- [Validation](https://modulaphp.g4t.io/docs/#/application/validation) and [serialization](https://modulaphp.g4t.io/docs/#/application/serialization) declared on plain DTO classes.
- An [ORM](https://modulaphp.g4t.io/docs/#/data/overview) with entities, relations, a query builder and generated migrations, plus a [Prisma-style client](https://modulaphp.g4t.io/docs/#/prisma/overview), on PostgreSQL, MySQL, MariaDB and SQLite.
- [File uploads and storage](https://modulaphp.g4t.io/docs/#/files/storage) on local disks and S3.
- [Queues](https://modulaphp.g4t.io/docs/#/application/queues), [task scheduling](https://modulaphp.g4t.io/docs/#/application/task-scheduling), [events](https://modulaphp.g4t.io/docs/#/application/events), [caching](https://modulaphp.g4t.io/docs/#/data/caching) and [rate limiting](https://modulaphp.g4t.io/docs/#/security/rate-limiting).
- [Authentication](https://modulaphp.g4t.io/docs/#/security/authentication) with JWT and an [OpenAPI](https://modulaphp.g4t.io/docs/#/openapi/introduction) document generated from your code.
- A [console](https://modulaphp.g4t.io/docs/#/cli/overview) with generators, and production serving in [FrankenPHP](https://frankenphp.dev) worker mode.

Nesto speaks the PHP standards (PSR-7, 11, 15, 3, 14 and 16), so any Composer package plugs in.

## Getting Started

```bash
composer global require nesto/framework
nesto new my-api
cd my-api
php nesto start
```

Open http://127.0.0.1:3000/api and you get `Hello World!`. Make sure Composer's global bin directory
(`composer global config bin-dir --absolute`) is on your `PATH`.

A controller, a service and a module look like this:

```php
use Nesto\Common\Attributes\{Body, Controller, Get, Injectable, Module, Post};
use Nesto\Validation\Attributes\{IsString, Length};

final class CreateCatDto
{
    #[IsString, Length(min: 2, max: 50)]
    public string $name;
}

#[Injectable]
final class CatsService
{
    private array $cats = [];

    public function create(CreateCatDto $dto): array
    {
        return $this->cats[] = ['id' => count($this->cats) + 1, 'name' => $dto->name];
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

    #[Post]
    public function create(#[Body] CreateCatDto $dto): array
    {
        return $this->cats->create($dto);
    }
}

#[Module(controllers: [CatsController::class], providers: [CatsService::class])]
final class CatsModule {}
```

Or let the console write it for you: `php nesto g resource cats`.

## Learning Nesto

The [Nesto documentation](https://modulaphp.g4t.io/docs/) covers every part of the framework, and its
[tutorial](https://modulaphp.g4t.io/docs/#/tutorial/introduction) builds a complete blog API step by step, from
`nesto new` to production.

## Requirements

- PHP 8.3 or newer, with PDO and the driver of your database (`pdo_pgsql`, `pdo_mysql` or `pdo_sqlite`)
- Composer 2

## Contributing

Thank you for considering contributing to Nesto! Bug reports and pull requests are welcome on
[GitHub](https://github.com/trynesto/framework). To work on the framework:

```bash
git clone https://github.com/trynesto/framework nesto
cd nesto
composer install
vendor/bin/phpunit
```

The database tests also run against PostgreSQL, MySQL and MariaDB when `NESTO_TEST_PGSQL_URL`, `NESTO_TEST_MYSQL_URL`
and `NESTO_TEST_MARIADB_URL` point at throwaway databases whose name contains `test`. To try a change in an
application, create one against your checkout with `bin/nesto new demo --dev`.

## Security Vulnerabilities

If you discover a security vulnerability within Nesto, please report it privately through
[GitHub security advisories](https://github.com/trynesto/framework/security/advisories/new) instead of opening a
public issue. All reports will be addressed promptly.

## License

The Nesto framework is open-source software licensed under the [MIT license](LICENSE).
