<?php

declare(strict_types=1);

namespace Nesto\Common\Attributes;

/**
 * Base of #[Param], #[Body], #[Query], #[Headers] ... Each carries an optional key and a list of pipes.
 */
abstract class ParamAttribute
{
    /** @var list<class-string|object> */
    public readonly array $pipes;

    /** @param class-string|object ...$pipes */
    public function __construct(public readonly ?string $key = null, string|object ...$pipes)
    {
        $this->pipes = array_values($pipes);
    }

    /** @return 'body'|'query'|'param'|'headers'|'file'|'files'|'custom' */
    abstract public function type(): string;
}
