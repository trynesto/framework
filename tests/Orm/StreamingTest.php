<?php

declare(strict_types=1);

namespace Nesto\Tests\Orm;

use Nesto\Orm\DataSource;
use Nesto\Orm\MetadataRegistry;
use Nesto\Orm\Repository;
use Nesto\Tests\Orm\Fixtures\DataAccess\DaAuthor;
use Nesto\Tests\Orm\Fixtures\DataAccess\DaNote;
use PHPUnit\Framework\TestCase;

/** QueryBuilder::stream() / getManyIterable(), Repository::findIterable() / chunk() on SQLite (see the driver scenarios for the others). */
final class StreamingTest extends TestCase
{
    private DataSource $ds;
    private Repository $notes;

    protected function setUp(): void
    {
        MetadataRegistry::clear();
        $this->ds = new DataSource(['type' => 'sqlite', 'database' => ':memory:', 'entities' => [DaAuthor::class, DaNote::class], 'synchronize' => true]);
        $this->notes = $this->ds->getRepository(DaNote::class);
        $authors = $this->ds->getRepository(DaAuthor::class);
        $ada = $authors->save($authors->create(['name' => 'Ada']));
        $bob = $authors->save($authors->create(['name' => 'Bob']));
        $conn = $this->ds->getConnection();
        $conn->transaction(function () use ($conn, $ada, $bob): void {
            for ($i = 1; $i <= 25; $i++) {
                $conn->execute('INSERT INTO da_notes (title, views, author_id) VALUES (:t, :v, :a)', ['t' => "note $i", 'v' => $i, 'a' => $i % 2 ? $ada->id : $bob->id]);
            }
        });
    }

    public function testStreamYieldsTheRawRows(): void
    {
        $qb = fn () => $this->ds->createQueryBuilder(DaNote::class, 'n')->where('n.views > :v', ['v' => 20])->orderBy('n.id');
        $stream = $qb()->stream();
        $this->assertInstanceOf(\Generator::class, $stream);
        $this->assertSame($qb()->getRawMany(), iterator_to_array($stream, false));
        $this->assertSame(['n__title' => 'note 21'], array_intersect_key($qb()->stream()->current(), ['n__title' => 1]));
        $this->assertSame([['t' => 'note 1']], iterator_to_array($this->ds->createQueryBuilder('da_notes', 'n')->select('n.title AS t')->where('n.id = 1')->stream(), false));
    }

    public function testLeavingTheLoopEarlyClosesTheStatement(): void
    {
        foreach ($this->ds->createQueryBuilder(DaNote::class, 'n')->stream() as $row) {
            break;
        }
        unset($row);
        // SQLite refuses to drop a table while a statement still reads it
        $this->ds->getConnection()->execute('DROP TABLE da_notes');
        $this->assertFalse($this->ds->schema()->hasTable('da_notes'));
    }

    public function testGetManyIterableHydratesInBatches(): void
    {
        $batches = [];
        $titles = [];
        $iterable = $this->ds->createQueryBuilder(DaNote::class, 'n')->leftJoinAndSelect('n.author', 'a')->orderBy('n.id')
            ->getManyIterable(10, function (array $batch) use (&$batches): void {
                $batches[] = count($batch);
            });
        foreach ($iterable as $note) {
            $this->assertInstanceOf(DaNote::class, $note);
            $titles[] = $note->title . '/' . $note->author->name;
        }
        $this->assertSame([10, 10, 5], $batches);
        $this->assertCount(25, $titles);
        $this->assertSame('note 1/Ada', $titles[0]);
        $this->assertSame('note 2/Bob', $titles[1]);

        // to-many joins: every author keeps all its notes even when the batch boundary falls inside
        $authors = iterator_to_array($this->ds->createQueryBuilder(DaAuthor::class, 'a')->leftJoinAndSelect('a.notes', 'n')->getManyIterable(1), false);
        $this->assertSame(['Ada', 'Bob'], array_map(fn (DaAuthor $a) => $a->name, $authors));
        $this->assertSame([13, 12], array_map(fn (DaAuthor $a) => count($a->notes), $authors));

        // paginated to-many joins (two-step query)
        $page = iterator_to_array($this->ds->createQueryBuilder(DaAuthor::class, 'a')->leftJoinAndSelect('a.notes', 'n')->orderBy('a.id', 'DESC')->take(1)->getManyIterable(), false);
        $this->assertSame(['Bob'], array_map(fn (DaAuthor $a) => $a->name, $page));
        $this->assertCount(12, $page[0]->notes);
        $this->assertSame([], iterator_to_array($this->ds->createQueryBuilder(DaAuthor::class, 'a')->leftJoinAndSelect('a.notes', 'n')->skip(5)->take(1)->getManyIterable(), false));
    }

    public function testRepositoryFindIterableAndChunk(): void
    {
        $seen = [];
        foreach ($this->notes->findIterable(['where' => ['views' => ['>', 15]], 'relations' => ['author'], 'order' => ['views' => 'DESC']], 4) as $note) {
            $seen[] = $note->views . ':' . $note->author->name;
        }
        $this->assertSame(['25:Ada', '24:Bob', '23:Ada'], array_slice($seen, 0, 3));
        $this->assertCount(10, $seen);

        $pages = [];
        $this->assertTrue($this->notes->chunk(10, function (array $notes, int $page) use (&$pages): void {
            $pages[$page] = array_map(fn (DaNote $n) => $n->views, $notes);
        }));
        $this->assertSame([range(1, 10), range(11, 20), range(21, 25)], $pages);

        $count = 0;
        $this->assertFalse($this->notes->chunk(5, function (array $notes) use (&$count): bool {
            $count++;

            return $count < 2;
        }, ['where' => ['views' => ['<=', 20]]]));
        $this->assertSame(2, $count);
    }

    public function testStreamingKeepsMemoryBounded(): void
    {
        $conn = $this->ds->getConnection();
        $body = str_repeat('x', 1000);
        $conn->transaction(function () use ($conn, $body): void {
            for ($i = 0; $i < 4000; $i++) {
                $conn->execute('INSERT INTO da_notes (title, body, views) VALUES (:t, :b, :v)', ['t' => "bulk $i", 'b' => $body . $i, 'v' => $i]);
            }
        });
        $qb = fn () => $this->ds->createQueryBuilder(DaNote::class, 'n');

        gc_collect_cycles();
        $base = memory_get_usage();
        memory_reset_peak_usage();
        $rows = $qb()->getRawMany();
        $buffered = memory_get_peak_usage() - $base;
        $this->assertCount(4025, $rows);
        unset($rows);

        gc_collect_cycles();
        $base = memory_get_usage();
        memory_reset_peak_usage();
        $count = 0;
        foreach ($qb()->stream() as $row) {
            $count++;
        }
        $streamed = memory_get_peak_usage() - $base;
        $this->assertSame(4025, $count);
        $this->assertLessThan($buffered / 10, $streamed, "stream peak {$streamed} B vs getRawMany {$buffered} B");

        gc_collect_cycles();
        $base = memory_get_usage();
        memory_reset_peak_usage();
        $count = 0;
        foreach ($qb()->getManyIterable(100) as $note) {
            $count++;
        }
        $iterated = memory_get_peak_usage() - $base;
        $this->assertSame(4025, $count);
        $this->assertLessThan($buffered / 4, $iterated, "getManyIterable peak {$iterated} B vs getRawMany {$buffered} B");
    }
}
