<?php

declare(strict_types=1);

namespace Nesto\Tests\Swagger\Fixtures;

use Nesto\Swagger\Attributes\ApiHideProperty;
use Nesto\Swagger\Attributes\ApiProperty;
use Nesto\Swagger\Attributes\ApiPropertyOptional;

/** A user to create. */
final class CreateUserDto
{
    #[IsEmail]
    #[ApiProperty(description: 'Login email', example: 'a@b.c')]
    public string $email;

    #[MaxLength(50)]
    public string $name;

    #[Min(18)]
    public int $age;

    #[IsOptional]
    public string $nickname;

    public ?bool $newsletter = false;

    public Status $status = Status::Active;

    #[IsEnum(Status::class)]
    public string $previousStatus;

    public \DateTimeImmutable $birthday;

    public AddressDto $address;

    /** @var AddressDto[] */
    public array $otherAddresses = [];

    /** @var list<string> */
    public array $tags = [];

    #[ApiPropertyOptional(type: 'string', format: 'uri')]
    public string $website;

    #[ApiHideProperty]
    public string $internal;
}
