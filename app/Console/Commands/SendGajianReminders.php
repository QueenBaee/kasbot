<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\BudgetCycle;
use App\Models\User;
use App\Services\TelegramOutboxService;
use Carbon\CarbonImmutable;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Throwable;

#[Signature('finance:gajian-reminder')]
#[Description('Queue salary reminders for users without an active cycle')]
final class SendGajianReminders extends Command
{
    public function __construct(private readonly TelegramOutboxService $outbox)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        User::query()->select(['id', 'timezone'])->orderBy('id')->chunkById(200, function ($users): void {
            foreach ($users as $user) {
                try {
                    $today = CarbonImmutable::now($user->timezone);
                    if ($today->day !== 25) {
                        continue;
                    }
                    $date = $today->toDateString();
                    $hasCycle = BudgetCycle::query()->where('user_id', $user->id)->where('status', 'active')
                        ->whereDate('start_date', '<=', $date)->whereDate('end_date', '>=', $date)->exists();
                    if ($hasCycle) {
                        continue;
                    }
                    $text = "💰 WAKTUNYA GAJIAN\n\nHari ini tanggal 25.\n\nKalau gaji sudah masuk, catat dengan:\n/gajian <nominal>";
                    $this->outbox->enqueueMessage($user->id, "gajian-reminder:{$user->id}:{$date}", $text);
                } catch (Throwable $exception) {
                    Log::error('Salary reminder could not be queued.', [
                        'user_id' => $user->id, 'exception_class' => $exception::class,
                        'exception' => $exception,
                    ]);
                }
            }
        });

        return self::SUCCESS;
    }
}
