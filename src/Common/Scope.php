<?php

declare(strict_types=1);

namespace Nesto\Common;

enum Scope
{
    /** One instance for the whole application (Nest DEFAULT). */
    case DEFAULT;
    /** New instance per incoming request. */
    case REQUEST;
    /** New instance every time it is injected. */
    case TRANSIENT;
}
