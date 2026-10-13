<?php

declare(strict_types=1);

namespace Nesto\Tests\Config;

use Nesto\Config\ConfigException;
use Nesto\Config\ConfigService;
use PHPUnit\Framework\TestCase;

final class ConfigServiceTest extends TestCase
{
    protected function setUp(): void
    {
        putenv('APP_NAME=FromEnv');
        putenv('DATABASE_READ_HOST=replica');
        putenv('FEATURE_FLAG=true');
        putenv('PORT=8080');
    }

    protected function tearDown(): void
    {
        foreach (['APP_NAME', 'DATABASE_READ_HOST', 'FEATURE_FLAG', 'PORT'] as $k) {
            putenv($k);
            unset($_ENV[$k], $_SERVER[$k]);
        }
    }

    public function testTreeWinsOverEnvAndFallsBackToUpperSnake(): void
    {
        $config = new ConfigService(['app' => ['name' => 'Tree', 'debug' => false]], ['PORT' => 8080]);

        self::assertSame('Tree', $config->get('app.name'));
        self::assertFalse($config->get('app.debug'));
        self::assertSame('replica', $config->get('database.readHost'));
        self::assertTrue($config->get('feature.flag'));
        self::assertTrue($config->get('FEATURE_FLAG'));
        self::assertSame(8080, $config->get('PORT'), 'validated env values keep their cast type');
        self::assertSame('default', $config->get('nothing.here', 'default'));
        self::assertTrue($config->has('app.name'));
        self::assertFalse($config->has('nope'));
    }

    public function testGetOrThrowSetAndMerge(): void
    {
        $config = new ConfigService(['a' => ['b' => 1]]);
        self::assertSame(1, $config->getOrThrow('a.b'));
        $config->set('a.c', 2);
        $config->merge(['a' => ['d' => 3], 'x' => 'y']);
        self::assertSame(['a' => ['b' => 1, 'c' => 2, 'd' => 3], 'x' => 'y'], $config->all());

        $this->expectException(ConfigException::class);
        $config->getOrThrow('missing.key');
    }

    public function testCacheCanBeDisabled(): void
    {
        $cached = new ConfigService([], [], true);
        $fresh = new ConfigService([], [], false);
        self::assertSame('FromEnv', $cached->get('app.name'));
        self::assertSame('FromEnv', $fresh->get('app.name'));
        putenv('APP_NAME=Changed');
        self::assertSame('FromEnv', $cached->get('app.name'));
        self::assertSame('Changed', $fresh->get('app.name'));
    }
}
