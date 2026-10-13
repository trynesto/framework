<?php

declare(strict_types=1);

namespace Nesto\Transformer;

use Nesto\Common\Contracts\CallHandler;
use Nesto\Common\Contracts\Interceptor;
use Nesto\Core\ExecutionContext;
use Nesto\Core\Reflector;
use Psr\Http\Message\ResponseInterface;

/**
 * Nest's ClassSerializerInterceptor: runs the handler and converts whatever it returns
 * (an entity, a list of entities, a paginated array holding entities, ...) through
 * ClassTransformer::instanceToPlain(), honouring #[Exclude], #[Expose(groups)] and #[Transform].
 *
 *   $app->useGlobalInterceptors(new ClassSerializerInterceptor(['groups' => ['public']]));
 *
 *   #[UseInterceptors(ClassSerializerInterceptor::class)]
 *   #[SerializeOptions(groups: ['admin'])]
 *   final class UsersController { ... }
 *
 * Options precedence: handler #[SerializeOptions] > controller #[SerializeOptions] > constructor options.
 */
final class ClassSerializerInterceptor implements Interceptor
{
    private readonly Reflector $reflector;

    /** @param array{groups?: list<string>, excludeExtraneousValues?: bool, namingStrategy?: string} $options */
    public function __construct(private readonly array $options = [], ?Reflector $reflector = null)
    {
        $this->reflector = $reflector ?? new Reflector;
    }

    public function intercept(ExecutionContext $context, CallHandler $next): mixed
    {
        $result = $next->handle();
        if ($result instanceof ResponseInterface || $result === null || is_scalar($result)) {
            return $result;
        }

        return ClassTransformer::toPlain($result, $this->resolveOptions($context));
    }

    /** @return array<string, mixed> */
    private function resolveOptions(ExecutionContext $context): array
    {
        $options = $this->options;
        foreach ([$context->getClass(), $context->getHandler()] as $target) {
            $attr = $this->reflector->get(SerializeOptions::class, $target);
            if ($attr instanceof SerializeOptions) {
                $options = array_replace($options, $attr->toArray());
            }
        }

        return $options;
    }
}
