<?php

declare(strict_types=1);

namespace Nesto\Tests\Orm\Fixtures\Relations;

use Nesto\Orm\EntityManager;

/**
 * Shared data set of the polymorphic / through relation tests (unit tests and the driver suite).
 *
 *   countries  Iraq (Ada, Bob), Japan (Kei), Chile (no writers)
 *   articles   A1 (Ada), A2 (Ada), A3 (Bob), A4 (Kei)        videos V1, V2
 *   comments   A1: a1-1, a1-2 · A2: a2-1 · V1: v1-1, v1-2 · orphan (no commentable)
 *   images     cover of A1 (type "article"), avatar of Ada (type = Writer class name)
 *   labels     A1: php, orm · A2: sql · V1: php
 *   paragraphs P1 (A1), P2 (A3), P3 (A4)
 *   mechanics  Mo (car T → owner Ow), Lu (no car)
 */
final class RelationsSeeder
{
    public const ENTITIES = [Country::class, Writer::class, Article::class, Video::class, Comment::class, Image::class, Label::class, Paragraph::class, Mechanic::class, Car::class, Owner::class];
    public const MORPH_MAP = ['video' => Video::class];

    /** @return array<string, object> */
    public static function seed(EntityManager $em): array
    {
        $e = [];
        $mk = function (string $class, array $plain) use ($em): object {
            return $em->create($class, $plain);
        };
        $e['iraq'] = $mk(Country::class, ['name' => 'Iraq']);
        $e['japan'] = $mk(Country::class, ['name' => 'Japan']);
        $e['chile'] = $mk(Country::class, ['name' => 'Chile']);
        $e['ada'] = $mk(Writer::class, ['name' => 'Ada']);
        $e['bob'] = $mk(Writer::class, ['name' => 'Bob']);
        $e['kei'] = $mk(Writer::class, ['name' => 'Kei']);
        $e['iraq']->writers = [$e['ada'], $e['bob']];   // OneToMany cascade (Writer::$country defaults to null)
        $e['japan']->writers = [$e['kei']];
        $em->save([$e['iraq'], $e['japan'], $e['chile']]);

        $e['php'] = $mk(Label::class, ['name' => 'php']);
        $e['sql'] = $mk(Label::class, ['name' => 'sql']);
        $e['orm'] = $mk(Label::class, ['name' => 'orm']);
        $em->save([$e['php'], $e['sql'], $e['orm']]);

        foreach (['a1' => 'ada', 'a2' => 'ada', 'a3' => 'bob', 'a4' => 'kei'] as $key => $writer) {
            $e[$key] = $mk(Article::class, ['title' => strtoupper($key)]);
            $e[$key]->writer = $e[$writer];
        }
        $e['a1']->comments = [$mk(Comment::class, ['body' => 'a1-1']), $mk(Comment::class, ['body' => 'a1-2'])];
        $e['a1']->labels = [$e['php'], $e['orm']];
        $e['a1']->cover = $mk(Image::class, ['url' => 'a1.png']);
        $e['a2']->comments = [$mk(Comment::class, ['body' => 'a2-1'])];
        $e['a2']->labels = [$e['sql']];
        $em->save([$e['a1'], $e['a2'], $e['a3'], $e['a4']]);

        $e['v1'] = $mk(Video::class, ['title' => 'V1']);
        $e['v1']->comments = [$mk(Comment::class, ['body' => 'v1-1']), $mk(Comment::class, ['body' => 'v1-2'])];
        $e['v1']->labels = [$e['php']];
        $e['v2'] = $mk(Video::class, ['title' => 'V2']);
        $em->save([$e['v1'], $e['v2']]);
        $em->save($mk(Comment::class, ['body' => 'orphan']));

        $e['ada']->avatar = $mk(Image::class, ['url' => 'ada.png']);
        $em->save($e['ada']);

        foreach (['p1' => 'a1', 'p2' => 'a3', 'p3' => 'a4'] as $key => $article) {
            $e[$key] = $mk(Paragraph::class, ['text' => strtoupper($key)]);
            $e[$key]->article = $e[$article];
        }
        $em->save([$e['p1'], $e['p2'], $e['p3']]);

        $e['mo'] = $mk(Mechanic::class, ['name' => 'Mo']);
        $e['lu'] = $mk(Mechanic::class, ['name' => 'Lu']);
        $em->save([$e['mo'], $e['lu']]);
        $e['car'] = $mk(Car::class, ['model' => 'T']);
        $e['car']->mechanic = $e['mo'];
        $e['owner'] = $mk(Owner::class, ['name' => 'Ow']);
        $e['owner']->car = $e['car'];   // cascades the car
        $em->save($e['owner']);

        return $e;
    }
}
