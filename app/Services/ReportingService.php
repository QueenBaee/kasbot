<?php

declare(strict_types=1);

namespace App\Services;

use App\Data\ExportResult;
use App\Exceptions\Financial\FinancialIntegrityException;
use App\Exceptions\Financial\UserFinancialException;
use App\Models\BudgetCycle;
use App\Models\BudgetPeriod;
use App\Models\Installment;
use App\Models\Transaction;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use InvalidArgumentException;
use RuntimeException;
use Throwable;

final class ReportingService
{
    private const CATEGORY_LIMIT = 10;

    private const PROJECTION_MONTH_LIMIT = 12;

    public function __construct(
        private readonly FinancialEngineService $financialEngine,
        private readonly RupiahFormatter $rupiah,
    ) {}

    public function getSmartGuideResponse(int $userId, Transaction $expense): string
    {
        if (
            (int) $expense->user_id !== $userId
            || $expense->type !== 'expense'
            || $expense->status !== 'success'
            || $expense->budget_period_id === null
        ) {
            throw new FinancialIntegrityException('The Smart Guide expense is invalid.');
        }

        $user = $this->findUser($userId);
        $period = BudgetPeriod::query()->find($expense->budget_period_id);

        if (! $period instanceof BudgetPeriod) {
            throw new FinancialIntegrityException('The Smart Guide period is missing.');
        }

        $period = $this->financialEngine->recalculatePeriodState($period);
        [$remainingDays, $today] = $this->remainingDays($user, $period);
        $category = $expense->category()->value('name') ?? 'Lainnya';

        $todayDate = now()
            ->setTimezone(config('app.timezone', 'Asia/Jakarta'))
            ->toDateString();

        $spentToday = (int) Transaction::query()
            ->where('user_id', $userId)
            ->where('budget_period_id', $period->getKey())
            ->where('type', 'expense')
        $dailyBudget = $remainingDays > 0
            ? intdiv((int) $period->remaining_amount + $spentToday, $remainingDays)
            : 0;
        $remainingToday = $dailyBudget - $spentToday;

        return "✅ {$expense->description} tercatat\n".
            "💸 Pengeluaran: {$this->rupiah->format($expense->amount)}\n".
            "🏷 Kategori: {$category}\n\n".
            "• Jatah hari ini: {$this->rupiah->format($dailyBudget)}\n".
            "• Sisa jatah hari ini: {$this->rupiah->format($remainingToday)}\n\n".
            "💰 Sisa jatah periode: {$this->rupiah->format($period->remaining_amount)}\n".
            "⏳ Sisa waktu: {$remainingDays} hari";
    }

