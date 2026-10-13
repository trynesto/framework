<?php

declare(strict_types=1);

namespace Nesto\Tests\Support;

use Nesto\Core\Paths;
use Nesto\Logger\LogConfig;
use Nesto\Logger\Logger;
use Nesto\Tests\Cli\GeneratorTest;

/**
 * A throwaway project directory (Paths::base()) plus environment variables that are restored afterwards, for the
 * tests of the .env-driven modules (logging, cache, database). Call cleanSandbox() in tearDown().
 */
trait ProjectSandbox
{
    private ?string $sandbox = null;
    /** @var list<string> */
    private array $sandboxEnv = [];

    protected function sandbox(): string
    {
        if ($this->sandbox === null) {
            $this->sandbox = sys_get_temp_dir() . '/nesto-sandbox-' . bin2hex(random_bytes(5));
            mkdir($this->sandbox . '/config', 0777, true);
            file_put_contents($this->sandbox . '/composer.json', '{}');
            Paths::setBase($this->sandbox);
        }

        return $this->sandbox;
    }

    /** Write a file of the sandbox project (relative path). */
    protected function sandboxFile(string $relative, string $content): string
    {
        $file = $this->sandbox() . '/' . $relative;
        if (! is_dir(dirname($file))) {
            mkdir(dirname($file), 0777, true);
        }
        file_put_contents($file, $content);

        return $file;
    }

    /** @param array<string, string|null> $vars null unsets */
    protected function setEnv(array $vars): void
    {
        foreach ($vars as $key => $value) {
            $this->sandboxEnv[] = $key;
            if ($value === null) {
                putenv($key);
                unset($_ENV[$key], $_SERVER[$key]);
                continue;
            }
            putenv("$key=$value");
            $_ENV[$key] = $value;
            $_SERVER[$key] = $value;
        }
    }

    protected function cleanSandbox(): void
    {
        foreach (array_unique($this->sandboxEnv) as $key) {
            putenv($key);
            unset($_ENV[$key], $_SERVER[$key]);
        }
        $this->sandboxEnv = [];
        Logger::reset();
        LogConfig::reset();
        Paths::setBase(null);
        if ($this->sandbox !== null) {
            GeneratorTest::rrmdir($this->sandbox);
            $this->sandbox = null;
        }
    }
}
