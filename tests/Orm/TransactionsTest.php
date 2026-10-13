<?php

declare(strict_types=1);

namespace Nesto\Tests\Orm;

use Nesto\Orm\EntityManager;
use Nesto\Tests\Orm\Fixtures\User;

final class TransactionsTest extends OrmTestCase
{
    public function testRollbackOnException(): void
    {
        try {
            $this->ds->transaction(function (EntityManager $em): void {
                $em->save($em->create(User::class, ['name' => 'T', 'email' => 't@example.com']));
                $this->assertSame(1, $em->count(User::class));
                throw new \RuntimeException('fail');
            });
            $this->fail('expected exception');
        } catch (\RuntimeException $e) {
            $this->assertSame('fail', $e->getMessage());
        }
        $this->assertSame(0, $this->users->count());
        $this->assertFalse($this->ds->getConnection()->inTransaction());
    }

    public function testCommitReturnsValue(): void
    {
        $user = $this->ds->transaction(fn (EntityManager $em) => $em->save($em->create(User::class, ['name' => 'T', 'email' => 't@example.com'])));
        $this->assertSame(1, $user->id);
        $this->assertSame(1, $this->users->count());
    }

    public function testNestedTransactionsUseSavepoints(): void
    {
        $conn = $this->ds->getConnection();
        $conn->enableQueryLog();
        $this->ds->transaction(function (EntityManager $em) use ($conn): void {
            $em->save($em->create(User::class, ['name' => 'Outer', 'email' => 'o@example.com']));
            try {
                $this->ds->transaction(function (EntityManager $em) use ($conn): void {
                    $this->assertSame(2, $conn->transactionDepth());
                    $em->save($em->create(User::class, ['name' => 'Inner', 'email' => 'i@example.com']));
                    throw new \LogicException('inner');
                });
            } catch (\LogicException) {
            }
            $this->assertSame(1, $conn->transactionDepth());
        });
        $this->assertSame(['Outer'], array_map(fn (User $u) => $u->name, $this->users->find()));
    }

    public function testManualTransactionApi(): void
    {
        $conn = $this->ds->getConnection();
        $conn->beginTransaction();
        $this->users->insert(['name' => 'M', 'email' => 'm@example.com']);
        $conn->rollBack();
        $this->assertSame(0, $this->users->count());
        $conn->beginTransaction();
        $this->users->insert(['name' => 'M', 'email' => 'm@example.com']);
        $conn->commit();
        $this->assertSame(1, $this->users->count());
    }
}
