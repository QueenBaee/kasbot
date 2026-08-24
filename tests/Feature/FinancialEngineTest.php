<?php

declare(strict_types=1);

use App\Exceptions\Financial\FinancialIntegrityException;
use App\Exceptions\Financial\UserFinancialException;
use App\Models\BudgetCycle;
use App\Models\Installment;
use App\Models\Transaction;
use App\Models\User;
use App\Services\FinancialEngineService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-08-25 10:00:00', 'Asia/Jakarta'));
    $this->user = User::query()->create([
        'telegram_user_id' => random_int(1, PHP_INT_MAX),
        'name' => 'Phase Four User',
        'timezone' => 'Asia/Jakarta',
    ]);
    $this->engine = app(FinancialEngineService::class);
});

afterEach(fn () => CarbonImmutable::setTestNow());

test('overspending creates one expense ledger row and a negative remaining amount', function (): void {
    $cycle = $this->engine->processGajian($this->user->id, 40_000, '2026-08-25');
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-08-26 10:00:00', 'Asia/Jakarta'));
    $this->engine->recordExpense($this->user->id, 15_000, 'Bakso');

    expect(Transaction::query()->where('type', 'expense')->count())->toBe(1)
        ->and($cycle->budgetPeriods()->where('period_number', 1)->firstOrFail()->remaining_amount)->toBe(-5_000)
        ->and($this->user->wallet()->firstOrFail()->dompet_jajan_aktif)->toBe(-5_000);
});

test('additional income increases cold money without changing weekly allocation', function (): void {
    $cycle = $this->engine->processGajian($this->user->id, 400_000, '2026-08-25');
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-08-26 10:00:00', 'Asia/Jakarta'));
    $this->engine->syncWalletCache($this->user->id);
    $period = $cycle->budgetPeriods()->where('period_number', 1)->firstOrFail();
    $allocation = $period->allocated_amount;
    $coldBefore = $this->user->wallet()->firstOrFail()->uang_dingin;

    $income = $this->engine->recordIncome($this->user->id, 50_000, 'Bonus project');

    expect($income->source_wallet)->toBe('external')
        ->and($this->user->wallet()->firstOrFail()->uang_dingin)->toBe($coldBefore + 50_000)
        ->and($period->refresh()->allocated_amount)->toBe($allocation)
        ->and($period->total_budget)->toBe($allocation);
});

test('expense undo preserves originals and selects the next eligible transaction', function (): void {
    $cycle = $this->engine->processGajian($this->user->id, 400_000, '2026-08-25');
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-08-26 10:00:00', 'Asia/Jakarta'));
    $period = $cycle->budgetPeriods()->where('period_number', 1)->firstOrFail();
    $first = $this->engine->recordExpense($this->user->id, 10_000, 'First expense');
    $second = $this->engine->recordExpense($this->user->id, 20_000, 'Second expense');

    $firstReversal = $this->engine->undoLastTransaction($this->user->id);
    $secondReversal = $this->engine->undoLastTransaction($this->user->id);

    expect($first->refresh()->status)->toBe('reversed')
        ->and($second->refresh()->status)->toBe('reversed')
        ->and($firstReversal->reference_transaction_id)->toBe($second->id)
        ->and($secondReversal->reference_transaction_id)->toBe($first->id)
        ->and(Transaction::query()->whereKey($first->id)->exists())->toBeTrue()
        ->and($period->refresh()->spent_amount)->toBe(0)
        ->and($period->remaining_amount)->toBe(100_000)
        ->and($this->user->wallet()->firstOrFail()->dompet_jajan_aktif)->toBe(100_000);
});

test('undo gajian reverses its batch restores tenor cancels projections and permits correction', function (): void {
    $installment = Installment::query()->create([
        'user_id' => $this->user->id,
        'name' => 'Laptop',
        'jenis' => 'tetap',
        'nominal_default' => 100_000,
        'sisa_tenor_bulan' => 1,
        'active' => true,
    ]);
    $cancelledCycle = $this->engine->processGajian($this->user->id, 500_000, '2026-08-25');
    $salary = $cancelledCycle->transactions()->where('type', 'income')->firstOrFail();
    $installmentTransaction = $cancelledCycle->transactions()->whereNotNull('installment_id')->firstOrFail();

    $this->engine->undoGajian($this->user->id);

    expect($salary->refresh()->status)->toBe('reversed')
        ->and($installmentTransaction->refresh()->status)->toBe('reversed')
        ->and($installmentTransaction->installment_id)->toBe($installment->id)
        ->and($installment->refresh()->sisa_tenor_bulan)->toBe(1)
        ->and($installment->active)->toBeTrue()
        ->and($cancelledCycle->refresh()->status)->toBe('cancelled')
        ->and($cancelledCycle->budgetPeriods()->where('status', 'cancelled')->count())->toBe(4)
        ->and($this->user->wallet()->firstOrFail()->uang_dingin)->toBe(0)
        ->and($this->user->wallet()->firstOrFail()->dompet_jajan_aktif)->toBe(0)
        ->and(Transaction::query()->where('type', 'reversal')->count())->toBe(2);

    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-08-25 10:00:00', 'Asia/Jakarta'));
    $correctedCycle = $this->engine->processGajian($this->user->id, 600_000, '2026-08-25');

    expect($correctedCycle->id)->not->toBe($cancelledCycle->id)
        ->and($correctedCycle->status)->toBe('active')
        ->and(BudgetCycle::query()->where('status', 'cancelled')->count())->toBe(1);
});

test('later user activity blocks gajian undo while automatic installments do not', function (): void {
    Installment::query()->create([
        'user_id' => $this->user->id,
        'name' => 'Phone',
        'jenis' => 'tetap',
        'nominal_default' => 50_000,
        'sisa_tenor_bulan' => 2,
        'active' => true,
    ]);
    $cycle = $this->engine->processGajian($this->user->id, 500_000, '2026-08-25');
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-08-26 10:00:00', 'Asia/Jakarta'));
    $this->engine->recordIncome($this->user->id, 10_000, 'Later income');

    expect(fn () => $this->engine->undoGajian($this->user->id))
        ->toThrow(UserFinancialException::class);
    expect($cycle->refresh()->status)->toBe('active')
        ->and($cycle->transactions()->where('status', 'reversed')->count())->toBe(0);
});

test('an unlinked automatic installment row is an integrity failure', function (): void {
    $cycle = $this->engine->processGajian($this->user->id, 500_000, '2026-08-25');
    Transaction::query()->create([
        'user_id' => $this->user->id,
        'budget_cycle_id' => $cycle->id,
        'budget_period_id' => null,
        'type' => 'expense',
        'amount' => 50_000,
        'description' => 'Impossible unlinked installment',
        'category_id' => null,
        'installment_id' => null,
        'source_wallet' => 'uang_dingin',
        'target_wallet' => null,
        'reference_transaction_id' => null,
        'status' => 'success',
    ]);

    expect(fn () => $this->engine->undoGajian($this->user->id))
        ->toThrow(FinancialIntegrityException::class);
    expect($cycle->refresh()->status)->toBe('active');
});
