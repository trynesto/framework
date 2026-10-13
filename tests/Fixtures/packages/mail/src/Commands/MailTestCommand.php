<?php

declare(strict_types=1);

namespace Acme\Mail\Commands;

use Acme\Mail\MailService;
use Nesto\Cli\Command;

/** `php nesto mail:test you@example.com` — registered automatically through extra.nesto.commands. */
final class MailTestCommand extends Command
{
    protected string $signature = 'mail:test {to : Recipient address}';
    protected string $description = 'Send a test e-mail through acme/mail';

    public function handle(): int
    {
        $mail = $this->get(MailService::class);
        $message = $mail->send((string) $this->argument('to'), 'Test e-mail', 'acme/mail is installed and configured.');
        $this->success("Sent to {$message['to']} from {$message['from']} using the \"{$mail->driver()}\" driver.");

        return self::SUCCESS;
    }
}
