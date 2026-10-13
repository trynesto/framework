# Nesto\Prisma — architecture

Prisma-style front-end on the ORM's shared schema engine (the alternative to OrmModule). Stage P1 = schema
language, DMMF, migrate/db tooling and CLI. Stage P2 = the query client (`Client\*`), the code generator
(`prisma:generate`), PrismaModule / PrismaService and test helpers.

## Pipeline

```
schema.prisma ──Lexer/Parser──▶ Schema\Ast\SchemaAst ──SchemaValidator──▶ Dmmf\Document ──▶ Client\PrismaClient / Generator
                                     │                                        │
                                Formatter (prisma format)          Migrate\SchemaTranslator
                                                                              ▼
live DB ──Orm\Schema\Introspection──▶ Migrate\DatabaseSchema ◀── neutral Orm\Schema\Table model
                                              │
                                    Migrate\Differ (Comparator) ──▶ Plan ──▶ DiffPlan/Grammar SQL
```

- `Schema\Parser` / `Lexer` produce the AST (blocks, fields, attributes, comments, `///` docs, spans).
  `SchemaLoader::load($source)` / `loadFile()` parse + validate and return a `Dmmf\Document`, or throw
  `SchemaException` (Prisma P1012 report, every error with `file:line:col`).
- `Schema\SchemaValidator` = `prisma validate` (relations, ids, defaults, native types, enums per provider,
  constraint-name collisions). Diagnostics are sorted by position; warnings end up in `Document::$warnings`.
- `Schema\NamingConventions` — Prisma's names (`User_pkey`, `User_email_key`, `Post_title_idx`,
  `Post_authorId_fkey`, relation `PostToUser`, join table `_CategoryToPost`), truncated to 63/64 chars.
- `Schema\NativeTypes` — default native type per scalar and the `@db.*` catalog per provider.

## The schema as data: `Dmmf\Document`

Immutable, worker-safe. `toArray()` has Prisma's DMMF shape (`datamodel.models/enums/views/indexes`) plus
`datasources`, `generators` and `nesto` extras; `Document::fromArray()` restores it.

- `models()`, `views()`, `model($name)`, `enums()`, `enum($name)`, `indexesOf($model)` (with `dbName`),
  `manyToMany` (`ManyToMany`: join table, which model is column `A` / `B`), `oppositeField($model, $field)`,
  `datasource` (`provider` postgresql|mysql|sqlite, `activeProvider` may be "mariadb", `url` EnvValue,
  `relationMode`), `generators` (`provider`, `output`, `previewFeatures`, `config`).
- `Model`: `tableName()`, `primaryKey`, `primaryKeyFields()`, `uniqueFields`, `uniqueIndexes`, `isView`,
  `isIgnored` (Nesto addition — skip in the client).
- `Field`: Prisma DMMF props + `columnName()`, `relationFkName`, `isIgnored`, kind `unsupported`
  (Unsupported("...") — skip in the client like Prisma). `default` is a literal (enum defaults = value
  name, use `Enum::value()->databaseValue()` for the stored value) or `FieldDefault{name, args}`.
  Client-side defaults: `uuid()`, `cuid()`, `nanoid()`, `ulid()` and `@updatedAt` — the database has none.
- `Dmmf\DmmfCache::load($schemaFile, $cacheFile, checkSource: false)` — compiled PHP array file (opcache),
  so the runtime never parses the schema. `PrismaProject::dmmfCacheFile()` gives the default location
  (`storage/cache/prisma-dmmf.php`, override with nesto.config.php `prisma.cache`).

## Migrate (`Migrate\*`)

- `PrismaProject` — schema location (`--schema`, nesto.config.php `prisma.schema`, composer.json
  `extra.prisma.schema`, prisma/schema.prisma), `.env` loading, `databaseUrl()` (`DatabaseUrl`: Prisma URLs →
  `Orm\Connection`; `file:` paths are relative to the schema dir; `?schema=` on PostgreSQL; the datasource's
  `directUrl` when declared — every migrate / db command uses it, the client uses `url`), `connect()`,
  `migrations()`, `seedCommand()`. P2's PrismaModule can reuse `DatabaseUrl::options()` to build its connection.
