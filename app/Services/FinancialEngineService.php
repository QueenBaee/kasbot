<?php

declare(strict_types=1);

namespace App\Services;

use App\Exceptions\Financial\FinancialIntegrityException;
use App\Exceptions\Financial\UserFinancialException;
use App\Models\BudgetCycle;
use App\Models\BudgetPeriod;
use App\Models\Category;
use App\Models\CategoryKeyword;
use App\Models\Installment;
use App\Models\Transaction;
use App\Models\User;
use App\Models\Wallet;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Throwable;

final class FinancialEngineService
{
    public function processGajian(
        int $userId,
        int $grossIncome,
        string $payDate
    ): BudgetCycle {
        if ($grossIncome <= 0) {
            throw new UserFinancialException('Gross income must be greater than zero.');
        }

        return DB::transaction(function () use ($userId, $grossIncome, $payDate): BudgetCycle {
            $user = $this->lockUser($userId);
            $cycleStart = $this->parsePayDate($payDate, $user->timezone);
            $today = $this->todayFor($user);

            if ($cycleStart->toDateString() !== $today) {
                throw new UserFinancialException('Pay date must match the user\'s current local date.');
            }

            if ($cycleStart->day !== 25) {
                throw new UserFinancialException('Pay date must be the 25th day of the month.');
            }

            $nextMonth = $cycleStart->addMonth()->startOfMonth();
            $cycleEnd = $nextMonth->day(24);

            $overlappingCycleExists = BudgetCycle::query()
                ->where('user_id', $user->getKey())
                ->where('status', '!=', 'cancelled')
                ->whereDate('start_date', '<=', $cycleEnd->toDateString())
                ->whereDate('end_date', '>=', $cycleStart->toDateString())
                ->lockForUpdate()
                ->exists();

            if ($overlappingCycleExists) {
                throw new UserFinancialException('A budget cycle already overlaps the requested pay cycle.');
            }

            $installments = Installment::query()
                ->where('user_id', $user->getKey())
                ->where('active', true)
                ->where(function ($query): void {
                    $query
                        ->whereNull('sisa_tenor_bulan')
                        ->orWhere('sisa_tenor_bulan', '>', 0);
                })
                ->orderBy('id')
                ->lockForUpdate()
                ->get();

            $billingMonth = $cycleStart->format('Y-m');
            $installmentAmounts = [];
            $totalInstallments = 0;

            foreach ($installments as $installment) {
                $installmentAmount = $this->resolveInstallmentAmount($installment, $billingMonth);

                if ($installmentAmount > PHP_INT_MAX - $totalInstallments) {
                    throw new UserFinancialException('Total installments exceed the supported integer range.');
                }

                $installmentAmounts[$installment->getKey()] = $installmentAmount;
                $totalInstallments += $installmentAmount;
            }

            $netIncome = $grossIncome - $totalInstallments;

            if ($netIncome < 0) {
                throw new UserFinancialException('Total installments exceed gross income.');
            }

            $cycle = BudgetCycle::query()->create([
                'user_id' => $user->getKey(),
                'start_date' => $cycleStart->toDateString(),
                'end_date' => $cycleEnd->toDateString(),
                'gross_income' => $grossIncome,
                'total_installments' => $totalInstallments,
                'net_income' => $netIncome,
                'status' => 'active',
            ]);

            Transaction::query()->create([
                'user_id' => $user->getKey(),
                'budget_cycle_id' => $cycle->getKey(),
                'budget_period_id' => null,
                'type' => 'income',
                'amount' => $grossIncome,
                'description' => 'Gaji '.$cycleStart->format('F Y'),
                'category_id' => null,
                'installment_id' => null,
                'source_wallet' => null,
                'target_wallet' => 'uang_dingin',
                'reference_transaction_id' => null,
                'status' => 'success',
            ]);

            // Category firstOrCreate calls are fully concurrency-safe only with a UNIQUE constraint on categories.name.
            $installmentCategory = $installments->isEmpty()
                ? null
                : Category::query()->firstOrCreate(['name' => 'Cicilan']);

            foreach ($installments as $installment) {
                Transaction::query()->create([
                    'user_id' => $user->getKey(),
                    'budget_cycle_id' => $cycle->getKey(),
                    'budget_period_id' => null,
                    'type' => 'expense',
                    'amount' => $installmentAmounts[$installment->getKey()],
                    'description' => 'Cicilan: '.$installment->name,
                    'category_id' => $installmentCategory?->getKey(),
                    'installment_id' => $installment->getKey(),
                    'source_wallet' => 'uang_dingin',
                    'target_wallet' => null,
                    'reference_transaction_id' => null,
                    'status' => 'success',
                ]);

                if ($installment->sisa_tenor_bulan !== null) {
                    $remainingTenor = $installment->sisa_tenor_bulan - 1;

                    $installment->forceFill([
                        'sisa_tenor_bulan' => $remainingTenor,
                        'active' => $remainingTenor > 0,
                    ])->save();
                }
            }

            $baseAllocation = intdiv($netIncome, 4);
            $allocations = [
                1 => $baseAllocation,
                2 => $baseAllocation,
                3 => $baseAllocation,
                4 => $netIncome - ($baseAllocation * 3),
            ];
            $periodBoundaries = [
                1 => [$cycleStart, $nextMonth->day(1)],
                2 => [$nextMonth->day(2), $nextMonth->day(8)],
                3 => [$nextMonth->day(9), $nextMonth->day(15)],
                4 => [$nextMonth->day(16), $nextMonth->day(24)],
            ];

            $periodOne = null;

            foreach ($allocations as $periodNumber => $allocatedAmount) {
                [$periodStart, $periodEnd] = $periodBoundaries[$periodNumber];

                $period = BudgetPeriod::query()->create([
                    'budget_cycle_id' => $cycle->getKey(),
                    'period_number' => $periodNumber,
                    'start_date' => $periodStart->toDateString(),
                    'end_date' => $periodEnd->toDateString(),
                    'allocated_amount' => $allocatedAmount,
                    'carry_over_amount' => 0,
                    'total_budget' => $allocatedAmount,
                    'spent_amount' => 0,
                    'remaining_amount' => $allocatedAmount,
                    'status' => 'active',
                ]);

                if ($periodNumber === 1) {
                    $periodOne = $period;
                }
            }

            if (! $periodOne instanceof BudgetPeriod) {
                throw new FinancialIntegrityException('The first budget period could not be created.');
            }

            $this->recalculatePeriodStateLocked($periodOne);
            $this->syncWalletCacheLocked($user, $cycle, $periodOne, $today);

            return $cycle->refresh();
        }, 5);
    }

