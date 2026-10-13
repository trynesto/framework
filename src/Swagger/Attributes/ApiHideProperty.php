<?php

declare(strict_types=1);

namespace Nesto\Swagger\Attributes;

use Attribute;

/** Leave this DTO property (or #[Expose]d method) out of the generated schema. #[Exclude]d properties are left out already. */
#[Attribute(Attribute::TARGET_PROPERTY | Attribute::TARGET_METHOD)]
final class ApiHideProperty {}
