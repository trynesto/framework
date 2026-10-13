<?php

declare(strict_types=1);

namespace Nesto\Orm\Attributes;

use Attribute;

/**
 * Embedded columns (TypeORM's `@Column(() => Address, { prefix })`): the #[Column] properties of a plain class
 * (no #[Entity]) become columns of the entity's table, prefixed with the property name by default.
 *
 *   final class Address { #[Column] public string $city; #[Column(nullable: true)] public ?string $zip = null; }
 *
 *   #[Embedded(Address::class)] public Address $address;                     // address_city, address_zip
 *   #[Embedded(Address::class, prefix: 'home')] public Address $home;        // home_city, home_zip
 *   #[Embedded(Address::class, prefix: false)] public Address $location;     // city, zip
 *
 * Embedded classes may embed others (prefixes add up). Query them by path: where ['address' => ['city' => 'Oslo']],
 * order ['address' => ['city' => 'ASC']], select ['address'] or ['address' => ['city' => true]], 'u.address.city'
 * in query builder expressions. Hydration always creates the embedded object.
 */
#[Attribute(Attribute::TARGET_PROPERTY)]
final class Embedded
{
    /** @param class-string $class */
    public function __construct(public readonly string $class, public readonly string|bool $prefix = true) {}
}