- `SchemaTranslator` — Document → `DatabaseSchema` (tables + PostgreSQL enum types and `extensions`). Uses the engine's
  `Column::$sqlType` (exact native type), `Table::$primaryKeyName`, `Index` sorts/lengths/using.
- `Differ` → `Plan` (`statements()`, `apply()`, `warnings()`; PostgreSQL `extensions = [citext, pg_trgm(schema: "public")]`
  (`postgresqlExtensions` preview feature) become `-- CreateExtension` / `-- AlterExtension` steps before the
  tables — never dropped when removed from the list; `DatabaseReset` drops the ones of the schema like Prisma); `DbPush`, `DbPull` (+ `Introspection\SchemaIntrospector`,
  `TypeMapper`), `Migrate` (dev/deploy/reset/status/resolve), `MigrateDiff`, `MigrationsTable`
  (`_prisma_migrations`, Prisma layout, SHA-256 checksums), `ShadowDatabase`, `SqlScript` (migration.sql
  layout, statement splitter), `MigrationRunner`.

## CLI

`prisma:init`, `prisma:format [--check]`, `prisma:validate`, `prisma:db push|pull|seed`,
`prisma:migrate dev|deploy|reset|status|resolve|diff`, `prisma:generate [--no-hints]`
(src/Cli/Commands/Prisma*Command.php, registered in BuiltIn). push / migrate dev / reset run
`prisma:generate --schema=<path>` afterwards (`--skip-generate` to skip).

## Client (`Client\*`, stage P2)

```
ModelDelegate ($prisma->user->findMany) ─▶ PrismaClient::dispatch ─▶ query extensions ─▶ Internal\Reader / Writer
                                                                                        │
                       Internal\QueryCompiler (where / orderBy → SQL, `?` params) ◀──────┘
                       Internal\ValueCodec (PHP ↔ DB values)   Internal\Engine (Connection, query events)
                       Internal\ErrorMapper (PDO errors → P2002 / P2003 / P2011 / ...)
```

- `PrismaClient` — options: `schema` (+ `cacheFile`, `checkSchema`) or `document`, `datasourceUrl` /
  `datasources`, `connection` (an `Orm\Connection`), `log`, `logStream`, `transactionOptions`, `omit` (global
  omit), `schemaDir`, `enums`. Prisma's `$`-methods are plain methods (methods and properties never clash in PHP):
  `connect()`, `disconnect()`, `transaction()`, `queryRaw()`, `executeRaw()`, `queryRawUnsafe()`,
  `executeRawUnsafe()`, `on()`, `extends()`, plus `dmmf()` / `connection()`; `$prisma->{'$transaction'}()` works too.
  Models: `$prisma->user` (lowerCamel model name; `model('User')`).
- Queries: findUnique(OrThrow), findFirst(OrThrow), findMany, create, createMany (skipDuplicates),
  createManyAndReturn / updateManyAndReturn (PostgreSQL + SQLite, like Prisma), update, updateMany (`limit`),
  upsert, delete, deleteMany (`limit`), count (`select: {_all, field}`), aggregate, groupBy (by, having, orderBy,
  take/skip). createMany / updateMany / deleteMany return `['count' => n]` like Prisma. Aggregate types are the same
  on every database: `_count` int, `_avg` float (full precision; MySQL / MariaDB average DOUBLEs) and for Decimal
  fields a Decimal string computed from SUM / COUNT with PostgreSQL's rule (max(16 significant digits, the column
  scale — 30 for the default DECIMAL(65,30)), half away from zero), `_sum` the field's type, `_min` / `_max`
  decoded like the field.
- Arguments are Prisma's, as PHP arrays: where (equals, not, in, notIn, lt/lte/gt/gte, contains / startsWith /
  endsWith, `mode: 'insensitive'`, AND/OR/NOT, some/every/none/is/isNot, Json `path` + equals / string_* /
  array_* / gt..., scalar lists has / hasEvery / hasSome / isEmpty on PostgreSQL, compound keys
  `['email_name' => [...]]`), select / include (nested, with where / orderBy / take / skip / cursor / distinct
  and `_count`), omit, orderBy (fields, relations, `_count`, `['sort' => 'asc', 'nulls' => 'last']`), cursor,
  take (negative), skip, distinct (applied in PHP, like Prisma for most connectors).
