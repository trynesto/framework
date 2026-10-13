<?php

declare(strict_types=1);

namespace Nesto\Core;

use Psr\Container\ContainerExceptionInterface;
use Psr\Container\NotFoundExceptionInterface;

final class CircularDependencyException extends ContainerException {}