    public function recordExpense(
        int $userId,
        int $amount,
        string $description,
        ?string $keyword = null
    ): Transaction {
        $normalizedDescription = Str::of($description)->trim()->value();

        if ($amount <= 0) {
            throw new InvalidArgumentException('Expense amount must be greater than zero.');
        }

        if ($normalizedDescription === '') {
            throw new InvalidArgumentException('Expense description must not be empty.');
        }

        return DB::transaction(function () use (
            $userId,
            $amount,
            $normalizedDescription,
            $keyword
        ): Transaction {
            $user = $this->lockUser($userId);
            $today = $this->todayFor($user);
            $cycle = $this->lockCurrentCycle($user, $today);
            $period = $this->lockCurrentPeriod($cycle, $today);
            $category = $this->resolveExpenseCategory($keyword);

            $transaction = Transaction::query()->create([
                'user_id' => $user->getKey(),
                'budget_cycle_id' => $cycle->getKey(),
                'budget_period_id' => $period->getKey(),
                'type' => 'expense',
                'amount' => $amount,
                'description' => $normalizedDescription,
                'category_id' => $category->getKey(),
                'installment_id' => null,
                'source_wallet' => 'dompet_jajan_aktif',
                'target_wallet' => null,
                'reference_transaction_id' => null,
                'status' => 'success',
            ]);

            $this->recalculatePeriodStateLocked($period);
            $this->syncWalletCacheLocked($user);

            return $transaction->refresh();
        }, 5);
    }