- Reads use one query per relation and level (Prisma's "query" strategy; `relationLoadStrategy: 'join'` is
  accepted with a `warn` event, other values are a validation error); per-parent take/skip uses `ROW_NUMBER() OVER (PARTITION BY ...)`; nested cursor / distinct+take run
  per parent. Relation filters are EXISTS subqueries, relation orderBy scalar subqueries — the same SQL on all
  four databases. `NOT` treats unknown (NULL) conditions as false first, like Prisma (`NOT {name: 'x'}` keeps
  rows whose name is NULL); `not: 'x'` does not.
- Writes: every top-level write is one transaction (a savepoint inside `transaction()`). Nested create,
  createMany, connect, connectOrCreate, disconnect, set, update, updateMany, upsert, delete, deleteMany on
  to-one (both sides), one-to-many and implicit m2m (`_AToB`); operations of one relation run in the order of
  their keys. updateMany / deleteMany read the matching identities first, then write by primary key (portable,
  MySQL forbids subqueries on the updated table). Atomic `set / increment / decrement / multiply / divide`
  (integer division everywhere), list `set / push`. `uuid()` (v4, `uuid(7)`), `cuid()`, `cuid(2)`, `nanoid()`,
  `ulid()`, `now()` and `@updatedAt` are filled by the client (DateTimes in UTC).
- `relationMode = "prisma"`: delete / deleteMany emulate onDelete (Cascade recursively, SetNull, SetDefault,
  Restrict / NoAction → P2014) and remove m2m join rows. `foreignKeys` (default) leaves it to the database.
  onUpdate is not emulated.
