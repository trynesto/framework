<?php

declare(strict_types=1);

namespace Nesto\Swagger\Attributes;

use Attribute;

/** Leave this handler out of the generated document. */
#[Attribute(Attribute::TARGET_METHOD)]
final class ApiExcludeEndpoint {}
