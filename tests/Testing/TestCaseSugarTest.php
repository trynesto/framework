<?php

declare(strict_types=1);

namespace Nesto\Tests\Testing;

use Nesto\Testing\TestCase;
use Nesto\Tests\Testing\Fixtures\CatsModule;

require_once __DIR__ . '/Fixtures/Fixtures.php';

final class TestCaseSugarTest extends TestCase
{
    public function testCreateAppAndShortcuts(): void
    {
        $this->createApp(CatsModule::class)->setGlobalPrefix('api');

        $this->get('/api/cats')->assertOk()->assertJsonCount(2, 'data');
        $this->post('/api/cats', ['name' => 'Nala'])->assertCreated()->assertJsonPath('name', 'Nala');
        $this->delete('/api/cats/1')->assertNoContent();
        $this->put('/api/nothing')->assertNotFound();
        $this->patch('/api/nothing')->assertNotFound();
        $this->get('/api/cats/secret')->assertForbidden();
    }

    public function testUseModule(): void
    {
        $module = $this->createTestingModule(['imports' => [CatsModule::class]])->compile();
        $this->useModule($module);

        $this->get('/cats/1')->assertOk()->assertJson(['id' => 1, 'name' => 'Tom']);
        self::assertSame($module->app(), $this->app());
    }

    public function testAppThrowsWithoutBoot(): void
    {
        $this->expectException(\LogicException::class);
        $this->app();
    }
}