    public function recordIncome(
        int $userId,
        int $amount,
        string $description,
        ?string $keyword = null
    ): Transaction {
        $normalizedDescription = Str::of($description)->trim()->value();

        if ($amount <= 0) {
            throw new InvalidArgumentException('Income amount must be greater than zero.');
        }

        if ($normalizedDescription === '') {
            throw new InvalidArgumentException('Income description must not be empty.');
        }

        return DB::transaction(function () use ($userId, $amount, $normalizedDescription): Transaction {
            $user = $this->lockUser($userId);
            $today = $this->todayFor($user);
            $cycle = $this->lockCurrentCycle($user, $today);

            $transaction = Transaction::query()->create([
                'user_id' => $user->getKey(),
                'budget_cycle_id' => $cycle->getKey(),
                'budget_period_id' => null,
                'type' => 'income',
                'amount' => $amount,
                'description' => $normalizedDescription,
                'category_id' => null,
                'installment_id' => null,
                'source_wallet' => 'external',
                'target_wallet' => 'uang_dingin',
                'reference_transaction_id' => null,
                'status' => 'success',
            ]);

            $this->syncWalletCacheLocked($user, $cycle, null, $today);

            return $transaction->refresh();
        }, 5);
    }

    public function upsertFixedInstallment(
        int $userId,
        string $name,
        int $amount,
        int $remainingTenor
    ): Installment {
        $normalizedName = Str::of($name)->trim()->squish()->value();

        if ($normalizedName === '') {
            throw new InvalidArgumentException('Installment name must not be empty.');
        }

        if ($amount <= 0 || $remainingTenor <= 0) {
            throw new InvalidArgumentException('Installment amount and tenor must be greater than zero.');
        }

        return DB::transaction(function () use ($userId, $normalizedName, $amount, $remainingTenor): Installment {
            $user = $this->lockUser($userId);

            $installment = Installment::query()
                ->where('user_id', $user->getKey())
                ->where('name', $normalizedName)
                ->lockForUpdate()
                ->first();

            if (! $installment instanceof Installment) {
                return Installment::query()->create([
                    'user_id' => $user->getKey(),
                    'name' => $normalizedName,
                    'jenis' => 'tetap',
                    'nominal_default' => $amount,
                    'sisa_tenor_bulan' => $remainingTenor,
                    'active' => true,
                ]);
            }

            if ($installment->jenis !== 'tetap') {
                throw new UserFinancialException('An installment with this name is not fixed.');
            }

            $installment->forceFill([
                'nominal_default' => $amount,
                'sisa_tenor_bulan' => $remainingTenor,
                'active' => true,
            ])->save();

            return $installment->refresh();
        }, 5);
    }

    public function deactivateInstallment(int $userId, string $name): ?Installment
    {
        $normalizedName = Str::of($name)->trim()->squish()->value();

        if ($normalizedName === '') {
            throw new InvalidArgumentException('Installment name must not be empty.');
        }

        return DB::transaction(function () use ($userId, $normalizedName): ?Installment {
            $user = $this->lockUser($userId);
            $installment = Installment::query()
                ->where('user_id', $user->getKey())
                ->where('active', true)
                ->whereRaw('LOWER(name) = ?', [Str::lower($normalizedName)])
                ->lockForUpdate()
                ->first();

            if (! $installment instanceof Installment) {
                return null;
            }

            $installment->forceFill(['active' => false])->save();

            return $installment->refresh();
        }, 5);
    }

    public function transferAmbilDingin(
        int $userId,
        int $amount
    ): Transaction {
        if ($amount <= 0) {
            throw new InvalidArgumentException('Transfer amount must be greater than zero.');
        }

        return DB::transaction(function () use ($userId, $amount): Transaction {
            $user = $this->lockUser($userId);
            $today = $this->todayFor($user);
            $cycle = $this->lockCurrentCycle($user, $today);
            $period = $this->lockCurrentPeriod($cycle, $today);
            $wallet = $this->syncWalletCacheLocked($user, $cycle, $period, $today);

            if ($amount > $wallet->uang_dingin) {
                throw new UserFinancialException('Insufficient uang_dingin balance.');
            }

            $transaction = Transaction::query()->create([
                'user_id' => $user->getKey(),
                'budget_cycle_id' => $cycle->getKey(),
                'budget_period_id' => $period->getKey(),
                'type' => 'transfer',
                'amount' => $amount,
                'description' => 'Ambil uang dingin',
                'category_id' => null,
                'installment_id' => null,
                'source_wallet' => 'uang_dingin',
                'target_wallet' => 'dompet_jajan_aktif',
                'reference_transaction_id' => null,
                'status' => 'success',
            ]);

            $this->recalculatePeriodStateLocked($period);
            $this->syncWalletCacheLocked($user, $cycle, $period, $today);

            return $transaction->refresh();
        }, 5);
    }

