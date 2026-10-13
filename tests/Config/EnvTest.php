<?php

declare(strict_types=1);

namespace Nesto\Tests\Config;

use Nesto\Config\Env;
use PHPUnit\Framework\TestCase;

final class EnvTest extends TestCase
{
    protected function tearDown(): void
    {
        foreach (['NESTO_T_A', 'NESTO_T_B', 'NESTO_T_EXISTING', 'NESTO_T_FILE', 'NESTO_T_EXP', 'NESTO_T_QUOTED'] as $k) {
            unset($_ENV[$k], $_SERVER[$k]);
            putenv($k);
        }
    }

    public function testParsesAllSyntaxes(): void
    {
        $vars = Env::parse(<<<'ENV'
        # comment line
        PLAIN=hello world   # inline comment
        export EXPORTED=yes
        EMPTY=
        DQ="line1\nline2 \"quoted\" ${PLAIN}"
        SQ='literal ${PLAIN} \n'
        REF=${PLAIN}/${MISSING:-fallback}
        DOLLAR=$PLAIN!
        URL=postgres://user:pa$$word@host
        MULTI="first
        second"
        ENV);

        self::assertSame('hello world', $vars['PLAIN']);
        self::assertSame('yes', $vars['EXPORTED']);
        self::assertSame('', $vars['EMPTY']);
        self::assertSame("line1\nline2 \"quoted\" hello world", $vars['DQ']);
        self::assertSame('literal ${PLAIN} \n', $vars['SQ']);
        self::assertSame('hello world/fallback', $vars['REF']);
        self::assertSame('hello world!', $vars['DOLLAR']);
        self::assertSame('postgres://user:pa$$word@host', $vars['URL']);
        self::assertSame("first\nsecond", $vars['MULTI']);
    }

    public function testLoadPopulatesWithoutOverridingUnlessAsked(): void
    {
        putenv('NESTO_T_EXISTING=original');
        $file = tempnam(sys_get_temp_dir(), 'env');
        file_put_contents($file, "NESTO_T_FILE=from-file\nNESTO_T_EXISTING=changed\nNESTO_T_EXP=\${NESTO_T_FILE}-x\n");

        Env::load($file);
        self::assertSame('from-file', $_ENV['NESTO_T_FILE']);
        self::assertSame('from-file', getenv('NESTO_T_FILE'));
        self::assertSame('original', getenv('NESTO_T_EXISTING'));
        self::assertSame('from-file-x', $_SERVER['NESTO_T_EXP']);

        Env::load($file, override: true);
        self::assertSame('changed', getenv('NESTO_T_EXISTING'));

        self::assertSame([], Env::load('/definitely/missing/.env'));
        unlink($file);
    }

    public function testCast(): void
    {
        self::assertTrue(Env::cast('true'));
        self::assertFalse(Env::cast('FALSE'));
        self::assertNull(Env::cast('null'));
        self::assertSame('', Env::cast('(empty)'));
        self::assertSame('3000', Env::cast('3000'));
    }
}
