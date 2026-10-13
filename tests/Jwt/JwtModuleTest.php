<?php

declare(strict_types=1);

namespace Nesto\Tests\Jwt;

use Nesto\Common\Attributes\Module;
use Nesto\Common\DynamicModule;
use Nesto\Config\ConfigModule;
use Nesto\Config\ConfigService;
use Nesto\Core\NestoFactory;
use Nesto\Jwt\JwtModule;
use Nesto\Jwt\JwtService;
use PHPUnit\Framework\TestCase;

final class JwtModuleTest extends TestCase
{
    public function testAttributeImportProvidesJwtService(): void
    {
        $app = NestoFactory::create(JwtAttributeRoot::class)->init();
        $jwt = $app->select(JwtConsumerModule::class)->get(JwtService::class);
        self::assertInstanceOf(JwtService::class, $jwt);
        $token = $jwt->sign(['sub' => 1]);
        self::assertSame(1, $jwt->verify($token)['sub']);
        self::assertSame(time() + 7200, $jwt->decode($token)['exp']);
    }

    public function testRegisterAsyncUsesConfigService(): void
    {
        $root = new DynamicModule(module: JwtAsyncRoot::class, imports: [
            ConfigModule::forRoot(['isGlobal' => true, 'ignoreEnvFile' => true, 'load' => [fn () => ['jwt' => ['secret' => 'async-secret']]]]),
            JwtModule::registerAsync([
                'useFactory' => fn (ConfigService $config) => ['secret' => $config->getOrThrow('jwt.secret'), 'signOptions' => ['expiresIn' => '5m']],
                'global' => true,
            ]),
        ]);
        $app = NestoFactory::create($root)->init();
        $jwt = $app->get(JwtService::class);
        self::assertSame('async-secret', $jwt->options()['secret']);
        self::assertSame(300, $jwt->decode($jwt->sign([]))['exp'] - time());

        $injected = NestoFactory::create(new DynamicModule(module: JwtAsyncRoot::class, imports: [
            ConfigModule::forRoot(['isGlobal' => true, 'ignoreEnvFile' => true]),
            JwtModule::registerAsync(['useFactory' => fn (ConfigService $c) => ['secret' => 'inject'], 'inject' => [ConfigService::class]]),
        ]))->init()->get(JwtService::class);
        self::assertSame('inject', $injected->options()['secret']);
    }
}

#[Module(imports: [new JwtModule(secret: 'attr', global: true, signOptions: ['expiresIn' => '2h']), JwtConsumerModule::class])]
final class JwtAttributeRoot {}

#[Module]
final class JwtConsumerModule {}

#[Module]
final class JwtAsyncRoot {}
