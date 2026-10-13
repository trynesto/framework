<?php

declare(strict_types=1);

namespace Acme\Mail;

use Nesto\Common\Attributes\Injectable;
use Nesto\Config\ConfigService;
use Nesto\Core\Packages\PackageManifest;

#[Injectable]
final class MailService
{
    /** @var list<array{to: string, from: string, subject: string, body: string}> messages kept by the "array" driver */
    private array $sent = [];

    public function __construct(private readonly ConfigService $config) {}

    /** @return array{to: string, from: string, subject: string, body: string} */
    public function send(string $to, string $subject, string $body): array
    {
        $from = sprintf('%s <%s>', $this->config->get('mail.from.name'), $this->config->get('mail.from.address'));
        $message = ['to' => $to, 'from' => $from, 'subject' => $subject, 'body' => $body];

        match ($this->driver()) {
            'array' => $this->sent[] = $message,
            'log' => $this->log($message),
        };

        return $message;
    }

    public function driver(): string
    {
        return (string) $this->config->get('mail.driver', 'log');
    }

    /** @return list<array{to: string, from: string, subject: string, body: string}> */
    public function sent(): array
    {
        return $this->sent;
    }

    /** @param array{to: string, from: string, subject: string, body: string} $message */
    private function log(array $message): void
    {
        $path = (string) $this->config->get('mail.log_path', 'storage/logs/mail.log');
        if (! str_starts_with($path, '/')) {
            $path = dirname((string) PackageManifest::vendorDir()) . '/' . $path;
        }
        if (! is_dir(dirname($path))) {
            mkdir(dirname($path), 0777, true);
        }
        $line = sprintf("[%s] To: %s | From: %s | Subject: %s\n%s\n\n", date('c'), $message['to'], $message['from'], $message['subject'], $message['body']);
        file_put_contents($path, $line, FILE_APPEND | LOCK_EX);
    }
}
