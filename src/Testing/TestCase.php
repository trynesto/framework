<?php

declare(strict_types=1);

namespace Nesto\Testing;

use Nesto\Common\DynamicModule;
use Nesto\Core\NestoApplication;
use Nesto\Core\NestoFactory;
use PHPUnit\Framework\TestCase as PHPUnitTestCase;

/**
 * Optional base class with sugar for end-to-end tests.
 *
 *   final class CatsTest extends \Nesto\Testing\TestCase
 *   {
 *       protected function setUp(): void
 *       {
 *           $this->createApp(AppModule::class)->setGlobalPrefix('api');
 *       }
 *
 *       public function testList(): void
 *       {
 *           $this->get('/api/cats')->assertOk()->assertJsonCount(0);
 *           $this->post('/api/cats', ['name' => 'Tom'])->assertCreated();
 *       }
 *   }
 */
abstract class TestCase extends PHPUnitTestCase
{
    protected ?NestoApplication $app = null;
    protected ?TestingModule $testingModule = null;

    /** Boot an application from a root module (NestoFactory::create) and keep it for the request helpers. */
    protected function createApp(string|DynamicModule $root, array $options = []): NestoApplication
    {
        return $this->app = NestoFactory::create($root, $options);
    }

    /** Shortcut for Test::createTestingModule(); the compiled module's app becomes the one used by get()/post()... */
    protected function createTestingModule(array $metadata = []): TestingModuleBuilder
    {
        return Test::createTestingModule($metadata);
    }

    /** Use a compiled TestingModule for the request helpers. */
    protected function useModule(TestingModule $module): TestingModule
    {
        $this->testingModule = $module;
        $this->app = $module->app();

        return $module;
    }

    protected function app(): NestoApplication
    {
        if ($this->app === null) {
            throw new \LogicException('No application: call $this->createApp(RootModule::class) or $this->useModule($module) first.');
        }

        return $this->app;
    }

    protected function request(): TestRequest
    {
        return new TestRequest($this->app());
    }

    protected function get(string $uri, array $headers = []): TestRequest
    {
        return $this->request()->get($uri)->withHeaders($headers);
    }

    /** @param array<string, mixed>|string|null $body */
    protected function post(string $uri, array|string|null $body = null, array $headers = []): TestRequest
    {
        return $this->request()->post($uri, $body)->withHeaders($headers);
    }

    /** @param array<string, mixed>|string|null $body */
    protected function put(string $uri, array|string|null $body = null, array $headers = []): TestRequest
    {
        return $this->request()->put($uri, $body)->withHeaders($headers);
    }

    /** @param array<string, mixed>|string|null $body */
    protected function patch(string $uri, array|string|null $body = null, array $headers = []): TestRequest
    {
        return $this->request()->patch($uri, $body)->withHeaders($headers);
    }

    /** @param array<string, mixed>|string|null $body */
    protected function delete(string $uri, array|string|null $body = null, array $headers = []): TestRequest
    {
        return $this->request()->delete($uri, $body)->withHeaders($headers);
    }

    protected function tearDown(): void
    {
        if ($this->app !== null) {
            try {
                $this->app->close();
            } catch (\Throwable) {
                // shutdown hooks must never fail a test
            }
        }
        $this->app = null;
        $this->testingModule = null;
        parent::tearDown();
    }
}