    public function handleStatus(int $userId): string
    {
        [$user, $cycle, $period] = $this->currentContext($userId);
        $period = $this->financialEngine->recalculatePeriodState($period);
        $wallet = $this->financialEngine->syncWalletCache($userId);
        [$remainingDays] = $this->remainingDays($user, $period);
        $ledger = $this->successfulCycleLedger($cycle);

        $totalIncome = (int) (clone $ledger)->where('type', 'income')->sum('amount');
        $salaryIncome = (int) (clone $ledger)->where('type', 'income')->whereNull('source_wallet')
            ->where('target_wallet', 'uang_dingin')->whereNull('budget_period_id')->sum('amount');
        $additionalIncome = (int) (clone $ledger)->where('type', 'income')->where('source_wallet', 'external')
            ->where('target_wallet', 'uang_dingin')->sum('amount');
        $installments = (int) (clone $ledger)->where('type', 'expense')->whereNotNull('installment_id')
            ->whereNull('budget_period_id')->sum('amount');
        $dailySpending = (int) (clone $ledger)->where('type', 'expense')->whereNotNull('budget_period_id')->sum('amount');
        $activeSpending = (int) (clone $ledger)->where('type', 'expense')
            ->where('budget_period_id', $period->getKey())->sum('amount');
        $categories = $this->categoryTotals($cycle, true);

        $lines = [
            '📊 STATUS KEUANGAN', '',
            '📆 Siklus',
            $this->dateRange($cycle->start_date, $cycle->end_date),
            "Periode {$period->period_number} • {$this->dateRange($period->start_date, $period->end_date)}", '',
            '💵 Pemasukan',
            "Gaji: {$this->rupiah->format($salaryIncome)}",
            "Tambahan: {$this->rupiah->format($additionalIncome)}",
            "Total: {$this->rupiah->format($totalIncome)}", '',
            "💳 Cicilan: {$this->rupiah->format($installments)}",
            "🛒 Pengeluaran harian: {$this->rupiah->format($dailySpending)}",
            "Pengeluaran periode aktif: {$this->rupiah->format($activeSpending)}", '',
            "🎯 Jatah periode: {$this->rupiah->format($period->total_budget)}",
            "💰 Sisa jatah aktif: {$this->rupiah->format($period->remaining_amount)}",
            "🧊 Uang dingin: {$this->rupiah->format($wallet->uang_dingin)}", '',
        ];

        if ($period->remaining_amount < 0) {
            $lines[] = "⚠️ Jatah periode minus {$this->rupiah->format(abs($period->remaining_amount))}.";
            $lines[] = 'Uang dingin tidak dipotong otomatis.';
        } else {
            $lines[] = "📅 Sisa periode: {$remainingDays} hari";
            $lines[] = "💡 Batas aman: {$this->rupiah->format(intdiv($period->remaining_amount, $remainingDays))}/hari";
        }

        if ($categories->isNotEmpty()) {
            $lines[] = '';
            $lines[] = '🏷️ Pengeluaran siklus:';
            foreach ($categories as $category) {
                $lines[] = "{$category->category_name}: {$this->rupiah->format((int) $category->total)}";
            }
        }

        return implode("\n", $lines);
    }

    public function handleRecap(int $userId, string $type = 'bulan'): string
    {
        if ($type !== 'bulan') {
            throw new InvalidArgumentException('Only monthly cycle recap is supported.');
        }

        [, $cycle] = $this->currentContext($userId);
        $ledger = $this->successfulCycleLedger($cycle);
        $totalIncome = (int) (clone $ledger)->where('type', 'income')->sum('amount');
        $installments = (int) (clone $ledger)->where('type', 'expense')->whereNotNull('installment_id')
            ->whereNull('budget_period_id')->sum('amount');
        $dailyExpenses = (int) (clone $ledger)->where('type', 'expense')->whereNotNull('budget_period_id')->sum('amount');
        $totalExpense = $installments + $dailyExpenses;
        $categories = $this->categoryTotals($cycle, false);

        $lines = [
            '📈 REKAP KEUANGAN', '',
            "📆 {$this->dateRange($cycle->start_date, $cycle->end_date)}", '',
            "💵 Total pemasukan: {$this->rupiah->format($totalIncome)}",
            "💳 Cicilan: {$this->rupiah->format($installments)}",
            "🛒 Jajan/pengeluaran: {$this->rupiah->format($dailyExpenses)}",
            "📤 Total keluar: {$this->rupiah->format($totalExpense)}",
        ];

        if ($categories->isNotEmpty()) {
            $lines[] = '';
            $lines[] = '🏷️ Komposisi pengeluaran';
            foreach ($categories as $category) {
                $percentage = $totalExpense === 0 ? '0,0' : number_format(((int) $category->total / $totalExpense) * 100, 1, ',', '.');
                $lines[] = "{$category->category_name} {$this->rupiah->format((int) $category->total)} • {$percentage}%";
            }
        }

        return implode("\n", $lines);
    }

