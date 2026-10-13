<?php

declare(strict_types=1);

namespace Nesto\Queue;

use Nesto\Common\Contracts\DynamicModuleFactory;
use Nesto\Common\DynamicModule;

/**
 * Attribute-friendly form of QueueModule::registerQueue():
 *
 *   #[Module(imports: [new QueueFeatureModule('mail', 'video')])]
 *   final class MailModule {}
 *
 * Each name becomes an injectable Queue: `#[InjectQueue('mail')] Queue $mail`.
 */
final class QueueFeatureModule implements DynamicModuleFactory
{
    /** @var list<string> */
    private readonly array $names;

    public function __construct(string ...$names)
    {
        $this->names = array_values($names);
    }

    public function toDynamicModule(): DynamicModule
    {
        return QueueModule::registerQueue(...$this->names);
    }
}