    public function undoLastTransaction(int $userId): Transaction
    {
        return DB::transaction(function () use ($userId): Transaction {
            $user = $this->lockUser($userId);

            $original = Transaction::query()
                ->where('user_id', $user->getKey())
                ->where('status', 'success')
                ->where(function ($query): void {
                    $query
                        ->where(function ($expenseQuery): void {
                            $expenseQuery
                                ->where('type', 'expense')
                                ->whereNotNull('budget_period_id')
                                ->where('source_wallet', 'dompet_jajan_aktif');
                        })
                        ->orWhere(function ($incomeQuery): void {
                            $incomeQuery
                                ->where('type', 'income')
                                ->where('source_wallet', 'external')
                                ->where('target_wallet', 'uang_dingin');
                        });
                })
                ->latest('id')
                ->lockForUpdate()
                ->first();

            if (! $original instanceof Transaction) {
                throw new UserFinancialException('No undoable transaction exists.');
            }

            $reversal = $this->reverseTransactionLocked($original);

            if ($original->budget_period_id !== null) {
                $period = BudgetPeriod::query()
                    ->whereKey($original->budget_period_id)
                    ->lockForUpdate()
                    ->first();

                if (! $period instanceof BudgetPeriod) {
                    throw new FinancialIntegrityException('The transaction budget period is missing.');
                }

                $this->recalculatePeriodStateLocked($period);
            }

            $this->syncWalletCacheLocked($user);

            return $reversal->refresh();
        }, 5);
    }

    public function undoGajian(int $userId): BudgetCycle
    {
        return DB::transaction(function () use ($userId): BudgetCycle {
            $user = $this->lockUser($userId);

            $cycle = BudgetCycle::query()
                ->where('user_id', $user->getKey())
                ->where('status', 'active')
                ->latest('start_date')
                ->latest('id')
                ->lockForUpdate()
                ->first();

            if (! $cycle instanceof BudgetCycle) {
                throw new UserFinancialException('No active salary cycle exists to undo.');
            }

            $salaryTransaction = Transaction::query()
                ->where('user_id', $user->getKey())
                ->where('budget_cycle_id', $cycle->getKey())
                ->where('type', 'income')
                ->whereNull('source_wallet')
                ->where('target_wallet', 'uang_dingin')
                ->whereNull('budget_period_id')
                ->where('status', 'success')
                ->lockForUpdate()
                ->first();

            if (! $salaryTransaction instanceof Transaction) {
                throw new FinancialIntegrityException('The active cycle has no reversible salary transaction.');
            }

            $unlinkedInstallmentTransactionExists = Transaction::query()
                ->where('user_id', $user->getKey())
                ->where('budget_cycle_id', $cycle->getKey())
                ->where('type', 'expense')
                ->where('source_wallet', 'uang_dingin')
                ->whereNull('budget_period_id')
                ->whereNull('installment_id')
                ->where('status', 'success')
                ->lockForUpdate()
                ->exists();

            if ($unlinkedInstallmentTransactionExists) {
                throw new FinancialIntegrityException(
                    'An automatic installment transaction is missing its installment relation.'
                );
            }

            $installmentTransactions = Transaction::query()
                ->where('user_id', $user->getKey())
                ->where('budget_cycle_id', $cycle->getKey())
                ->where('type', 'expense')
                ->where('source_wallet', 'uang_dingin')
                ->whereNull('budget_period_id')
                ->whereNotNull('installment_id')
                ->where('status', 'success')
                ->orderBy('id')
                ->lockForUpdate()
                ->get();

            $batchLastTransactionId = max(
                (int) $salaryTransaction->getKey(),
                (int) ($installmentTransactions->max('id') ?? 0),
            );

            $laterTransactionExists = Transaction::query()
                ->where('user_id', $user->getKey())
                ->where('status', 'success')
                ->where('id', '>', $batchLastTransactionId)
                ->lockForUpdate()
                ->exists();

            if ($laterTransactionExists) {
                throw new UserFinancialException('A later transaction exists after the salary batch.');
            }

            $this->reverseTransactionLocked($salaryTransaction);

            foreach ($installmentTransactions as $installmentTransaction) {
                $this->reverseTransactionLocked($installmentTransaction);

                $installment = Installment::query()
                    ->whereKey($installmentTransaction->installment_id)
                    ->lockForUpdate()
                    ->first();

                if (! $installment instanceof Installment) {
                    throw new FinancialIntegrityException('A salary installment is missing.');
                }

                if ($installment->sisa_tenor_bulan !== null) {
                    $installment->forceFill([
                        'sisa_tenor_bulan' => $installment->sisa_tenor_bulan + 1,
                        'active' => true,
                    ])->save();
                }
            }

            $periods = BudgetPeriod::query()
                ->where('budget_cycle_id', $cycle->getKey())
                ->orderBy('period_number')
                ->lockForUpdate()
                ->get();

            foreach ($periods as $period) {
                $period->forceFill(['status' => 'cancelled'])->save();
            }

            $cycle->forceFill(['status' => 'cancelled'])->save();
            $this->syncOrResetWalletCacheLocked($user);

            return $cycle->refresh();
        }, 5);
    }

