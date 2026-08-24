<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\BudgetCycle;
use App\Models\User;
use App\Services\ReportingService;
use App\Services\TelegramOutboxService;
use Carbon\CarbonImmutable;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Throwable;

#[Signature('finance:daily-recap')]
#[Description('Queue daily financial recap notifications')]
final class SendDailyRecaps extends Command
{
    public function __construct(
        private readonly ReportingService $reporting,
        private readonly TelegramOutboxService $outbox,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        User::query()->select(['id', 'timezone'])->orderBy('id')->chunkById(200, function ($users): void {
            foreach ($users as $user) {
                try {
                    $date = CarbonImmutable::now($user->timezone)->toDateString();
                    $eligible = BudgetCycle::query()->where('user_id', $user->id)->where('status', 'active')
                        ->whereDate('start_date', '<=', $date)->whereDate('end_date', '>=', $date)->exists();
                    if (! $eligible) {
                        continue;
                    }
                    $text = $this->reporting->handleDailyRecap($user->id, $date);
                    $this->outbox->enqueueMessage($user->id, "daily-recap:{$user->id}:{$date}", $text);
                } catch (Throwable $exception) {
                    Log::error('Daily recap could not be queued.', [
                        'user_id' => $user->id, 'exception_class' => $exception::class,
                        'exception' => $exception,
                    ]);
                }
            }
        });

        return self::SUCCESS;
    }
}
