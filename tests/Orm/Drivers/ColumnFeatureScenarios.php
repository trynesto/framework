<?php

declare(strict_types=1);

namespace Nesto\Tests\Orm\Drivers;

use Nesto\Orm\DataSource;
use Nesto\Orm\Operators\Operator;
use Nesto\Orm\OrmException;
use Nesto\Orm\Types\TypeRegistry;
use Nesto\Tests\Orm\Fixtures\Columns\ChangeV1;
use Nesto\Tests\Orm\Fixtures\Columns\ChangeV2;
use Nesto\Tests\Orm\Fixtures\Columns\Counter;
use Nesto\Tests\Orm\Fixtures\Columns\Customer;
use Nesto\Tests\Orm\Fixtures\Columns\Money;
use Nesto\Tests\Orm\Fixtures\Columns\MoneyType;
use Nesto\Tests\Orm\Fixtures\Columns\PgFeature;
use Nesto\Tests\Orm\Fixtures\Columns\Priority;
use Nesto\Tests\Orm\Fixtures\Columns\Tier;
use Nesto\Tests\Orm\Fixtures\Columns\Voucher;

use function Nesto\Orm\Operators\Any;
use function Nesto\Orm\Operators\ArrayContainedBy;
use function Nesto\Orm\Operators\ArrayContains;
use function Nesto\Orm\Operators\ArrayOverlap;
use function Nesto\Orm\Operators\Between;
use function Nesto\Orm\Operators\Equal;
use function Nesto\Orm\Operators\ILike;
use function Nesto\Orm\Operators\In;
use function Nesto\Orm\Operators\IsNull;
use function Nesto\Orm\Operators\JsonContains;
use function Nesto\Orm\Operators\LessThan;
use function Nesto\Orm\Operators\LessThanOrEqual;
use function Nesto\Orm\Operators\Like;
use function Nesto\Orm\Operators\MoreThan;
use function Nesto\Orm\Operators\MoreThanOrEqual;
use function Nesto\Orm\Operators\Not;
use function Nesto\Orm\Operators\Raw;

/**
 * Column features on every driver (tables col_*): value transformers, custom types, enums, simple-array /
 * simple-json, select / insert / update options, generated values and columns, embedded columns, find
 * operators; PostgreSQL array / identity / sequence columns. Used by DriverTestCase.
 */
trait ColumnFeatureScenarios
{
    private ?DataSource $columns = null;

    /** @return list<class-string> */
    private function columnEntities(): array
    {
        return [Customer::class, Voucher::class, ...(static::platform() === 'pgsql' ? [PgFeature::class] : [Counter::class])];
    }

    private function columnsDataSource(?array $entities = null): DataSource
    {
        TypeRegistry::register('money', MoneyType::class);
        $ds = new DataSource(['entities' => $entities ?? $this->columnEntities()]);
        $ds->useConnection($this->ds->getConnection());
        $this->dropColumnTables($ds);
        $ds->synchronize();

        return $this->columns = $ds;
    }

    #[\PHPUnit\Framework\Attributes\After]
    protected function dropColumnFeatureTables(): void
    {
        if ($this->columns !== null) {
            $this->dropColumnTables($this->columns);
            $this->columns = null;
        }
        TypeRegistry::unregister('money');
    }

    private function dropColumnTables(DataSource $ds): void
    {
        $ds->synchronizer()->drop([...$this->columnEntities(), ChangeV2::class, Counter::class, PgFeature::class]);
    }

    /** @return array<string, mixed> */
    private function rawRow(string $table, int|string $id): array
    {
        $conn = $this->ds->getConnection();

        return $conn->fetchOne('SELECT * FROM ' . $conn->quote($table) . ' WHERE ' . $conn->quote('id') . ' = :id', ['id' => $id]) ?? [];
    }