    public function recalculatePeriodState(BudgetPeriod $period): BudgetPeriod
    {
        return DB::transaction(
            fn (): BudgetPeriod => $this->recalculatePeriodStateLocked($period),
            5
        );
    }

    public function syncWalletCache(int $userId): Wallet
    {
        return DB::transaction(function () use ($userId): Wallet {
            $user = $this->lockUser($userId);

            return $this->syncWalletCacheLocked($user);
        }, 5);
    }

    private function lockUser(int $userId): User
    {
        $user = User::query()
            ->whereKey($userId)
            ->lockForUpdate()
            ->first();

        if (! $user instanceof User) {
            throw new FinancialIntegrityException('User not found.');
        }

        return $user;
    }

    private function localTodayFor(User $user): CarbonImmutable
    {
        try {
            return CarbonImmutable::now($user->timezone)->startOfDay();
        } catch (Throwable $exception) {
            throw new FinancialIntegrityException('The user timezone is invalid.', 0, $exception);
        }
    }

    private function parsePayDate(string $payDate, string $timezone): CarbonImmutable
    {
        try {
            $date = CarbonImmutable::createFromFormat('!Y-m-d', $payDate, $timezone);
        } catch (Throwable $exception) {
            throw new InvalidArgumentException('Pay date must be a valid date in Y-m-d format.', 0, $exception);
        }

        if ($date->format('Y-m-d') !== $payDate) {
            throw new InvalidArgumentException('Pay date must be a valid date in Y-m-d format.');
        }

        return $date;
    }

    private function todayFor(User $user): string
    {
        return $this->localTodayFor($user)->toDateString();
    }

    private function resolveInstallmentAmount(Installment $installment, string $billingMonth): int
    {
        if (! in_array($installment->jenis, ['tetap', 'revolving'], true)) {
            throw new FinancialIntegrityException("Installment {$installment->name} has an invalid type.");
        }

        $schedule = $installment->jadwal_khusus;

        if ($schedule !== null && ! is_array($schedule)) {
            throw new FinancialIntegrityException("Installment {$installment->name} has a corrupt schedule.");
        }

        $hasMonthlyAmount = is_array($schedule) && array_key_exists($billingMonth, $schedule);

        if ($installment->jenis === 'revolving' && ! $hasMonthlyAmount) {
            throw new UserFinancialException(
                "Revolving installment {$installment->name} has no schedule for {$billingMonth}."
            );
        }

        $amount = $hasMonthlyAmount
            ? $schedule[$billingMonth]
            : $installment->nominal_default;

        if (! is_int($amount) || $amount < 0) {
            throw new FinancialIntegrityException(
                "Installment {$installment->name} has an invalid amount for {$billingMonth}."
            );
        }

        return $amount;
    }

