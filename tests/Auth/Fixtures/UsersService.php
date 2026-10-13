<?php

declare(strict_types=1);

namespace Nesto\Tests\Auth\Fixtures;

use Nesto\Auth\Hash;

final class UsersService
{
    /** @var array<int, array{id: int, username: string, password: string, roles: list<string>}> */
    private array $users;

    public function __construct()
    {
        $this->users = [
            1 => ['id' => 1, 'username' => 'alice', 'password' => Hash::make('secret'), 'roles' => ['admin']],
            2 => ['id' => 2, 'username' => 'bob', 'password' => Hash::make('hunter2'), 'roles' => ['user']],
        ];
    }

    public function find(int $id): ?array
    {
        if (! isset($this->users[$id])) {
            return null;
        }
        $user = $this->users[$id];
        unset($user['password']);

        return $user;
    }

    public function findByUsername(string $username): ?array
    {
        foreach ($this->users as $user) {
            if ($user['username'] === $username) {
                return $user;
            }
        }

        return null;
    }
}