    public function handleProyeksi(int $userId): string
    {
        $user = $this->findUser($userId);
        $targetMonth = $this->nextUnprocessedSalaryMonth($user);
        $installments = Installment::query()->where('user_id', $userId)->where('active', true)
            ->where(function (Builder $query): void {
                $query->where(function (Builder $fixed): void {
                    $fixed->where('jenis', 'tetap')->where('sisa_tenor_bulan', '>', 0);
                })->orWhere(function (Builder $revolving): void {
                    $revolving->where('jenis', 'revolving')
                        ->where(fn (Builder $tenor) => $tenor->whereNull('sisa_tenor_bulan')->orWhere('sisa_tenor_bulan', '>', 0));
                });
            })
            ->orderBy('id')->get();

        if ($installments->isEmpty()) {
            return "🔮 PROYEKSI CICILAN\n\nTidak ada cicilan aktif.";
        }

        $lines = ['🔮 PROYEKSI CICILAN', '', 'Aktif:'];
        $monthlyKnown = [];
        $monthlyUnknown = [];

        foreach ($installments as $installment) {
            $tenor = $installment->sisa_tenor_bulan;
            $lines[] = "• {$installment->name}".($tenor === null
                ? ' — tenor tidak terbatas / belum ditentukan'
                : " — {$this->rupiah->format($installment->nominal_default)}/bulan — sisa {$tenor} bulan");

            if ($tenor === null) {
                $nextAmount = $installment->jadwal_khusus[$targetMonth->format('Y-m')] ?? null;
                $lines[] = '  Nominal bulan depan: '.(is_int($nextAmount) ? $this->rupiah->format($nextAmount) : 'belum diatur');
            }

            $months = $tenor === null ? self::PROJECTION_MONTH_LIMIT : min($tenor, self::PROJECTION_MONTH_LIMIT);
            for ($index = 0; $index < $months; $index++) {
                $month = $targetMonth->copy()->addMonths($index);
                $key = $month->format('Y-m');
                $schedule = $installment->jadwal_khusus;
                $scheduled = is_array($schedule) ? ($schedule[$key] ?? null) : null;

                if ($installment->jenis === 'tetap') {
                    $amount = is_int($scheduled) ? $scheduled : $installment->nominal_default;
                    $monthlyKnown[$key] = ($monthlyKnown[$key] ?? 0) + $amount;
                } elseif (is_int($scheduled)) {
                    $monthlyKnown[$key] = ($monthlyKnown[$key] ?? 0) + $scheduled;
                } else {
                    $monthlyUnknown[$key] = true;
                }
            }
        }

        $lines[] = '';
        $lines[] = '📉 Beban cicilan yang diketahui:';
        for ($index = 0; $index < self::PROJECTION_MONTH_LIMIT; $index++) {
            $month = $targetMonth->copy()->addMonths($index);
            $key = $month->format('Y-m');
            if (! array_key_exists($key, $monthlyKnown) && ! isset($monthlyUnknown[$key])) {
                continue;
            }
            $known = $this->rupiah->format($monthlyKnown[$key] ?? 0);
            $unknown = isset($monthlyUnknown[$key]) ? ' + tagihan belum diatur' : '';
            $cycleLabel = $index === 0
                ? 'Potongan ke-1 (Siklus saat ini)'
                : 'Potongan ke-'.($index + 1);
            $lines[] = "{$cycleLabel}: {$known}{$unknown}";
        }

        return implode("\n", $lines);
    }

    public function handleExport(int $userId): ExportResult
    {
        $user = $this->findUser($userId);
        $filename = 'kasbot-transactions-'.CarbonImmutable::now($user->timezone)->format('Ymd-His').'-'.Str::lower(Str::random(8)).'.csv';
        $relativePath = 'exports/'.$filename;
        Storage::disk('local')->makeDirectory('exports');
        $absolutePath = Storage::disk('local')->path($relativePath);
        $handle = fopen($absolutePath, 'wb');

        if ($handle === false) {
            throw new RuntimeException('Unable to create transaction export.');
        }

        try {
            fwrite($handle, "\xEF\xBB\xBF");
            fputcsv($handle, ['id', 'created_at', 'budget_cycle_id', 'budget_period_id', 'type', 'amount', 'description', 'category', 'source_wallet', 'target_wallet', 'installment_id', 'reference_transaction_id', 'status']);

            Transaction::query()->where('user_id', $userId)
                ->with('category:id,name')->orderBy('id')
                ->chunkById(500, function (Collection $transactions) use ($handle, $user): void {
                    foreach ($transactions as $transaction) {
                        fputcsv($handle, [
                            $transaction->id,
                            $transaction->created_at?->setTimezone($user->timezone)->format('Y-m-d H:i:s'),
                            $transaction->budget_cycle_id,
                            $transaction->budget_period_id,
                            $this->safeCsvText($transaction->type),
                            $transaction->amount,
                            $this->safeCsvText($transaction->description),
                            $this->safeCsvText($transaction->category?->name ?? ''),
                            $this->safeCsvText($transaction->source_wallet ?? ''),
                            $this->safeCsvText($transaction->target_wallet ?? ''),
                            $transaction->installment_id,
                            $transaction->reference_transaction_id,
                            $this->safeCsvText($transaction->status),
                        ]);
                    }
                });
        } finally {
            fclose($handle);
        }

        return new ExportResult($absolutePath, $filename, 'text/csv');
    }