    private function resolveExpenseCategory(?string $keyword): Category
    {
        if ($keyword !== null) {
            $normalizedKeyword = CategoryKeyword::normalizeKeyword($keyword);

            if ($normalizedKeyword !== '') {
                $categoryKeyword = CategoryKeyword::query()
                    ->with('category')
                    ->where('normalized_keyword', $normalizedKeyword)
                    ->first();

                if ($categoryKeyword?->category instanceof Category) {
                    return $categoryKeyword->category;
                }
            }
        }

        return Category::query()->firstOrCreate([
            'name' => 'Lainnya',
        ]);
    }

    private function lockCurrentCycle(User $user, string $today): BudgetCycle
    {
        $cycle = BudgetCycle::query()
            ->where('user_id', $user->getKey())
            ->where('status', 'active')
            ->whereDate('start_date', '<=', $today)
            ->whereDate('end_date', '>=', $today)
            ->orderBy('id')
            ->lockForUpdate()
            ->first();

        if (! $cycle instanceof BudgetCycle) {
            throw new UserFinancialException('No active budget cycle exists for today.');
        }

        return $cycle;
    }

    private function lockCurrentPeriod(BudgetCycle $cycle, string $today): BudgetPeriod
    {
        $period = BudgetPeriod::query()
            ->where('budget_cycle_id', $cycle->getKey())
            ->whereDate('start_date', '<=', $today)
            ->whereDate('end_date', '>=', $today)
            ->orderBy('period_number')
            ->lockForUpdate()
            ->first();

        if (! $period instanceof BudgetPeriod) {
            throw new UserFinancialException('No active budget period exists for today.');
        }

        return $period;
    }

    private function recalculatePeriodStateLocked(BudgetPeriod $period): BudgetPeriod
    {
        if (! $period->exists || $period->getKey() === null) {
            throw new InvalidArgumentException('Budget period must already exist.');
        }

        $targetPeriod = BudgetPeriod::query()->find($period->getKey());

        if (! $targetPeriod instanceof BudgetPeriod) {
            throw new FinancialIntegrityException('Budget period not found.');
        }

        if ($targetPeriod->period_number < 1 || $targetPeriod->period_number > 4) {
            throw new FinancialIntegrityException('Budget period number is invalid.');
        }

        $periods = BudgetPeriod::query()
            ->where('budget_cycle_id', $targetPeriod->budget_cycle_id)
            ->where('period_number', '<=', $targetPeriod->period_number)
            ->orderBy('period_number')
            ->lockForUpdate()
            ->get();

        if ($periods->count() !== $targetPeriod->period_number) {
            throw new FinancialIntegrityException('A required previous budget period is missing.');
        }

        $previousRemainingAmount = 0;

        foreach ($periods as $index => $currentPeriod) {
            $expectedPeriodNumber = $index + 1;

            if ($currentPeriod->period_number !== $expectedPeriodNumber) {
                throw new FinancialIntegrityException('Budget periods are not sequential.');
            }

            $spentAmount = (int) Transaction::query()
                ->where('budget_period_id', $currentPeriod->getKey())
                ->where('type', 'expense')
                ->where('status', 'success')
                ->sum('amount');

            $transferIn = (int) Transaction::query()
                ->where('budget_period_id', $currentPeriod->getKey())
                ->where('type', 'transfer')
                ->where('target_wallet', 'dompet_jajan_aktif')
                ->where('status', 'success')
                ->sum('amount');

            $carryOverAmount = $currentPeriod->period_number === 1
                ? 0
                : $previousRemainingAmount;
            $totalBudget = $currentPeriod->allocated_amount + $carryOverAmount + $transferIn;
            $remainingAmount = $totalBudget - $spentAmount;

            $currentPeriod->forceFill([
                'carry_over_amount' => $carryOverAmount,
                'total_budget' => $totalBudget,
                'spent_amount' => $spentAmount,
                'remaining_amount' => $remainingAmount,
            ])->save();

            $previousRemainingAmount = $remainingAmount;
        }

        $recalculatedPeriod = $periods->last();

        if (! $recalculatedPeriod instanceof BudgetPeriod) {
            throw new FinancialIntegrityException('Budget period could not be recalculated.');
        }

        return $recalculatedPeriod->refresh();
    }

