<?php

declare(strict_types=1);

namespace Nesto\Tests\Orm;

use Nesto\Orm\Attributes\{AfterLoad, BeforeInsert, BeforeUpdate, ChildEntity, Column, DeleteDateColumn, Embedded, Entity, PrimaryGeneratedColumn, TableInheritance, Tree, TreeChildren, TreeLevelColumn, TreeParent, UpdateDateColumn, VersionColumn, ViewColumn, ViewEntity};
use Nesto\Orm\Logging\AbstractLogger;
use Nesto\Orm\QueryRunner;
use Nesto\Orm\DataSource;
use Nesto\Orm\EntityManager;
use Nesto\Orm\ValueTransformer;
use PHPUnit\Framework\TestCase;

use function Nesto\Orm\Operators\MoreThan;

// One entity that uses features of several ORM waves together: uuid key, version column, embedded, transformer,
// entity listeners, find operators, upsert and transactions.
final class CrossCents implements ValueTransformer {
    public function to(mixed $v): mixed { return $v === null ? null : (int) round($v * 100); }
    public function from(mixed $v): mixed { return $v === null ? null : $v / 100; }
}
final class CrossAddress { #[Column(nullable: true)] public ?string $city = null; #[Column(nullable: true)] public ?string $street = null; }

#[Entity('cross_items')]
final class CrossItem {
    #[PrimaryGeneratedColumn('uuid')] public string $id;
    #[Column(unique: true)] public string $sku;
    #[Column] public string $slug;
    #[Column(type: 'int', transformer: CrossCents::class)] public float $price;
    #[Embedded(CrossAddress::class, prefix: 'addr_')] public CrossAddress $address;
    #[VersionColumn] public int $version;
    #[UpdateDateColumn] public \DateTimeImmutable $updatedAt;
    public bool $loaded = false;
    #[BeforeInsert] #[BeforeUpdate] public function makeSlug(): void { $this->slug = strtolower($this->sku); }
    #[AfterLoad] public function markLoaded(): void { $this->loaded = true; }
}

// Single table inheritance + uuid key + embedded + transformer + version + soft delete + listeners; a
// materialized-path tree with uuid keys.
#[Entity('cross_assets')]
#[TableInheritance(column: ['name' => 'asset_type'])]
abstract class CrossAsset {
    #[PrimaryGeneratedColumn('uuid')] public string $id;
    #[Column(unique: true)] public string $code;
    #[Column(type: 'int', transformer: CrossCents::class)] public float $value;
    #[VersionColumn] public int $version;
    #[DeleteDateColumn] public ?\DateTimeImmutable $deletedAt = null;
    public bool $loaded = false;
    #[AfterLoad] public function markLoaded(): void { $this->loaded = true; }
}
#[ChildEntity('building')]
final class CrossBuilding extends CrossAsset {
    #[Embedded(CrossAddress::class, prefix: 'site_')] public CrossAddress $site;
    #[Column(nullable: true)] public ?int $floors = null;
    #[BeforeInsert] public function upper(): void { $this->code = strtoupper($this->code); }
}
#[ChildEntity('vehicle')]
final class CrossVehicle extends CrossAsset { #[Column(nullable: true)] public ?string $plate = null; }

#[Entity('cross_folders')]
#[Tree('materialized-path')]
final class CrossFolder {
    #[PrimaryGeneratedColumn('uuid')] public string $id;
    #[Column] public string $name;
    #[TreeParent(onDelete: 'CASCADE')] public ?CrossFolder $parent = null;
    #[TreeChildren(cascade: true)] public array $children;
    #[TreeLevelColumn] public int $depth;
    #[VersionColumn] public int $version;
}

// A view over the single table inheritance table.
#[ViewEntity(name: 'cross_asset_counts', expression: 'SELECT asset_type AS type, COUNT(*) AS total FROM cross_assets WHERE deleted_at IS NULL GROUP BY asset_type')]
final class CrossAssetCount { #[ViewColumn] public string $type; #[ViewColumn] public int $total; }

final class CrossQueryLog extends AbstractLogger {
    public array $queries = [];
    protected function writeLog(string $level, array $messages, ?QueryRunner $queryRunner = null): void { $this->queries[] = (string) $messages[0]['message']; }
}

final class CrossFeatureTest extends TestCase
{
    public function testFeaturesWorkTogether(): void
    {
        $ds = new DataSource(['type' => 'sqlite', 'database' => ':memory:', 'entities' => [CrossItem::class], 'synchronize' => true]);
        $ds->initialize();
        $repo = $ds->getRepository(CrossItem::class);

        $item = $repo->create(['sku' => 'ABC', 'price' => 12.5]);
        $item->address = new CrossAddress;
        $item->address->city = 'Baghdad';
        $repo->save($item);
        self::assertSame(36, strlen($item->id));
        self::assertSame(1, $item->version);
        self::assertSame('abc', $item->slug);

        $item->price = 20.0;
        $item->sku = 'XYZ';
        $repo->save($item);
        self::assertSame(2, $item->version);

        $found = $repo->findOneBy(['id' => $item->id]);
        self::assertSame(20.0, $found->price);
        self::assertSame('Baghdad', $found->address->city);
        self::assertTrue($found->loaded);
        self::assertSame('xyz', $found->slug);
        self::assertCount(1, $repo->find(['where' => ['price' => MoreThan(15.0)]]));
        self::assertCount(1, $repo->find(['where' => ['address' => ['city' => 'Baghdad']]]));

        // upsert with a plain row: transformer + embedded columns, conflict on the unique sku
        $repo->upsert([['sku' => 'XYZ', 'slug' => 'xyz', 'price' => 30.0, 'address' => ['city' => 'Basra']]], ['sku']);
        $upserted = $repo->findOneBy(['sku' => 'XYZ']);
        self::assertSame(30.0, $upserted->price);
        self::assertSame('Basra', $upserted->address->city);
        self::assertSame(1, $repo->count());

        $ds->transaction(function (EntityManager $em): void {
            $tx = $em->getRepository(CrossItem::class);
            $row = $tx->findOneBy(['sku' => 'XYZ']);
            $row->price = 1.0;
            $tx->save($row);
        });
        self::assertSame(1.0, $repo->findOneBy(['sku' => 'XYZ'])->price);
    }

