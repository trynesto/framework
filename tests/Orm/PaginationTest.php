<?php

declare(strict_types=1);

namespace Nesto\Tests\Orm;

use Nesto\Orm\Pagination;
use PHPUnit\Framework\TestCase;

final class PaginationTest extends TestCase
{
    public function testMapTransformsTheItemsAndKeepsTheMeta(): void
    {
        $page = new Pagination([(object) ['id' => 1], (object) ['id' => 2]], total: 12, page: 2, perPage: 2);

        $mapped = $page->map(fn (object $item, int $index) => ['id' => $item->id, 'index' => $index]);

        self::assertSame([['id' => 1, 'index' => 0], ['id' => 2, 'index' => 1]], $mapped->data);
        self::assertSame(['total' => 12, 'page' => 2, 'perPage' => 2, 'lastPage' => 6], $mapped->meta());
        self::assertSame([['id' => 1, 'index' => 0], ['id' => 2, 'index' => 1]], $mapped->toArray()['data']);
        self::assertNotSame($page, $mapped);
        self::assertSame(1, $page->data[0]->id); // the original page is unchanged
    }
}
