<?php

declare(strict_types=1);

namespace Acme\Mail;

use Nesto\Common\Attributes\GlobalModule;
use Nesto\Common\Attributes\Module;

/** Import once in the root module (`php nesto add acme/mail` does it); MailService is then injectable anywhere. */
#[GlobalModule]
#[Module(providers: [MailService::class], exports: [MailService::class])]
final class MailModule {}