    public function testInheritanceAndTreesWithOtherFeatures(): void
    {
        $ds = new DataSource(['type' => 'sqlite', 'database' => ':memory:', 'synchronize' => true, 'entities' => [CrossAsset::class, CrossBuilding::class, CrossVehicle::class, CrossFolder::class]]);
        $assets = $ds->getRepository(CrossAsset::class);
        $buildings = $ds->getRepository(CrossBuilding::class);
        $vehicles = $ds->getRepository(CrossVehicle::class);

        $hq = $buildings->create(['code' => 'hq', 'value' => 1000.5, 'floors' => 3, 'site' => ['city' => 'Erbil']]);
        $buildings->save($hq);
        self::assertSame(36, strlen($hq->id));
        self::assertSame('HQ', $hq->code);
        $vehicles->save($vehicles->create(['code' => 'V1', 'value' => 20.0, 'plate' => 'ABC']));
        self::assertSame(['building', 'vehicle'], array_column($ds->getConnection()->fetchAll('SELECT asset_type FROM cross_assets ORDER BY code'), 'asset_type'));

        $found = $assets->findOneBy(['site' => ['city' => 'Erbil']]);
        self::assertInstanceOf(CrossBuilding::class, $found);
        self::assertSame(1000.5, $found->value);
        self::assertTrue($found->loaded);
        self::assertCount(1, $vehicles->find(['where' => ['value' => MoreThan(1.0)]]), 'find operators + transformer, limited to vehicles');

        // upsert through a child repository writes its discriminator; optimistic version still bumps on save
        $vehicles->upsert([['code' => 'V2', 'value' => 5.0, 'plate' => 'XYZ']], ['code']);
        self::assertSame(2, $vehicles->count());
        self::assertSame('vehicle', $ds->getConnection()->fetchColumn("SELECT asset_type FROM cross_assets WHERE code = 'V2'"));
        $found->floors = 4;
        $buildings->save($found);
        self::assertSame(2, $found->version);

        // soft delete through the child, visible through the parent only withDeleted
        $vehicles->softDelete(['code' => 'V1']);
        self::assertSame(1, $vehicles->count());
        self::assertSame(2, $assets->count());
        self::assertSame(3, $assets->count(['withDeleted' => true]));
        self::assertSame(0, $buildings->softDelete(['code' => 'V2']), 'a child never touches other types');

        // materialized path with uuid keys, a level column named differently and a version column
        $folders = $ds->getTreeRepository(CrossFolder::class);
        $root = $folders->create(['name' => 'root', 'children' => [['name' => 'docs', 'children' => [['name' => 'cv']]], ['name' => 'pics']]]);
        $folders->save($root);
        $docs = $folders->findOneBy(['name' => 'docs']);
        $cv = $folders->findOneBy(['name' => 'cv']);
        self::assertSame([0, 1, 2], [$root->depth, $docs->depth, $cv->depth]);
        self::assertSame($root->id . '.' . $docs->id . '.' . $cv->id . '.', $ds->getConnection()->fetchColumn('SELECT mpath FROM cross_folders WHERE id = :id', ['id' => $cv->id]));
        self::assertSame(['cv', 'docs', 'root'], self::sortedNames($folders->findAncestors($cv)));
        $docs->parent = $folders->findOneBy(['name' => 'pics']);
        $folders->save($docs);
        self::assertSame(2, $docs->version);
        self::assertSame(['cv', 'docs', 'pics', 'root'], self::sortedNames($folders->findAncestors($cv)));
        self::assertSame(3, $folders->findOneBy(['name' => 'cv'])->depth);
        self::assertSame(['cv', 'docs', 'pics'], self::sortedNames($folders->findDescendants($folders->findOneBy(['name' => 'pics']))));
    }

