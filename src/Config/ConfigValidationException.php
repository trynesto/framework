<?php

declare(strict_types=1);

namespace Nesto\Config;

/** Thrown by ConfigModule when the environment does not satisfy `validationSchema`. */
class ConfigValidationException extends \RuntimeException
{
    /** @param list<string> $errors */
    public function __construct(string $message, private readonly array $errors = [])
    {
        parent::__construct($message);
    }

    /** @return list<string> one human readable message per failing key */
    public function errors(): array
    {
        return $this->errors === [] ? [$this->getMessage()] : $this->errors;
    }
}