- Records are plain arrays (Prisma's plain objects): their shape depends on select / include / omit, so a
  DTO per model would lie; the generator gives array shapes instead. Types: DateTime → `DateTimeImmutable`
  (UTC), Decimal → normalized numeric string (`"9.99"`; exact, PHP has no decimal type), BigInt → int (numeric
  string beyond PHP_INT_MAX), Bytes → string (bound as `Orm\Types\Binary`, PARAM_LOB), Json → decoded value
  (`Prisma::JsonNull` / `DbNull` / `AnyNull`), enums → the generated backed enum (case value = schema name; @map
  values translated), else the name string, scalar lists → list (never null).
- Errors (`Client\Errors`): `PrismaClientKnownRequestError` (`getCode()` = "P2002", `meta`) with subclasses
  UniqueConstraintError P2002 (`meta.target` = field names on every database), ForeignKeyConstraintError P2003
  (`meta.field_name` = `"Post_authorId_fkey (index)"` on every database: SQLite does not name the constraint, so
  it is found from the schema — the model's foreign keys the arguments write; `"foreign key"` when ambiguous),
  NullConstraintError P2011, ValueTooLongError P2000, RequiredRelationViolationError P2014,
  RecordNotFoundError P2025 (update/delete/OrThrow/nested connect/update/delete), TransactionError P2028,
  WriteConflictError P2034, RawQueryError P2010; `PrismaClientValidationError` (bad arguments, nothing sent),
  `PrismaClientInitializationError` (P1000/P1001/P1003), `PrismaClientUnknownRequestError`.
- `transaction([fn ($tx) => ..., ...])` (PHP evaluates calls eagerly, so array items are closures) and
  `transaction(fn (PrismaClient $tx) => ..., ['isolationLevel' => Prisma::Serializable, 'timeout' => 5000])`:
  rollback on exception; past the timeout the next query / the commit fails with P2028 and everything is rolled
  back (PHP cannot interrupt the closure; `maxWait` is accepted and ignored). `$tx` refuses queries after the
  transaction (P2028). Queries through the outer client inside the closure share the connection, so they are in
  the transaction too.
- Raw: `queryRaw(Prisma::sql('... WHERE id = ', $id))` (template-like: text, value, text, ...; `Prisma::join()`,
  `Prisma::raw()`, `Prisma::empty()`), `queryRaw('... WHERE id = ?', [$id])`, `executeRaw()`, `*Unsafe(sql, ...values)`
  — always bound parameters; rows as the driver returns them (streams read into strings).
- Logging: `log: ['query', 'info', 'warn', 'error']` (stdout lines `prisma:query ...`) or `[['emit' => 'event',
  'level' => 'query']]`; `on('query', fn (array $e))` gets `timestamp, query, params (JSON), duration (ms),
  target`; `on('error')` gets failed operations; `on('warn')` / `log: ['warn']` gets the schema's warnings
  (deprecated preview features, ...) when the client starts and `relationLoadStrategy: 'join'` (not supported).
- Extensions: `extends(['result' => ..., 'query' => ..., 'model' => ..., 'client' => ...])` returns a new client
  sharing the engine. result: computed fields `['needs' => [...], 'compute' => fn (array $record)]` (selectable /
  omittable, applied to nested records too); query: `[model|'$allModels' => [operation|'$allOperations' => fn]]`
  or top-level `'$allOperations'` — hooks receive the parameters they name (model, operation, args, query) and
  later extensions wrap earlier ones; model: methods on delegates (`$this` is the delegate); client: methods on
  the client (`$xprisma->greet()` / `->{'$greet'}()`). `Prisma::defineExtension()` for reusable ones.

## Generator (`Generator\ClientGenerator`, `prisma:generate`)

`generator client { provider = "nesto-client"  output = "../generated/prisma"  namespace = "App\\Prisma" }` —
output relative to the schema file (default `../generated/prisma`), namespace from the option, else from
composer.json PSR-4 for the output folder, else `Generated\Prisma` (the command prints the composer.json line;
`autoload.php` is generated as a fallback). Other generator providers are ignored. Files: `PrismaClient.php`
(typed readonly delegate properties, the DMMF embedded via `dmmf.php`, enum map, schema dir for SQLite paths
and .env), `PrismaService.php`, `Delegates/<Model>Delegate.php` (`@phpstan-type` array shapes: `User`,
`UserWhereInput`, `UserWhereUniqueInput`, `UserSelect`, `UserInclude`, `UserOmit`, `UserOrderByInput`,
`UserCreateInput`, `UserUpdateInput`, `User<Op>Args`; nested relation arguments are `array<string, mixed>` to
avoid recursive aliases), `Enums/<Enum>.php`. Unsupported / @ignore fields and @@ignore models are skipped,
views get read methods only. Output is deterministic and only rewritten files change; stale delegates/enums
are removed. The command also refreshes the DmmfCache file. No PHPStan in the repo: the shapes are checked for
balance and php -l only.

## Nest integration

`PrismaModule` (DynamicModuleFactory; global by default): `new PrismaModule(schema:, isGlobal:, service:,
datasourceUrl:, log:, transactionOptions:, checkSchema:)`, `forRoot([...])`, `forRootAsync(['useFactory' => ...,
'inject' => [...], 'service' => ..., 'schema' => ...])`. It provides the service class — the generated
`<namespace>\PrismaService` when that class exists, else `Nesto\Prisma\PrismaService` (runtime client,
schema via DmmfCache) — and aliases it as `Client\PrismaClient`. PrismaService connects in onModuleInit and
disconnects in onApplicationShutdown. One instance per app/worker; nothing request-scoped.

## Testing

`Testing\PrismaTestDatabase::refresh($prisma, $schema, $url)` = trait `Testing\RefreshPrismaDatabase`: points the
client at $url (`PrismaClient::useDatasourceUrl()` — an app's PrismaService already connected to the .env database
is moved), pushes there once per process, truncates the client's database. `::push($schema, $url)` (db push
--force-reset; points the datasource's `url` / `directUrl` env vars at $url), `::truncate($prisma)` (TRUNCATE ...
RESTART IDENTITY CASCADE / TRUNCATE without FK checks / DELETE + sqlite_sequence). Every helper refuses a
database whose name (SQLite: file name) lacks "test" unless `force: true` (`isTestDatabase()`,
`databaseName($connection)` reads the live name). Tests: tests/Prisma/Client
(4 drivers), tests/Prisma/Generator (snapshots: `NESTO_UPDATE_SNAPSHOTS=1`), tests/Prisma/PrismaModuleTest.

## Not supported (yet)

MongoDB / SQL Server / CockroachDB providers, composite types, `multiSchema` / `@@schema`, `extensions` in `db pull`, `@@fulltext` and
the `search` filter, field references (`prisma.user.fields.x`), `relationLoadStrategy: 'join'` (accepted, the
query strategy is used), onUpdate emulation in relationMode prisma, `$on('beforeExit')`, migrating views (parsed with the `views` preview feature, never migrated or introspected). Index
sort/length/type are written but not compared (introspection does not report them).