    private function newCustomer(DataSource $ds, array $values = []): Customer
    {
        return $ds->getRepository(Customer::class)->create($values + [
            'name' => 'Ada',
            'email' => '  ADA@Example.COM ',
            'password' => 'secret',
            'createdBy' => 'admin',
            'note' => 'ignored on insert',
            'credit' => 12.34,
            'balance' => new Money('10.50'),
            'tier' => Tier::Pro,
            'priority' => Priority::High,
            'roles' => ['admin', 'editor'],
            'prefs' => ['theme' => 'dark'],
            'meta' => ['plan' => 'pro', 'flags' => ['beta' => true]],
            'quantity' => 3,
            'unitPrice' => 5,
            'address' => ['city' => 'Oslo', 'zip' => '0150', 'geo' => ['lat' => 59, 'lng' => 10]],
            'spot' => ['lat' => 1, 'lng' => 2],
        ]);
    }

    public function testColumnFeaturesSynchronizeWithoutDrift(): void
    {
        $ds = $this->columnsDataSource();
        $sync = $ds->synchronizer();
        $this->assertDiffEmpty($sync->diff($this->columnEntities()), 'column feature fixtures');
        $this->assertSame([], $this->ddl(fn () => $sync->synchronize($this->columnEntities())), 'a second synchronize runs no DDL');

        $columns = array_column($ds->schema()->columnDetails('col_customers'), null, 'name');
        foreach (['address_city', 'address_zip_code', 'address_geo_lat', 'address_geo_lng', 'bill_city', 'bill_geo_lat', 'lat', 'lng', 'public_id', 'total'] as $name) {
            $this->assertArrayHasKey($name, $columns);
        }
        $this->assertFalse($columns['address_city']['nullable']);
        $this->assertTrue($columns['bill_city']['nullable'], 'columns of a nullable embedded are nullable');

        // a migration generated for the same entities renders the new column options and round-trips
        $this->dropColumnTables($ds);
        $class = 'ColumnFeatures' . ucfirst(static::platform()) . bin2hex(random_bytes(3));
        $source = (string) $ds->generateMigration($class);
        $this->assertStringContainsString("->storedAs('quantity * unit_price')", $source);
        $this->assertStringContainsString("\$table->column('balance', 'money')->nullable();", $source);
        if (static::platform() === 'pgsql') {
            $this->assertStringContainsString("->array()", $source);
            $this->assertStringContainsString("->generatedAs('BY DEFAULT')", $source);
            $this->assertStringContainsString("->enumName('col_customers_tier_enum')", $source);
        } else {
            $this->assertStringContainsString("->virtualAs('hits + 1')", $source);
        }
        $dir = sys_get_temp_dir() . '/nesto-col-' . bin2hex(random_bytes(4));
        mkdir($dir);
        try {
            file_put_contents("$dir/2026_01_01_000000_columns.php", $source);
            $migrator = new \Nesto\Orm\Migration\Migrator($ds->getConnection(), [$dir], 'col_migrations');
            $this->assertSame([$class], $migrator->run());
            $this->assertDiffEmpty($sync->diff($this->columnEntities()), "after up()\n$source");
            $this->assertSame($class, $migrator->revert());
            $this->assertFalse($ds->schema()->hasTable('col_customers'));
        } finally {
            array_map('unlink', glob("$dir/*.php") ?: []);
            rmdir($dir);
            $ds->schema()->dropTableIfExists('col_migrations');
        }
    }

