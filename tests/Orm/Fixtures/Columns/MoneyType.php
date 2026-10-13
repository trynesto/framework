<?php

declare(strict_types=1);

namespace Nesto\Tests\Orm\Fixtures\Columns;

use Nesto\Orm\Schema\Column as SchemaColumn;
use Nesto\Orm\Types\ColumnType;

final class MoneyType extends ColumnType
{
    public function sqlDeclaration(SchemaColumn $column, string $platform): string
    {
        return 'DECIMAL(19, 4)';
    }

    public function convertToDatabaseValue(mixed $value): mixed
    {
        return $value instanceof Money ? $value->amount : (string) $value;
    }

    public function convertToPHPValue(mixed $value): mixed
    {
        return new Money(number_format((float) $value, 2, '.', ''));
    }

    public function implicitDefault(string $platform): ?string
    {
        return '0';
    }
}