    /**
     * Write safety with the other features: a never-loaded single table inheritance child with embedded, transformer,
     * version, soft delete and listeners; relation-free criteria writes; save options.
     */
    public function testNeverLoadedSavesAndCriteriaWritesWithOtherFeatures(): void
    {
        $ds = new DataSource(['type' => 'sqlite', 'database' => ':memory:', 'synchronize' => true, 'entities' => [CrossAsset::class, CrossBuilding::class, CrossVehicle::class, CrossItem::class]]);
        $buildings = $ds->getRepository(CrossBuilding::class);
        $hq = $buildings->save($buildings->create(['code' => 'hq', 'value' => 10.5, 'floors' => 3, 'site' => ['city' => 'Erbil', 'street' => 'Main']]));
        $buildings->softRemove($hq);
        $row = fn (): array => $ds->getConnection()->fetchOne('SELECT * FROM cross_assets WHERE id = :id', ['id' => $hq->id]);
        $before = $row();

        // only `floors` and one embedded column are given: the rest (embedded street, value, code, deletedAt) stays
        $partial = $buildings->create(['id' => $hq->id, 'floors' => 4, 'site' => ['city' => 'Duhok']]);
        $buildings->save($partial);
        $after = $row();
        self::assertSame([4, 'Duhok', 'Main', 'HQ', 1050, 'building'], [(int) $after['floors'], $after['site_city'], $after['site_street'], $after['code'], (int) $after['value'], $after['asset_type']]);
        self::assertSame($before['deleted_at'], $after['deleted_at'], 'still soft-deleted');
        self::assertSame(3, (int) $after['version'], 'softRemove bumped it to 2');
        self::assertSame([3, 10.5, 'Main'], [$partial->version, $partial->value, $partial->site->street], 'the entity now holds the stored values');

        // listeners see the whole entity (uninitialized properties are read from the row)
        $items = $ds->getRepository(CrossItem::class);
        $item = $items->save($items->create(['sku' => 'ABC', 'price' => 1.0, 'address' => ['city' => 'Basra']]));
        $items->save($items->create(['id' => $item->id, 'price' => 2.5]), ['reload' => false, 'chunk' => 1]);
        $stored = $items->findOneByOrFail(['id' => $item->id]);
        self::assertSame(['abc', 2.5, 'Basra', 2], [$stored->slug, $stored->price, $stored->address->city, $stored->version]);

        // criteria writes: ids, lists, relations-free criteria; never empty
        self::assertSame(1, $items->update([$item->id], ['price' => 3.0]));
        self::assertSame(1, $buildings->restore([$hq->id]));
        try {
            $buildings->softDelete([]);
            self::fail('empty criteria');
        } catch (\Nesto\Orm\OrmException $e) {
            self::assertSame('Empty criteria(s) are not allowed for the softDelete method.', $e->getMessage());
        }
    }

