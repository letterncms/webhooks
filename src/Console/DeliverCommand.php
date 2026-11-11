<?php

declare(strict_types=1);

namespace Cms\Webhooks\Console;

use Illuminate\Console\Command;
use Cms\Webhooks\Dispatcher;

/** For a cron job on a quiet site: retries wait for a request otherwise. */
final class DeliverCommand extends Command
{
    protected $signature = 'webhooks:deliver';

    protected $description = 'Send the webhook deliveries that are due';

    public function handle(Dispatcher $dispatcher): int
    {
        $sent = $dispatcher->process(50_000, 200);
        $this->components->info($sent === 0 ? 'Nothing is due.' : "Attempted {$sent}.");

        return self::SUCCESS;
    }
}