    public function handleDailyRecap(int $userId, string $date): string
    {
        $user = $this->findUser($userId);
        try {
            $localDate = CarbonImmutable::createFromFormat('!Y-m-d', $date, $user->timezone);
        } catch (Throwable $exception) {
            throw new InvalidArgumentException('Daily recap date is invalid.', 0, $exception);
        }
        if ($localDate->toDateString() !== $date) {
            throw new InvalidArgumentException('Daily recap date is invalid.');
        }

        [$cycle, $period] = $this->contextForDate($user, $localDate);
        $period = $this->financialEngine->recalculatePeriodState($period);
        $this->financialEngine->syncWalletCache($userId);
        $startUtc = $localDate->startOfDay()->utc();
        $endUtc = $localDate->addDay()->startOfDay()->utc();

        $expenses = Transaction::query()
            ->leftJoin('categories', 'categories.id', '=', 'transactions.category_id')
            ->where('transactions.user_id', $userId)
            ->where('transactions.budget_cycle_id', $cycle->id)
            ->where('transactions.type', 'expense')
            ->where('transactions.status', 'success')
            ->whereNotNull('transactions.budget_period_id')
            ->where('transactions.created_at', '>=', $startUtc)
            ->where('transactions.created_at', '<', $endUtc)
            ->selectRaw("COALESCE(categories.name, 'Lainnya') AS category_name, SUM(transactions.amount) AS total")
            ->groupBy('category_name')->orderByDesc('total')->get();
        $total = (int) $expenses->sum(fn ($expense) => (int) $expense->total);

        $lines = ['🌙 DAILY RECAP', ''];
        if ($total === 0) {
            $lines[] = '👏 Hari ini belum ada pengeluaran.';
        } else {
            $lines[] = 'Hari ini:';
            foreach ($expenses as $expense) {
                $lines[] = "• {$expense->category_name}: {$this->rupiah->format((int) $expense->total)}";
            }
        }
        $lines[] = '';
        $lines[] = "💸 Total hari ini: {$this->rupiah->format($total)}";
        if ($period->remaining_amount < 0) {
            $lines[] = "⚠️ Jatah periode: {$this->rupiah->format($period->remaining_amount)}";
            $lines[] = '';
            $lines[] = 'Uang dingin tetap tidak dipotong otomatis.';
            $lines[] = 'Gunakan /ambil_dingin jika memang diperlukan.';
        } else {
            $lines[] = "🎯 Sisa jatah periode: {$this->rupiah->format($period->remaining_amount)}";
        }

        return implode("\n", $lines);
    }

    /** @return array{0: User, 1: BudgetCycle, 2: BudgetPeriod} */
    private function currentContext(int $userId): array
    {
        $user = $this->findUser($userId);
        $today = $this->localToday($user)->toDateString();
        $cycle = BudgetCycle::query()->where('user_id', $userId)->where('status', 'active')
            ->whereDate('start_date', '<=', $today)->whereDate('end_date', '>=', $today)->latest('id')->first();

        if (! $cycle instanceof BudgetCycle) {
            throw new UserFinancialException('No active budget cycle exists for today.');
        }

        $period = BudgetPeriod::query()->where('budget_cycle_id', $cycle->id)
            ->where('status', 'active')
            ->whereDate('start_date', '<=', $today)->whereDate('end_date', '>=', $today)->orderBy('period_number')->first();

        if (! $period instanceof BudgetPeriod) {
            throw new UserFinancialException('No active budget period exists for today.');
        }

        return [$user, $cycle, $period];
    }

    private function successfulCycleLedger(BudgetCycle $cycle): Builder
    {
        return Transaction::query()->where('budget_cycle_id', $cycle->id)
            ->where('status', 'success')->where('type', '!=', 'reversal');
    }