    public function testColumnFeaturesRoundTrip(): void
    {
        $ds = $this->columnsDataSource();
        $repo = $ds->getRepository(Customer::class);
        $customer = $repo->save($this->newCustomer($ds));

        $this->assertIsInt($customer->id);
        $this->assertMatchesRegularExpression('/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/', $customer->publicId);
        $this->assertSame(15, $customer->total, 'generated column read back after insert');

        $raw = $this->rawRow('col_customers', $customer->id);
        $this->assertSame('ada@example.com', $raw['email'], 'transformers run in order on write');
        $this->assertSame(1234, (int) $raw['credit']);
        $this->assertSame('admin,editor', $raw['roles'], 'simple-array is comma separated text');
        $this->assertSame(['theme' => 'dark'], json_decode((string) $raw['prefs'], true));
        $this->assertSame('10.5', rtrim(rtrim(number_format((float) $raw['balance'], 4, '.', ''), '0'), '.'));
        $this->assertSame('pro', $raw['tier']);
        $this->assertSame('2', (string) $raw['priority']);
        $this->assertNull($raw['note'], 'insert: false');
        $this->assertSame('secret', $raw['password']);
        $this->assertSame('Oslo', $raw['address_city']);
        $this->assertSame(59, (int) $raw['address_geo_lat']);
        $this->assertNull($raw['bill_city']);
        $this->assertSame(1, (int) $raw['lat']);

        $found = $repo->findOneByOrFail(['id' => $customer->id]);
        $this->assertFalse((new \ReflectionProperty($found, 'password'))->isInitialized($found), 'select: false is not loaded');
        $this->assertSame('ada@example.com', $found->email);
        $this->assertSame(12.34, $found->credit);
        $this->assertInstanceOf(Money::class, $found->balance);
        $this->assertSame('10.50', $found->balance->amount);
        $this->assertSame(Tier::Pro, $found->tier);
        $this->assertSame(Priority::High, $found->priority);
        $this->assertSame(['admin', 'editor'], $found->roles);
        $this->assertSame(['theme' => 'dark'], $found->prefs);
        $this->assertSame('Oslo', $found->address->city);
        $this->assertSame('0150', $found->address->zip);
        $this->assertSame(59, $found->address->geo->lat);
        $this->assertNull($found->billing, 'a nullable embedded whose columns are all NULL hydrates as null');
        $this->assertSame(2, $found->spot->lng);
        $this->assertSame(15, $found->total);
        $this->assertSame($customer->publicId, $found->publicId);

        $withPassword = $repo->findOneOrFail(['where' => ['id' => $customer->id], 'select' => ['id', 'name', 'password']]);
        $this->assertSame('secret', $withPassword->password);
        $qb = $repo->createQueryBuilder('c')->addSelect('c.password')->where(['id' => $customer->id])->getOneOrFail();
        $this->assertSame('secret', $qb->password, 'addSelect() adds a select: false column to the entity alias');
        $this->assertSame('Oslo', $qb->address->city);

        // update: changed columns only; update: false / readonly are never written; generated columns recomputed
        $found->createdBy = 'someone else';
        $found->source = 'api';
        $found->quantity = 4;
        $found->note = 'written on update';
        $found->address->city = 'Bergen';
        $found->billing = null;
        $repo->save($found);
        $this->assertSame(20, $found->total, 'generated column recomputed after update');
        $raw = $this->rawRow('col_customers', $customer->id);
        $this->assertSame('admin', $raw['created_by']);
        $this->assertSame('web', $raw['source']);
        $this->assertSame('written on update', $raw['note']);
        $this->assertSame('Bergen', $raw['address_city']);
        $this->assertSame(20, (int) $raw['total']);
        $conn = $ds->getConnection();
        $fresh = $repo->findOneByOrFail(['id' => $customer->id]);
        $conn->enableQueryLog()->flushQueryLog();
        $repo->save($found);
        $repo->save($fresh);
        $this->assertSame(0, $conn->queryCount(), 'an unchanged entity (transformed values, custom types, embedded) writes nothing');
        $conn->disableQueryLog();

        $found->billing = $repo->create(['address' => ['city' => 'Paris']])->address;
        $repo->save($found);
        $this->assertSame('Paris', $repo->findOneByOrFail(['id' => $customer->id])->billing?->city);

        // query builder writes skip update: false columns; set() takes embedded arrays
        $repo->update($customer->id, ['createdBy' => 'x', 'address' => ['zip' => '5003']]);
        $raw = $this->rawRow('col_customers', $customer->id);
        $this->assertSame('admin', $raw['created_by']);
        $this->assertSame('5003', $raw['address_zip_code']);
        try {
            $repo->update($customer->id, ['createdBy' => 'x']);
            $this->fail('nothing to update');
        } catch (OrmException $e) {
            $this->assertStringContainsString('update: false', $e->getMessage());
        }

        // uuid primary keys: PostgreSQL's default gen_random_uuid(), the ORM elsewhere
        $vouchers = $ds->getRepository(Voucher::class);
        $voucher = $vouchers->save($vouchers->create(['code' => 'WELCOME', 'customer' => $found]));
        $this->assertMatchesRegularExpression('/^[0-9a-f-]{36}$/', $voucher->id);
        $this->assertSame('WELCOME', $vouchers->findOneByOrFail(['id' => $voucher->id])->code);
        $id = $vouchers->insert(['code' => 'RAW']);
        $this->assertMatchesRegularExpression('/^[0-9a-f-]{36}$/', (string) $id);
        $this->assertSame('RAW', $vouchers->findOneByOrFail(['id' => $id])->code);
        $this->assertSame(1, $vouchers->countBy(['customer' => $found]));

        if (static::platform() !== 'pgsql') {
            $counters = $ds->getRepository(Counter::class);
            $counter = $counters->save($counters->create(['hits' => 4]));
            $this->assertSame(5, $counter->next, 'virtual generated column');
            $counter->hits = 9;
            $counters->save($counter);
            $this->assertSame(10, $counter->next);
            $this->assertSame(10, $counters->findOneByOrFail(['id' => $counter->id])->next);
        } else {
            try {
                $virtual = new DataSource(['entities' => [Counter::class]]);
                $virtual->useConnection($this->ds->getConnection());
                $virtual->synchronize();
                if ((int) $this->ds->getConnection()->serverVersion() < 18) {
                    $this->fail('VIRTUAL generated columns need PostgreSQL 18');
                }
            } catch (OrmException $e) {
                $this->assertStringContainsString('STORED', $e->getMessage());
            }
        }
    }

