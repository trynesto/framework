<?php

declare(strict_types=1);

namespace Nesto\Core;

/** A module exports something that is neither one of its providers nor a module it imports (Nest's UnknownExportException). */
final class UnknownExportException extends ContainerException {}
