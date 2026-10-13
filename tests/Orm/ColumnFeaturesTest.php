<?php

declare(strict_types=1);

namespace Nesto\Tests\Orm;

use Nesto\Orm\Attributes\Column;
use Nesto\Orm\Attributes\Embedded;
use Nesto\Orm\Attributes\Entity;
use Nesto\Orm\Attributes\PrimaryGeneratedColumn;
use Nesto\Orm\DataSource;
use Nesto\Orm\Mapping\PgArray;
use Nesto\Orm\MetadataRegistry;
use Nesto\Orm\Operators\FindOperator;
use Nesto\Orm\Operators\Operator;
use Nesto\Orm\OrmException;
use Nesto\Orm\Types\TypeRegistry;
use Nesto\Tests\Orm\Fixtures\Columns\Customer;
use Nesto\Tests\Orm\Fixtures\Columns\Lowercase;
use Nesto\Tests\Orm\Fixtures\Columns\Money;
use Nesto\Tests\Orm\Fixtures\Columns\MoneyType;
use Nesto\Tests\Orm\Fixtures\Columns\Priority;
use PHPUnit\Framework\TestCase;

use function Nesto\Orm\Operators\Between;
use function Nesto\Orm\Operators\In;
use function Nesto\Orm\Operators\IsNull;
use function Nesto\Orm\Operators\Like;
use function Nesto\Orm\Operators\MoreThan;
use function Nesto\Orm\Operators\Not;
use function Nesto\Orm\Operators\Raw;

#[Entity(name: 'cf_bad_transformer')]
final class CfBadTransformer
{
    #[PrimaryGeneratedColumn]
    public int $id;

    #[Column(transformer: \stdClass::class)]
    public string $name;
}

#[Entity(name: 'cf_bad_generated')]
final class CfBadGenerated
{
    #[PrimaryGeneratedColumn]
    public int $id;

    #[Column(type: 'int', generatedType: 'SOMETIMES', asExpression: 'id + 1')]
    public ?int $next = null;
}

final class CfPoint
{
    #[Column(type: 'int')]
    public int $x = 0;
}

#[Entity(name: 'cf_clash')]
final class CfClash
{
    #[PrimaryGeneratedColumn]
    public int $id;

    #[Column(type: 'int')]
    public int $x = 0;

    #[Embedded(CfPoint::class, prefix: false)]
    public CfPoint $point;
}

#[Entity(name: 'cf_typed')]
final class CfTyped
{
    #[PrimaryGeneratedColumn(type: 'int')]
    public int $id;

    #[Column(type: MoneyType::class)]
    public Money $price;

    #[Column(transformer: new Lowercase, default: 'ABC')]
    public string $code = 'XYZ';
}

/** Column feature metadata and SQL that need no server (the driver suite runs the behaviour on 4 databases). */
final class ColumnFeaturesTest extends TestCase
{
    protected function setUp(): void
    {
        MetadataRegistry::clear();
    }

    protected function tearDown(): void
    {
        TypeRegistry::unregister('money');
    }

    public function testEmbeddedColumnsAreFlattenedWithPrefixes(): void
    {
        $meta = MetadataRegistry::get(Customer::class);
        $this->assertSame('address_city', $meta->column('address.city')?->name);
        $this->assertSame('address_zip_code', $meta->column('address.zip')?->name, 'an explicit column name is prefixed too');
        $this->assertSame('address_geo_lat', $meta->column('address.geo.lat')?->name, 'nested embeddeds add up their prefixes');
        $this->assertSame('bill_city', $meta->column('billing.city')?->name);
        $this->assertSame('lat', $meta->column('spot.lat')?->name, 'prefix: false');
        $this->assertCount(4, $meta->embeddedColumns('address'));
        $this->assertCount(2, $meta->embeddedColumns('address.geo'));
        $this->assertSame([], $meta->embeddedColumns('name'));
        $this->assertTrue($meta->column('billing.city')->nullable);
        $this->assertFalse($meta->column('address.city')->nullable);
    }

    public function testColumnOptionsReachTheMetadata(): void
    {
        $meta = MetadataRegistry::get(Customer::class);
        $this->assertFalse($meta->column('password')->select);
        $this->assertFalse($meta->column('createdBy')->update);
        $this->assertFalse($meta->column('source')->update, 'readonly');
        $this->assertFalse($meta->column('note')->insert);
        $this->assertSame('STORED', $meta->column('total')->generatedType);
        $this->assertFalse($meta->column('total')->insert);
        $this->assertTrue($meta->column('total')->nullable);
        $this->assertSame('uuid', $meta->column('publicId')->generationStrategy);
        $this->assertSame('uuid', $meta->column('publicId')->type);
        $this->assertCount(2, $meta->column('email')->transformers);
        $this->assertSame(['1', '2'], array_map('strval', $meta->column('priority')->enumValues));
        $this->assertSame(Priority::class, $meta->column('priority')->enumClass);
        $this->assertSame('bigint', $meta->primary->type, 'increment keys are bigint unless a type is given');
        $this->assertSame('increment', $meta->primary->generationStrategy);
    }