    public function testFindOperatorsAndEmbeddedCriteria(): void
    {
        $ds = $this->columnsDataSource();
        $repo = $ds->getRepository(Customer::class);
        $repo->save([
            $this->newCustomer($ds, ['name' => 'Ada', 'email' => 'ada@x.io', 'quantity' => 1, 'credit' => 5.0, 'meta' => ['plan' => 'pro', 'tags' => ['a', 'b']]]),
            $this->newCustomer($ds, ['name' => 'Bob', 'email' => 'bob@x.io', 'quantity' => 5, 'credit' => 20.0, 'tier' => Tier::Free, 'note' => null,
                'address' => ['city' => 'Bergen', 'geo' => ['lat' => 60]], 'meta' => ['plan' => 'free']]),
            $this->newCustomer($ds, ['name' => 'Cyd', 'email' => 'cyd@x.io', 'quantity' => 9, 'credit' => 50.0, 'tier' => Tier::Team,
                'address' => ['city' => 'Trondheim', 'zip' => null, 'geo' => ['lat' => 63]], 'meta' => null]),
        ]);
        $names = fn (array $where, array $options = []) => array_map(fn (Customer $c) => $c->name, $repo->find(['where' => $where, 'order' => ['name' => 'ASC']] + $options));

        $this->assertSame(['Bob', 'Cyd'], $names(['quantity' => MoreThan(1)]));
        $this->assertSame(['Bob', 'Cyd'], $names(['quantity' => MoreThanOrEqual(5)]));
        $this->assertSame(['Ada'], $names(['quantity' => LessThan(5)]));
        $this->assertSame(['Ada', 'Bob'], $names(['quantity' => LessThanOrEqual(5)]));
        $this->assertSame(['Ada', 'Bob'], $names(['quantity' => Between(1, 5)]));
        $this->assertSame(['Bob'], $names(['quantity' => Equal(5)]));
        $this->assertSame(['Ada', 'Cyd'], $names(['name' => In(['Ada', 'Cyd', 'Zed'])]));
        $this->assertSame(['Bob'], $names(['name' => Not(In(['Ada', 'Cyd']))]));
        $this->assertSame(['Ada', 'Cyd'], $names(['name' => Not('Bob')]));
        $this->assertSame(['Ada', 'Cyd'], $names(['name' => Any(['Ada', 'Cyd'])]));
        $this->assertSame(['Bob'], $names(['name' => Like('B%')]));
        $this->assertSame(['Bob'], $names(['name' => ILike('b%')]));
        $this->assertSame(['Ada', 'Cyd'], $names(['name' => Not(Like('B%'))]));
        $this->assertSame(['Bob', 'Cyd'], $names(['address' => ['zip' => IsNull()]]));
        $this->assertSame(['Ada'], $names(['address' => ['zip' => Not(IsNull())]]));
        $this->assertSame(['Bob'], $names(['quantity' => Operator::and(MoreThan(1), LessThan(9))]));
        $this->assertSame(['Ada', 'Cyd'], $names(['quantity' => Operator::or(1, MoreThan(5))]));
        $this->assertSame(['Bob', 'Cyd'], $names(['quantity' => Raw(fn (string $column) => "$column > :min", ['min' => 1])]));
        $this->assertSame(['Ada', 'Cyd'], $names(['name' => Raw(fn (string $column) => "$column IN (:...names)", ['names' => ['Ada', 'Cyd']])]));
        $this->assertSame(['Bob'], $names(['quantity' => Raw('5')]));
        // where arrays are AND, a list of them OR (TypeORM)
        $this->assertSame(['Bob'], $names(['quantity' => MoreThan(1), 'tier' => Tier::Free]));
        $this->assertSame(['Ada', 'Bob'], $names([['name' => 'Ada'], ['tier' => Tier::Free]]));

        // transformers apply to where values: emails are stored lowercased, euros as cents
        $this->assertSame(['Ada'], $names(['email' => '  ADA@X.IO']));
        $this->assertSame(['Ada'], $names(['email' => In(['ADA@X.IO'])]));
        $this->assertSame(['Bob', 'Cyd'], $names(['credit' => MoreThan(10.0)]));
        $this->assertSame(['Bob'], $names(['credit' => Between(10.0, 30.0)]));

        // embedded criteria, order, select
        $this->assertSame(['Bob'], $names(['address' => ['city' => 'Bergen']]));
        $this->assertSame(['Cyd'], $names(['address' => ['geo' => ['lat' => MoreThan(61)]]]));
        $byLat = $repo->find(['order' => ['address' => ['geo' => ['lat' => 'DESC']]]]);
        $this->assertSame(['Cyd', 'Bob', 'Ada'], array_map(fn (Customer $c) => $c->name, $byLat));
        $partial = $repo->findOneOrFail(['where' => ['name' => 'Bob'], 'select' => ['name', 'address' => ['city' => true]]]);
        $this->assertSame('Bergen', $partial->address->city);
        $this->assertFalse((new \ReflectionProperty($partial->address, 'zip'))->isInitialized($partial->address));
        $this->assertFalse((new \ReflectionProperty($partial, 'email'))->isInitialized($partial));
        $whole = $repo->findOneOrFail(['where' => ['name' => 'Bob'], 'select' => ['name', 'address']]);
        $this->assertSame(60, $whole->address->geo->lat);
        $qb = $repo->createQueryBuilder('c')->where('c.address.city = :city', ['city' => 'Trondheim'])->orderBy('c.address.geo.lat', 'DESC')->getMany();
        $this->assertSame(['Cyd'], array_map(fn (Customer $c) => $c->name, $qb));
        $this->assertSame(1, $repo->count(['where' => ['address' => ['city' => Like('Ber%')]]]));

        if (static::platform() !== 'sqlite') {
            $this->assertSame(['Ada'], $names(['meta' => JsonContains(['plan' => 'pro'])]));
            $this->assertSame(['Ada'], $names(['meta' => JsonContains(['tags' => ['b']])]));
        } else {
            try {
                $names(['meta' => JsonContains(['plan' => 'pro'])]);
                $this->fail('JsonContains on SQLite');
            } catch (OrmException $e) {
                $this->assertStringContainsString('JsonContains', $e->getMessage());
            }
        }
    }

