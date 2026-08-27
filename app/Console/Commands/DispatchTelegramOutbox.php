<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\TelegramOutboxService;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('telegram:dispatch-outbox')]
#[Description('Dispatch pending Telegram outbox deliveries')]
final class DispatchTelegramOutbox extends Command
{
    public function __construct(private readonly TelegramOutboxService $outbox)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        $this->outbox->dispatchPending();

        return self::SUCCESS;
    }
}
