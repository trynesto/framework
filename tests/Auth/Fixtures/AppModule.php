<?php

declare(strict_types=1);

namespace Nesto\Tests\Auth\Fixtures;

use Nesto\Auth\AuthModule;
use Nesto\Common\Attributes\Module;
use Nesto\Config\ConfigModule;
use Nesto\Jwt\JwtModule;

#[Module(imports: [
    new ConfigModule(isGlobal: true, ignoreEnvFile: true),
    new JwtModule(secret: 'x', global: true, signOptions: ['expiresIn' => '1h']),
    new AuthModule(defaultStrategy: 'jwt'),
    UsersModule::class,
])]
final class AppModule {}
