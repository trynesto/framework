<?php

declare(strict_types=1);

namespace Nesto\Tests\Cli;

use Nesto\Cli\Generator\Str;
use PHPUnit\Framework\TestCase;

final class StrTest extends TestCase
{
    public function testCases(): void
    {
        self::assertSame('UserProfiles', Str::pascal('user-profiles'));
        self::assertSame('UserProfiles', Str::pascal('user_profiles'));
        self::assertSame('UserProfiles', Str::pascal('userProfiles'));
        self::assertSame('userProfiles', Str::camel('UserProfiles'));
        self::assertSame('user-profiles', Str::kebab('UserProfiles'));
        self::assertSame('user_profiles', Str::snake('user-profiles'));
    }

    public function testSingularPlural(): void
    {
        self::assertSame('Cat', Str::singular('Cats'));
        self::assertSame('Category', Str::singular('Categories'));
        self::assertSame('Person', Str::singular('People'));
        self::assertSame('Status', Str::singular('Status'));
        self::assertSame('Box', Str::singular('Boxes'));
        self::assertSame('Cats', Str::plural('Cat'));
        self::assertSame('Categories', Str::plural('Category'));
        self::assertSame('Boxes', Str::plural('Box'));
    }
}