    public function testPostgresArrayIdentityAndSequenceColumns(): void
    {
        if (static::platform() !== 'pgsql') {
            // array columns are PostgreSQL only: other databases refuse them with a clear message
            $ds = new DataSource(['entities' => [PgFeature::class]]);
            $ds->useConnection($this->ds->getConnection());
            try {
                $ds->synchronize();
                $this->fail('array columns need PostgreSQL');
            } catch (OrmException $e) {
                $this->assertStringContainsString('array columns', $e->getMessage());
            }
            try {
                $this->columnsDataSource()->getRepository(Customer::class)->findBy(['roles' => ArrayContains(['admin'])]);
                $this->fail('ArrayContains needs PostgreSQL');
            } catch (OrmException $e) {
                $this->assertStringContainsString('PostgreSQL', $e->getMessage());
            }

            return;
        }
        $ds = $this->columnsDataSource();
        $repo = $ds->getRepository(PgFeature::class);
        $a = $repo->save($repo->create(['tags' => ['red', 'green', 'with "quotes", commas'], 'scores' => [1, 2, 3], 'doc' => ['k' => 'v']]));
        $b = $repo->save($repo->create(['tags' => ['blue'], 'scores' => null]));
        $this->assertSame(1, $a->id, 'identity key');
        $this->assertSame(2, $b->id);
        $this->assertSame(1, $a->ticket, 'non-key sequence read back after insert');
        $this->assertSame(2, $b->ticket);

        $found = $repo->findOneByOrFail(['id' => $a->id]);
        $this->assertSame(['red', 'green', 'with "quotes", commas'], $found->tags);
        $this->assertSame([1, 2, 3], $found->scores);
        $this->assertNull($repo->findOneByOrFail(['id' => $b->id])->scores);
        $ids = fn (array $where) => array_map(fn (PgFeature $f) => $f->id, $repo->find(['where' => $where, 'order' => ['id' => 'ASC']]));
        $this->assertSame([1], $ids(['tags' => ArrayContains(['green', 'red'])]));
        $this->assertSame([2], $ids(['tags' => ArrayContainedBy(['blue', 'black'])]));
        $this->assertSame([1, 2], $ids(['tags' => ArrayOverlap(['blue', 'red'])]));
        $this->assertSame([1], $ids(['scores' => ArrayContains([2])]));
        $this->assertSame([1], $ids(['doc' => JsonContains(['k' => 'v'])]));
        $this->assertSame([1], $ids(['tags' => Equal(['red', 'green', 'with "quotes", commas'])]));

        $found->tags[] = 'yellow';
        $repo->save($found);
        $this->assertSame(['red', 'green', 'with "quotes", commas', 'yellow'], $repo->findOneByOrFail(['id' => $a->id])->tags);
        $found = $repo->findOneByOrFail(['id' => $a->id]);
        $conn = $ds->getConnection();
        $conn->enableQueryLog()->flushQueryLog();
        $repo->save($found);
        $this->assertSame(0, $conn->queryCount(), 'unchanged array columns write nothing');
        $conn->disableQueryLog();
    }

