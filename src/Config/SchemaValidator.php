<?php

declare(strict_types=1);

namespace Nesto\Config;

/**
 * Tiny rule-string validator for environment variables (ConfigModule's `validationSchema`).
 *
 *   $validated = SchemaValidator::validate([
 *       'PORT'     => 'required|int|min:1|max:65535',
 *       'APP_ENV'  => 'in:dev,prod|default:dev',
 *       'DEBUG'    => 'bool|default:false',
 *       'API_URL'  => 'url',
 *       'MAIL'     => 'email',
 *   ], $_ENV);
 *   // → ['PORT' => 3000, 'APP_ENV' => 'dev', 'DEBUG' => false, ...] with values cast to the rule's type
 *
 * Rules: required, int, number, bool, string, in:a,b,c, url, email, min:n, max:n, default:x
 * (min/max compare the numeric value for int/number rules and the string length otherwise).
 * Rules may also be given as an array: ['required', 'int'].
 *
 * @throws ConfigValidationException listing every failing key
 */
final class SchemaValidator
{
    /**
     * @param array<string, string|list<string>> $schema
     * @param array<string, mixed>               $values
     * @return array<string, mixed> the validated keys with cast values (unknown keys are left out)
     */
    public static function validate(array $schema, array $values): array
    {
        $out = [];
        $errors = [];

        foreach ($schema as $key => $rules) {
            $rules = is_array($rules) ? $rules : array_filter(explode('|', $rules), fn ($r) => $r !== '');
            $parsed = [];
            foreach ($rules as $rule) {
                [$name, $arg] = array_pad(explode(':', $rule, 2), 2, null);
                $parsed[strtolower(trim($name))] = $arg;
            }

            $value = array_key_exists($key, $values) ? $values[$key] : null;
            if ($value === '') {
                $value = null;
            }
            if ($value === null && array_key_exists('default', $parsed)) {
                $value = $parsed['default'];
            }
            if ($value === null) {
                if (array_key_exists('required', $parsed)) {
                    $errors[] = "\"$key\" is required";
                }
                continue;
            }

            $type = 'string';
            foreach (['int', 'number', 'bool', 'string'] as $t) {
                if (array_key_exists($t, $parsed)) {
                    $type = $t;
                }
            }

            try {
                $value = self::cast($key, $value, $type);
                foreach ($parsed as $name => $arg) {
                    self::check($key, $value, $type, $name, $arg);
                }
                $out[$key] = $value;
            } catch (ConfigValidationException $e) {
                $errors[] = $e->getMessage();
            }
        }

        if ($errors !== []) {
            throw new ConfigValidationException('Config validation error: ' . implode('; ', $errors), $errors);
        }

        return $out;
    }

    private static function cast(string $key, mixed $value, string $type): mixed
    {
        switch ($type) {
            case 'int':
                if (is_int($value) || (is_string($value) && preg_match('/^-?\d+$/', trim($value)))) {
                    return (int) $value;
                }
                throw new ConfigValidationException("\"$key\" must be an integer");
            case 'number':
                if (is_numeric($value)) {
                    return $value + 0;
                }
                throw new ConfigValidationException("\"$key\" must be a number");
            case 'bool':
                if (is_bool($value)) {
                    return $value;
                }
                $b = filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
                if ($b === null) {
                    throw new ConfigValidationException("\"$key\" must be a boolean");
                }

                return $b;
            default:
                if (is_scalar($value)) {
                    return (string) $value;
                }
                throw new ConfigValidationException("\"$key\" must be a string");
        }
    }

    private static function check(string $key, mixed $value, string $type, string $rule, ?string $arg): void
    {
        switch ($rule) {
            case 'in':
                $allowed = array_map('trim', explode(',', (string) $arg));
                if (! in_array((string) (is_bool($value) ? var_export($value, true) : $value), $allowed, true)) {
                    throw new ConfigValidationException("\"$key\" must be one of [" . implode(', ', $allowed) . ']');
                }
                break;
            case 'url':
                if (filter_var($value, FILTER_VALIDATE_URL) === false) {
                    throw new ConfigValidationException("\"$key\" must be a valid URL");
                }
                break;
            case 'email':
                if (filter_var($value, FILTER_VALIDATE_EMAIL) === false) {
                    throw new ConfigValidationException("\"$key\" must be a valid email address");
                }
                break;
            case 'min':
                $n = (float) $arg;
                if (in_array($type, ['int', 'number'], true) ? $value < $n : mb_strlen((string) $value) < $n) {
                    throw new ConfigValidationException(in_array($type, ['int', 'number'], true)
                        ? "\"$key\" must be at least $arg"
                        : "\"$key\" must be at least $arg characters");
                }
                break;
            case 'max':
                $n = (float) $arg;
                if (in_array($type, ['int', 'number'], true) ? $value > $n : mb_strlen((string) $value) > $n) {
                    throw new ConfigValidationException(in_array($type, ['int', 'number'], true)
                        ? "\"$key\" must be at most $arg"
                        : "\"$key\" must be at most $arg characters");
                }
                break;
            case 'required':
            case 'int':
            case 'number':
            case 'bool':
            case 'string':
            case 'default':
            case 'optional':
            case 'nullable':
                break;
            default:
                throw new ConfigValidationException("Unknown validation rule \"$rule\" for \"$key\"");
        }
    }
}
