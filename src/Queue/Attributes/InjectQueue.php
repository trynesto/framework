<?php

declare(strict_types=1);

namespace Nesto\Queue\Attributes;

use Attribute;
use Nesto\Common\Attributes\Inject;
use Nesto\Queue\QueueModule;

/**
 * Inject a Queue registered with QueueModule::registerQueue() / QueueFeatureModule (Nest's @InjectQueue()).
 *
 *   public function __construct(#[InjectQueue('mail')] private Queue $mail) {}
 */
#[Attribute(Attribute::TARGET_PARAMETER | Attribute::TARGET_PROPERTY)]
final class InjectQueue extends Inject
{
    public function __construct(public readonly string $name)
    {
        parent::__construct(QueueModule::queueToken($name));
    }
}