    /** @return array{0: BudgetCycle, 1: BudgetPeriod} */
    private function contextForDate(User $user, CarbonImmutable $date): array
    {
        $value = $date->toDateString();
        $cycle = BudgetCycle::query()->where('user_id', $user->id)->where('status', 'active')
            ->whereDate('start_date', '<=', $value)->whereDate('end_date', '>=', $value)->latest('id')->first();
        if (! $cycle instanceof BudgetCycle) {
            throw new UserFinancialException('No active budget cycle exists for today.');
        }
        $period = BudgetPeriod::query()->where('budget_cycle_id', $cycle->id)->where('status', 'active')
            ->whereDate('start_date', '<=', $value)->whereDate('end_date', '>=', $value)->orderBy('period_number')->first();
        if (! $period instanceof BudgetPeriod) {
            throw new UserFinancialException('No active budget period exists for today.');
        }

        return [$cycle, $period];
    }

    private function categoryTotals(BudgetCycle $cycle, bool $dailyOnly): Collection
    {
        return Transaction::query()->leftJoin('categories', 'categories.id', '=', 'transactions.category_id')
            ->where('transactions.budget_cycle_id', $cycle->id)
            ->where('transactions.status', 'success')->where('transactions.type', 'expense')
            ->when($dailyOnly, fn (Builder $query) => $query->whereNotNull('transactions.budget_period_id'))
            ->selectRaw("COALESCE(categories.name, 'Lainnya') AS category_name, SUM(transactions.amount) AS total")
            ->groupBy('category_name')->orderByDesc('total')
            ->when($dailyOnly, fn (Builder $query) => $query->limit(self::CATEGORY_LIMIT))
            ->get();
    }

    /** @return array{0: int, 1: CarbonImmutable} */
    private function remainingDays(User $user, BudgetPeriod $period): array
    {
        $today = $this->localToday($user);
        $start = CarbonImmutable::parse($period->start_date->toDateString(), $user->timezone)->startOfDay();
        $end = CarbonImmutable::parse($period->end_date->toDateString(), $user->timezone)->startOfDay();

        if ($today->lt($start) || $today->gt($end)) {
            throw new FinancialIntegrityException('Today falls outside the active budget period.');
        }

        return [(int) $today->diffInDays($end) + 1, $today];
    }

    private function nextUnprocessedSalaryMonth(User $user): CarbonImmutable
    {
        $today = $this->localToday($user);
        $currentPayDate = $today->day(25);
        $processed = BudgetCycle::query()->where('user_id', $user->id)
            ->whereDate('start_date', $currentPayDate->toDateString())->where('status', '!=', 'cancelled')->exists();

        return ($today->day > 25 || $processed ? $today->addMonth() : $today)->startOfMonth();
    }

    private function findUser(int $userId): User
    {
        $user = User::query()->find($userId);
        if (! $user instanceof User) {
            throw new FinancialIntegrityException('User not found.');
        }

        return $user;
    }

    private function localToday(User $user): CarbonImmutable
    {
        try {
            return CarbonImmutable::now($user->timezone)->startOfDay();
        } catch (Throwable $exception) {
            throw new FinancialIntegrityException('The user timezone is invalid.', 0, $exception);
        }
    }

    private function dateRange(mixed $start, mixed $end): string
    {
        return $this->dateLabel(CarbonImmutable::parse((string) $start)).' - '.$this->dateLabel(CarbonImmutable::parse((string) $end));
    }

    private function dateLabel(CarbonImmutable $date): string
    {
        return $date->day.' '.$this->monthName($date->month).' '.$date->year;
    }

    private function monthLabel(CarbonImmutable $date): string
    {
        return $this->monthName($date->month).' '.$date->year;
    }

    private function monthName(int $month): string
    {
        return [1 => 'Jan', 2 => 'Feb', 3 => 'Mar', 4 => 'Apr', 5 => 'Mei', 6 => 'Jun', 7 => 'Jul', 8 => 'Agu', 9 => 'Sep', 10 => 'Okt', 11 => 'Nov', 12 => 'Des'][$month];
    }

    private function safeCsvText(string $value): string
    {
        return preg_match('/^[\s]*[=+\-@]/u', $value) === 1 ? "'{$value}" : $value;
    }
}
