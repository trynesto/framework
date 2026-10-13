<?php

declare(strict_types=1);

namespace Nesto\Common\Exceptions;

/**
 * Every validation failure (DTO rules, uploaded files, route params, query strings, Parse*Pipes) has one shape,
 * with the messages grouped per field:
 *
 *   {
 *     "statusCode": 400,
 *     "message": "name must be a string (and 1 more error)",
 *     "errors": { "name": ["name must be a string"], "image": ["image must be an image (jpg, png, webp)"] },
 *     "error": "Bad Request"
 *   }
 *
 * The status is 400 like Nest; `new ValidationPipe(errorHttpStatusCode: 422)` switches the whole app to 422.
 */
final class ValidationException extends HttpException
{
    /** Status of every validation failure (set app-wide by ValidationPipe's errorHttpStatusCode). */
    public static int $defaultStatus = 400;

    /** @var array<string, list<string>> field => messages */
    public readonly array $errors;

    /** @var list<string> every message, flat */
    public readonly array $messages;

    /** @param array<string, string|list<string>> $errors field => message(s) */
    public function __construct(array $errors, ?int $status = null)
    {
        $grouped = [];
        foreach ($errors as $field => $messages) {
            foreach ((array) $messages as $message) {
                $grouped[(string) $field][] = (string) $message;
            }
        }
        $this->errors = $grouped;
        $this->messages = $grouped === [] ? [] : array_merge(...array_values($grouped));

        $status ??= self::$defaultStatus;
        $more = count($this->messages) - 1;
        $summary = ($this->messages[0] ?? 'The given data was invalid')
            . ($more > 0 ? sprintf(' (and %d more error%s)', $more, $more === 1 ? '' : 's') : '');

        parent::__construct([
            'statusCode' => $status,
            'message' => $summary,
            'errors' => $grouped === [] ? new \stdClass : $grouped,
            'error' => self::reasonPhrase($status),
        ], $status);
    }

    /** One field, one or more messages: `throw ValidationException::field('email', 'email is already taken')`. */
    public static function field(string $field, string|array $messages, ?int $status = null): self
    {
        return new self([$field => $messages], $status);
    }
}