    public function testGeneratedColumnsAndEnumValuesChangeInPlace(): void
    {
        $ds = $this->columnsDataSource([ChangeV1::class]);
        $ds->getRepository(ChangeV1::class)->save($ds->getRepository(ChangeV1::class)->create(['base' => 7, 'state' => 'live', 'labels' => ['x']]));

        $v2 = new DataSource(['entities' => [ChangeV2::class]]);
        $v2->useConnection($this->ds->getConnection());
        $this->assertNotSame([], $v2->synchronizer()->log([ChangeV2::class]));
        $v2->synchronize();
        $this->assertDiffEmpty($v2->synchronizer()->diff([ChangeV2::class]), 'after the generated / enum change');

        $repo = $v2->getRepository(ChangeV2::class);
        $row = $repo->findOneByOrFail(['base' => 7]);
        $this->assertSame(21, $row->derived, 'generated column recomputed with the new expression');
        $this->assertSame(107, $row->shifted);
        $this->assertSame('live', $row->state);
        $this->assertSame(['x'], $row->labels);
        $row->state = 'archived';
        $repo->save($row);
        $this->assertSame('archived', $repo->findOneByOrFail(['id' => $row->id])->state);

        // the reverse diff (a generated migration's down()) goes back to v1
        $back = new DataSource(['entities' => [ChangeV1::class]]);
        $back->useConnection($this->ds->getConnection());
        $repo->delete(['state' => 'archived']);
        $back->synchronize();
        $this->assertDiffEmpty($back->synchronizer()->diff([ChangeV1::class]), 'back to v1');
    }
}