    private function reverseTransactionLocked(Transaction $original): Transaction
    {
        if ($original->status !== 'success' || $original->type === 'reversal') {
            throw new FinancialIntegrityException('The transaction cannot be reversed.');
        }

        $reversal = Transaction::query()->create([
            'user_id' => $original->user_id,
            'budget_cycle_id' => $original->budget_cycle_id,
            'budget_period_id' => $original->budget_period_id,
            'type' => 'reversal',
            'amount' => $original->amount,
            'description' => 'Pembatalan: '.$original->description,
            'category_id' => $original->category_id,
            'installment_id' => $original->installment_id,
            'source_wallet' => $original->target_wallet,
            'target_wallet' => $original->source_wallet,
            'reference_transaction_id' => $original->getKey(),
            'status' => 'success',
        ]);

        $original->forceFill(['status' => 'reversed'])->save();

        return $reversal;
    }

    private function syncOrResetWalletCacheLocked(User $user): Wallet
    {
        $today = $this->todayFor($user);

        $cycle = BudgetCycle::query()
            ->where('user_id', $user->getKey())
            ->where('status', 'active')
            ->whereDate('start_date', '<=', $today)
            ->whereDate('end_date', '>=', $today)
            ->orderBy('id')
            ->lockForUpdate()
            ->first();

        if (! $cycle instanceof BudgetCycle) {
            return $this->resetWalletCacheLocked($user);
        }

        $period = $this->lockCurrentPeriod($cycle, $today);

        return $this->syncWalletCacheLocked($user, $cycle, $period, $today);
    }

    private function resetWalletCacheLocked(User $user): Wallet
    {
        $wallet = Wallet::query()
            ->where('user_id', $user->getKey())
            ->lockForUpdate()
            ->first();

        $wallet ??= new Wallet(['user_id' => $user->getKey()]);

        $wallet->forceFill([
            'uang_dingin' => 0,
            'dompet_jajan_aktif' => 0,
        ])->save();

        return $wallet->refresh();
    }

    private function syncWalletCacheLocked(
        User $user,
        ?BudgetCycle $cycle = null,
        ?BudgetPeriod $period = null,
        ?string $today = null
    ): Wallet {
        $today ??= $this->todayFor($user);
        $cycle ??= $this->lockCurrentCycle($user, $today);
        $period ??= $this->lockCurrentPeriod($cycle, $today);

        if ((int) $cycle->user_id !== (int) $user->getKey()) {
            throw new FinancialIntegrityException('Budget cycle does not belong to the user.');
        }

        if ((int) $period->budget_cycle_id !== (int) $cycle->getKey()) {
            throw new FinancialIntegrityException('Budget period does not belong to the active cycle.');
        }

        $activePeriod = $this->recalculatePeriodStateLocked($period);

        $wallet = Wallet::query()
            ->where('user_id', $user->getKey())
            ->lockForUpdate()
            ->first();

        $incomeToCold = (int) Transaction::query()
            ->where('budget_cycle_id', $cycle->getKey())
            ->where('type', 'income')
            ->where('target_wallet', 'uang_dingin')
            ->where('status', 'success')
            ->sum('amount');

        $coldExpenses = (int) Transaction::query()
            ->where('budget_cycle_id', $cycle->getKey())
            ->where('type', 'expense')
            ->where('source_wallet', 'uang_dingin')
            ->whereNull('budget_period_id')
            ->where('status', 'success')
            ->sum('amount');

        $manualTransferOut = (int) Transaction::query()
            ->where('budget_cycle_id', $cycle->getKey())
            ->where('type', 'transfer')
            ->where('source_wallet', 'uang_dingin')
            ->where('status', 'success')
            ->sum('amount');

        $releasedAllocations = (int) BudgetPeriod::query()
            ->where('budget_cycle_id', $cycle->getKey())
            ->whereDate('start_date', '<=', $today)
            ->sum('allocated_amount');

        $coldBalance = $incomeToCold
            - $coldExpenses
            - $releasedAllocations
            - $manualTransferOut;

        $wallet ??= new Wallet([
            'user_id' => $user->getKey(),
        ]);

        $wallet->forceFill([
            'uang_dingin' => $coldBalance,
            'dompet_jajan_aktif' => $activePeriod->remaining_amount,
        ])->save();

        return $wallet->refresh();
    }
}