    public function testInvalidColumnOptionsFailClearly(): void
    {
        foreach ([
            [CfBadTransformer::class, 'must implement'],
            [CfBadGenerated::class, "generatedType 'STORED' or 'VIRTUAL'"],
            [CfClash::class, 'clashes with another column'],
        ] as [$class, $message]) {
            try {
                MetadataRegistry::get($class);
                $this->fail("$class should be rejected");
            } catch (OrmException $e) {
                $this->assertStringContainsString($message, $e->getMessage());
            }
        }
    }

    public function testCustomTypesTransformerInstancesAndDefaults(): void
    {
        $ds = new DataSource(['type' => 'sqlite', 'database' => ':memory:', 'entities' => [CfTyped::class], 'types' => ['money' => MoneyType::class]]);
        $ds->synchronize();
        $this->assertTrue(TypeRegistry::has('money'), 'the DataSource option registers types');
        $create = $ds->getConnection()->fetchColumn("SELECT sql FROM sqlite_master WHERE name = 'cf_typed'");
        $this->assertStringContainsString('"id" INTEGER PRIMARY KEY AUTOINCREMENT', (string) $create);
        $this->assertStringContainsString('"price" DECIMAL(19, 4) NOT NULL', (string) $create, 'a ColumnType class works as type without registering it');
        $this->assertStringContainsString("\"code\" VARCHAR(255) NOT NULL DEFAULT 'ABC'", (string) $create, 'an attribute default is a database value (no transformer)');
        $this->assertTrue($ds->synchronizer()->diff([CfTyped::class])->isEmpty());

        $repo = $ds->getRepository(CfTyped::class);
        $row = $repo->save($repo->create(['price' => new Money('3.20'), 'code' => 'MiXeD']));
        $this->assertSame('mixed', $ds->getConnection()->fetchColumn('SELECT code FROM cf_typed'));
        $this->assertSame('3.20', $repo->findOneByOrFail(['id' => $row->id])->price->amount);
        $this->assertSame(1, $repo->countBy(['code' => 'MIXED']), 'where values go through the transformer');
    }

    public function testFindOperatorsCompileToSql(): void
    {
        $ds = new DataSource(['type' => 'sqlite', 'database' => ':memory:', 'entities' => [Customer::class], 'types' => ['money' => MoneyType::class]]);
        $sql = fn (array $where) => $ds->getRepository(Customer::class)->createQueryBuilder('c')->where($where)->getSql();

        $this->assertStringContainsString('WHERE ("c"."quantity" > :nesto_p0)', $sql(['quantity' => MoreThan(1)]));
        $this->assertStringContainsString('NOT("c"."name" LIKE :nesto_p0)', $sql(['name' => Not(Like('A%'))]));
        $this->assertStringContainsString('NOT("c"."name" IN (:nesto_p0, :nesto_p1))', $sql(['name' => Not(In(['a', 'b']))]));
        $this->assertStringContainsString('"c"."address_zip_code" IS NOT NULL', $sql(['address' => ['zip' => Not(IsNull())]]));
        $this->assertStringContainsString('"c"."quantity" BETWEEN :nesto_p0 AND :nesto_p1', $sql(['quantity' => Between(1, 3)]));
        $this->assertStringContainsString('("c"."quantity" > :nesto_p0 OR "c"."quantity" IS NULL)', $sql(['quantity' => Operator::or(MoreThan(3), IsNull())]));
        $this->assertStringContainsString('"c"."quantity" = :nesto_p0', $sql(['quantity' => Raw(':q', ['q' => 2])]));
        $this->assertStringContainsString('1 = 0', $sql(['name' => In([])]));

        $qb = $ds->getRepository(Customer::class)->createQueryBuilder('c')->where(['email' => In([' A@B.C '])]);
        $this->assertSame(['nesto_p0' => 'a@b.c'], $qb->getParameters(), 'operator values go through transformers');

        $this->expectException(\InvalidArgumentException::class);
        new FindOperator('nope');
    }

    public function testPostgresArrayLiterals(): void
    {
        $this->assertSame('{1,2,NULL}', PgArray::format([1, 2, null]));
        $this->assertSame('{"a b","c\\"d","e\\\\f"}', PgArray::format(['a b', 'c"d', 'e\\f']));
        $this->assertSame('{t,f}', PgArray::format([true, false]));
        $this->assertSame([], PgArray::parse('{}'));
        $this->assertSame(['1', '2', null], PgArray::parse('{1,2,NULL}'));
        $this->assertSame(['a b', 'c"d', 'e\\f', 'x,y'], PgArray::parse('{"a b","c\\"d","e\\\\f","x,y"}'));
        $this->assertSame(['NULL'], PgArray::parse('{"NULL"}'), 'a quoted NULL is a string');
    }
}
