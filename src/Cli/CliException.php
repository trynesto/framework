<?php

declare(strict_types=1);

namespace Nesto\Cli;

/** Raised for usage errors (unknown command, bad arguments). The Application prints the message and exits with 1. */
class CliException extends \RuntimeException {}
