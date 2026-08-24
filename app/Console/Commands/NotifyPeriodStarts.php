<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\BudgetPeriod;
use App\Models\User;
use App\Services\FinancialEngineService;
use App\Services\RupiahFormatter;
use App\Services\TelegramOutboxService;
use Carbon\CarbonImmutable;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Throwable;

#[Signature('finance:notify-period-starts')]
#[Description('Queue notifications for newly started budget periods')]
final class NotifyPeriodStarts extends Command
{
    public function __construct(
        private readonly FinancialEngineService $financialEngine,
        private readonly TelegramOutboxService $outbox,
        private readonly RupiahFormatter $rupiah,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        User::query()->select(['id', 'timezone'])->orderBy('id')->chunkById(200, function ($users): void {
            foreach ($users as $user) {
                try {
                    $today = CarbonImmutable::now($user->timezone)->toDateString();
                    $period = BudgetPeriod::query()->whereDate('start_date', $today)
                        ->where('period_number', '>', 1)
                        ->whereHas('budgetCycle', fn ($query) => $query->where('user_id', $user->id)->where('status', 'active'))
                        ->orderBy('id')->first();
                    if (! $period instanceof BudgetPeriod) {
                        continue;
                    }

                    $period = $this->financialEngine->recalculatePeriodState($period);
                    $wallet = $this->financialEngine->syncWalletCache($user->id);
                    $carry = $period->carry_over_amount < 0
                        ? "⚠️ Carry-over minggu sebelumnya: {$this->rupiah->format($period->carry_over_amount)}"
                        : "➕ Carry-over: {$this->rupiah->format($period->carry_over_amount)}";
                    $text = "🔔 PERIODE BARU\n\nJatah minggu ke-{$period->period_number} sudah aktif!\n\n".
                        "🎯 Jatah periode: {$this->rupiah->format($period->allocated_amount)}\n{$carry}\n".
                        "💰 Jatah aktif: {$this->rupiah->format($period->remaining_amount)}\n".
                        "🧊 Uang dingin: {$this->rupiah->format($wallet->uang_dingin)}\n\n".
                        "Periode:\n{$period->start_date->format('j M')} - {$period->end_date->format('j M')}";
                    $this->outbox->enqueueMessage($user->id, "period-start:{$user->id}:{$period->id}", $text);
                } catch (Throwable $exception) {
                    Log::error('Period-start notification could not be queued.', [
                        'user_id' => $user->id, 'exception_class' => $exception::class,
                        'exception' => $exception,
                    ]);
                }
            }
        });

        return self::SUCCESS;
    }
}
