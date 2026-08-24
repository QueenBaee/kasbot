<?php

declare(strict_types=1);

use App\Models\Category;
use App\Models\Installment;
use App\Models\TelegramOutbox;
use App\Models\Transaction;
use App\Models\User;
use App\Services\FinancialEngineService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-08-25 10:00:00', 'Asia/Jakarta'));
    $this->user = User::query()->create([
        'telegram_user_id' => random_int(1, PHP_INT_MAX), 'name' => 'Scheduled User', 'timezone' => 'Asia/Jakarta',
    ]);
    $this->engine = app(FinancialEngineService::class);
});

afterEach(fn () => CarbonImmutable::setTestNow());

test('period start queues periods two through four once without changing the ledger', function (): void {
    $cycle = $this->engine->processGajian($this->user->id, 400_000, '2026-08-25');
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-08-26 10:00:00', 'Asia/Jakarta'));
    $this->engine->recordExpense($this->user->id, 10_000, 'Expense before carry over');
    $ledgerCount = Transaction::query()->count();
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-09-02 00:00:00', 'Asia/Jakarta'));

    $this->artisan('finance:notify-period-starts')->assertSuccessful();
    $this->artisan('finance:notify-period-starts')->assertSuccessful();

    $periodTwo = $cycle->budgetPeriods()->where('period_number', 2)->firstOrFail();
    $outbox = TelegramOutbox::query()->sole();
    expect($outbox->dedupe_key)->toBe("period-start:{$this->user->id}:{$periodTwo->id}")
        ->and($outbox->text)->toContain('Carry-over: Rp90.000')
        ->and(TelegramOutbox::query()->count())->toBe(1)
        ->and(Transaction::query()->count())->toBe($ledgerCount);
});

test('period start does not queue before the period date', function (): void {
    $this->engine->processGajian($this->user->id, 400_000, '2026-08-25');
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-09-01 00:00:00', 'Asia/Jakarta'));

    $this->artisan('finance:notify-period-starts')->assertSuccessful();

    expect(TelegramOutbox::query()->count())->toBe(0);
});

test('daily recap includes only successful daily expenses and is deduplicated', function (): void {
    Installment::query()->create([
        'user_id' => $this->user->id, 'name' => 'Motor', 'jenis' => 'tetap',
        'nominal_default' => 40_000, 'sisa_tenor_bulan' => 2, 'active' => true,
    ]);
    $this->engine->processGajian($this->user->id, 440_000, '2026-08-25');
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-08-26 12:00:00', 'Asia/Jakarta'));
    $food = Category::query()->create(['name' => 'Makanan']);
    $first = $this->engine->recordExpense($this->user->id, 25_000, 'Bakso');
    $first->forceFill(['category_id' => $food->id])->save();
    $this->engine->recordExpense($this->user->id, 10_000, 'Reversed expense');
    $this->engine->undoLastTransaction($this->user->id);
    $this->engine->transferAmbilDingin($this->user->id, 5_000);
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-08-26 20:00:00', 'Asia/Jakarta'));

    $this->artisan('finance:daily-recap')->assertSuccessful();
    $this->artisan('finance:daily-recap')->assertSuccessful();

    $outbox = TelegramOutbox::query()->sole();
    expect($outbox->text)->toContain('Makanan: Rp25.000')
        ->and($outbox->text)->toContain('Total hari ini: Rp25.000')
        ->and($outbox->text)->not->toContain('Rp10.000')
        ->and($outbox->text)->not->toContain('Rp40.000')
        ->and(TelegramOutbox::query()->count())->toBe(1);
});

test('daily recap handles a zero spending day and overspending state', function (): void {
    $zeroUser = User::query()->create(['telegram_user_id' => 8123, 'name' => 'Zero User', 'timezone' => 'Asia/Jakarta']);
    $this->engine->processGajian($this->user->id, 40_000, '2026-08-25');
    $this->engine->processGajian($zeroUser->id, 400_000, '2026-08-25');
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-08-26 12:00:00', 'Asia/Jakarta'));
    $this->engine->recordExpense($this->user->id, 15_000, 'Overspend');
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-08-26 20:00:00', 'Asia/Jakarta'));

    $this->artisan('finance:daily-recap')->assertSuccessful();

    $overspend = TelegramOutbox::query()->where('user_id', $this->user->id)->sole();
    $zero = TelegramOutbox::query()->where('user_id', $zeroUser->id)->sole();
    expect($overspend->text)->toContain('Jatah periode: -Rp5.000')
        ->and($overspend->text)->toContain('Uang dingin tetap tidak dipotong otomatis')
        ->and($zero->text)->toContain('Hari ini belum ada pengeluaran')
        ->and($zero->text)->toContain('Total hari ini: Rp0');
});

test('gajian reminder queues once on local 25 and never creates a cycle', function (): void {
    $this->artisan('finance:gajian-reminder')->assertSuccessful();
    $this->artisan('finance:gajian-reminder')->assertSuccessful();

    expect(TelegramOutbox::query()->count())->toBe(1)
        ->and(TelegramOutbox::query()->sole()->text)->toContain('/gajian <nominal>')
        ->and($this->user->budgetCycles()->count())->toBe(0);
});

test('gajian reminder is skipped after salary processing', function (): void {
    $this->engine->processGajian($this->user->id, 400_000, '2026-08-25');

    $this->artisan('finance:gajian-reminder')->assertSuccessful();

    expect(TelegramOutbox::query()->count())->toBe(0);
});