    /** Replicated reads, query cache, streaming, logging and a view over a single table inheritance child entity. */
    public function testDataAccessFeaturesWithInheritance(): void
    {
        $file = sys_get_temp_dir() . '/nesto-cross-' . bin2hex(random_bytes(4)) . '.sqlite';
        $log = new CrossQueryLog(['query']);
        try {
            $ds = new DataSource([
                'type' => 'sqlite', 'synchronize' => true, 'cache' => ['duration' => 60_000], 'logger' => $log,
                'replication' => ['master' => ['database' => $file], 'slaves' => [['database' => $file]]],
                'entities' => [CrossAsset::class, CrossBuilding::class, CrossVehicle::class, CrossAssetCount::class],
            ]);
            $buildings = $ds->getRepository(CrossBuilding::class);
            $vehicles = $ds->getRepository(CrossVehicle::class);
            $buildings->save($buildings->create(['code' => 'hq', 'value' => 10.0, 'site' => ['city' => 'Erbil']]));
            $vehicles->save([$vehicles->create(['code' => 'V1', 'value' => 1.5, 'plate' => 'AAA']), $vehicles->create(['code' => 'V2', 'value' => 2.5, 'plate' => 'BBB'])]);
            self::assertSame([], $ds->logSchema(), 'cache table + view + inheritance table: sync then diff is empty');

            // cached find / count on the child: only its rows, hydrated as the child, AfterLoad fired on cache hits too
            $cached = fn () => $vehicles->find(['order' => ['code' => 'ASC'], 'cache' => ['id' => 'vehicles']]);
            self::assertSame(['AAA', 'BBB'], array_map(fn (CrossVehicle $v) => $v->plate, $cached()));
            self::assertSame(2, $vehicles->count(['cache' => ['id' => 'vehicles']]));
            $ds->manager->query("UPDATE cross_assets SET plate = 'CCC' WHERE code = 'V1'");
            $vehicles->save($vehicles->create(['code' => 'V3', 'value' => 3.0]));
            $again = $cached();
            self::assertSame(['AAA', 'BBB'], array_map(fn (CrossVehicle $v) => $v->plate, $again), 'served from the cache');
            self::assertContainsOnlyInstancesOf(CrossVehicle::class, $again);
            self::assertTrue($again[0]->loaded);
            self::assertSame(2, $vehicles->count(['cache' => ['id' => 'vehicles']]));
            $ds->queryResultCache->remove(['vehicles', 'vehicles-count']);
            self::assertSame(3, $vehicles->count(['cache' => ['id' => 'vehicles']]));

            // streamed iteration over the child entity (and the parent, which yields every subtype)
            $log->queries = [];
            $streamed = iterator_to_array($vehicles->findIterable(['order' => ['code' => 'ASC']], 2), false);
            self::assertSame(['V1', 'V2', 'V3'], array_map(fn (CrossVehicle $v) => $v->code, $streamed));
            self::assertSame('CCC', $streamed[0]->plate);
            self::assertTrue($streamed[2]->loaded);
            self::assertStringContainsString('"asset_type" IN (', implode("\n", $log->queries), 'the stream is limited to the discriminator values');
            $all = iterator_to_array($ds->createQueryBuilder(CrossAsset::class, 'a')->orderBy('a.code')->getManyIterable(1), false);
            self::assertSame([CrossBuilding::class, CrossVehicle::class, CrossVehicle::class, CrossVehicle::class], array_map(fn (object $a) => $a::class, $all));
            self::assertSame(['V1', 'V2', 'V3'], array_column(iterator_to_array($ds->createQueryBuilder(CrossVehicle::class, 'v')->orderBy('v.code')->stream(), false), 'v__code'));

            // a view over the inheritance table
            $vehicles->softDelete(['code' => 'V3']);
            $counts = $ds->getRepository(CrossAssetCount::class)->find(['order' => ['type' => 'ASC']]);
            self::assertSame([['building', 1], ['vehicle', 2]], array_map(fn (CrossAssetCount $c) => [$c->type, $c->total], $counts));
            $ds->destroy();
        } finally {
            @unlink($file);
        }
    }

    /** @param list<object> $nodes @return list<string> */
    private static function sortedNames(array $nodes): array
    {
        $names = array_map(fn (object $n) => $n->name, $nodes);
        sort($names);

        return $names;
    }
}
