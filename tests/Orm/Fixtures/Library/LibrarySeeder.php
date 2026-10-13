<?php

declare(strict_types=1);

namespace Nesto\Tests\Orm\Fixtures\Library;

use Nesto\Orm\EntityManager;

/**
 * Data set of the relation behaviour tests (cascades, orphans, find options, relation query builder):
 *
 *   authors   Ada (rank 2, bio "Countess"): Engines, Notes · Bob (rank 1): Sailing · Cyd (rank 3): no books
 *   books     Engines (300 p., science + history, chapters E1 E2, reviews R1 R2, notes N1 N2)
 *             Notes (120 p., science) · Sailing (200 p., travel)
 *   genres    science, history, travel, poetry (no books)
 *
 * Everything is inserted by ONE save per author through the cascades.
 */
final class LibrarySeeder
{
    public const ENTITIES = [LibAuthor::class, LibBook::class, LibGenre::class, LibChapter::class, LibReview::class, LibNote::class, LibBio::class];

    /** @return array<string, object> */
    public static function seed(EntityManager $em): array
    {
        $e = [];
        foreach (['science', 'history', 'travel', 'poetry'] as $name) {
            $e[$name] = $em->create(LibGenre::class, ['name' => $name]);
        }
        $e['engines'] = $em->create(LibBook::class, ['title' => 'Engines', 'pages' => 300]);
        $e['engines']->genres = [$e['science'], $e['history']];
        $e['engines']->chapters = [$em->create(LibChapter::class, ['title' => 'E1']), $em->create(LibChapter::class, ['title' => 'E2'])];
        $e['engines']->reviews = [$em->create(LibReview::class, ['body' => 'R1']), $em->create(LibReview::class, ['body' => 'R2'])];
        $e['engines']->notes = [$em->create(LibNote::class, ['text' => 'N1']), $em->create(LibNote::class, ['text' => 'N2'])];
        $e['notes'] = $em->create(LibBook::class, ['title' => 'Notes', 'pages' => 120]);
        $e['notes']->genres = [$e['science']];
        $e['sailing'] = $em->create(LibBook::class, ['title' => 'Sailing', 'pages' => 200]);
        $e['sailing']->genres = [$e['travel']];

        $e['ada'] = $em->create(LibAuthor::class, ['name' => 'Ada', 'rank' => 2]);
        $e['ada']->books = [$e['engines'], $e['notes']];
        $e['ada']->bio = $em->create(LibBio::class, ['text' => 'Countess']);
        $e['bob'] = $em->create(LibAuthor::class, ['name' => 'Bob', 'rank' => 1]);
        $e['bob']->books = [$e['sailing']];
        $e['cyd'] = $em->create(LibAuthor::class, ['name' => 'Cyd', 'rank' => 3]);
        $em->save([$e['ada'], $e['bob'], $e['cyd'], $e['poetry']]);

        return $e;
    }
}
