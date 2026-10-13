<?php

declare(strict_types=1);

namespace Nesto\Swagger\Attributes;

use Attribute;

/** Leave every route of this controller out of the generated document. */
#[Attribute(Attribute::TARGET_CLASS)]
final class ApiExcludeController {}
