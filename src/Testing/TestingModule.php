<?php

declare(strict_types=1);

namespace Nesto\Testing;

use Nesto\Core\ModuleRef;
use Nesto\Core\NestoApplication;

/**
 * The compiled test module: a DI context plus supertest-style request helpers.
 *
 *   $module = Test::createTestingModule([...])->compile();
 *   $service = $module->get(CatsService::class);
 *   $module->request()->post('/cats', ['name' => 'Tom'])->expect(201)->assertJsonPath('name', 'Tom');
 *
 *   $app = $module->createNestoApplication(['globalPrefix' => 'api']);   // same app, options applied
 *   $module->close();
 */
final class TestingModule
{
    public function __construct(private readonly NestoApplication $app) {}

    /** Resolve any provider/controller ignoring module boundaries (app.get()). */
    public function get(string $token): mixed
    {
        return $this->app->get($token);
    }

    public function select(string $moduleClass): ModuleRef
    {
        return $this->app->select($moduleClass);
    }

    public function app(): NestoApplication
    {
        return $this->app;
    }

    /**
     * Return the application, applying HTTP options first (before init):
     *   ['globalPrefix' => 'api', 'cors' => true|array, 'versioning' => ['prefix' => 'v'], 'init' => true]
     *
     * @param array<string, mixed> $options
     */
    public function createNestoApplication(array $options = []): NestoApplication
    {
        if (isset($options['globalPrefix'])) {
            $this->app->setGlobalPrefix((string) $options['globalPrefix']);
        }
        if (isset($options['cors']) && $options['cors'] !== false) {
            $this->app->enableCors(is_array($options['cors']) ? $options['cors'] : []);
        }
        if (isset($options['versioning'])) {
            $this->app->enableVersioning(is_array($options['versioning']) ? $options['versioning'] : []);
        }
        if (! empty($options['init'])) {
            $this->app->init();
        }

        return $this->app;
    }

    public function init(): self
    {
        $this->app->init();

        return $this;
    }

    public function close(): void
    {
        $this->app->close();
    }

    /** Start building an HTTP request against this module's application. */
    public function request(): TestRequest
    {
        return new TestRequest($this->app);
    }
}
