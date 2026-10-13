<?php

declare(strict_types=1);

namespace Nesto\Validation;

/**
 * One failed property, same shape as class-validator's ValidationError:
 *
 *   property:    'email'
 *   value:       'not-an-email'
 *   constraints: ['isEmail' => 'email must be an email']
 *   children:    nested errors (for #[ValidateNested])
 */
final class ValidationError
{
    /**
     * @param array<string, string>  $constraints constraint name → message
     * @param list<ValidationError>  $children
     */
    public function __construct(
        public readonly string $property,
        public readonly mixed $value = null,
        public array $constraints = [],
        public array $children = [],
        public readonly ?object $target = null,
    ) {}

    /** @return list<string> flat, Nest-style messages ("address.city must be a string") */
    public function messages(string $prefix = ''): array
    {
        $path = $prefix === '' ? $this->property : $prefix . '.' . $this->property;
        $out = [];
        foreach ($this->constraints as $message) {
            $out[] = $prefix === '' ? $message : $prefix . '.' . $message;
        }
        foreach ($this->children as $child) {
            $out = [...$out, ...$child->messages($path)];
        }

        return $out;
    }

    /**
     * Messages grouped by property path ("address.city" => [...]), the `errors` of a ValidationException.
     *
     * @return array<string, list<string>>
     */
    public function toMap(string $prefix = ''): array
    {
        $path = $prefix === '' ? $this->property : $prefix . '.' . $this->property;
        $out = [];
        foreach ($this->constraints as $message) {
            $out[$path][] = $prefix === '' ? $message : $prefix . '.' . $message;
        }
        foreach ($this->children as $child) {
            foreach ($child->toMap($path) as $field => $messages) {
                $out[$field] = [...($out[$field] ?? []), ...$messages];
            }
        }

        return $out;
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        $out = ['property' => $this->property, 'value' => $this->value, 'constraints' => $this->constraints];
        if ($this->children !== []) {
            $out['children'] = array_map(static fn (self $c) => $c->toArray(), $this->children);
        }

        return $out;
    }
}
