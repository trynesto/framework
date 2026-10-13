<?php

declare(strict_types=1);

namespace Nesto\Core;

/** Thrown when a request-scoped provider (e.g. the current Request) is resolved outside of a request. */
final class OutsideRequestScopeException extends ContainerException {}
