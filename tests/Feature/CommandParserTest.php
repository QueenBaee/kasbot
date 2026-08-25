<?php

declare(strict_types=1);

use App\Data\CommandResult;
use App\Exceptions\Financial\FinancialIntegrityException;
use App\Models\BudgetCycle;
use App\Models\BudgetPeriod;
use App\Models\Category;
use App\Models\CategoryKeyword;
use App\Models\Installment;
use App\Models\Transaction;
use App\Models\User;
use App\Services\CommandParserService;
use App\Services\FinancialEngineService;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-08-25 10:00:00', 'Asia/Jakarta'));
    $this->user = User::query()->create([
        'telegram_user_id' => random_int(1, PHP_INT_MAX),
        'name' => 'Parser User',
        'timezone' => 'Asia/Jakarta',
    ]);
    $this->parser = app(CommandParserService::class);
});

afterEach(fn () => CarbonImmutable::setTestNow());

test('expected user financial failures become command results', function (): void {
    $result = $this->parser->handleIncomingMessage($this->user->id, '15000 bakso');

    expect($result)->toBeInstanceOf(CommandResult::class)
        ->and($result->text)->toContain('Belum ada siklus gajian aktif');
});

test('financial integrity failures bubble and roll back the expense', function (): void {
    $cycle = BudgetCycle::query()->create([
        'user_id' => $this->user->id,
        'start_date' => '2026-08-24',
        'end_date' => '2026-09-24',
        'gross_income' => 100_000,
        'total_installments' => 0,
        'net_income' => 100_000,
        'status' => 'active',
    ]);
    BudgetPeriod::query()->create([
        'budget_cycle_id' => $cycle->id,
        'period_number' => 2,
        'start_date' => '2026-08-24',
        'end_date' => '2026-09-08',
        'allocated_amount' => 25_000,
        'carry_over_amount' => 0,
        'total_budget' => 25_000,
        'spent_amount' => 0,
        'remaining_amount' => 25_000,
        'status' => 'active',
    ]);

    expect(fn () => $this->parser->handleIncomingMessage($this->user->id, '15000 bakso'))
        ->toThrow(FinancialIntegrityException::class);
    expect(Transaction::query()->count())->toBe(0);
});

test('unexpected runtime failures bubble out of the parser', function (): void {
    $this->user->forceFill(['timezone' => 'Not/A-Timezone'])->save();

    expect(fn () => $this->parser->handleIncomingMessage($this->user->id, '/gajian 100000'))
        ->toThrow(RuntimeException::class);
});

test('money formats and bot usernames are parsed as integer rupiah', function (string $input, int $expected): void {
    app(FinancialEngineService::class)->processGajian($this->user->id, 8_000_000, '2026-08-25');
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-08-26 10:00:00', 'Asia/Jakarta'));
    $this->parser->handleIncomingMessage($this->user->id, $input);

    expect(Transaction::query()->where('type', 'expense')->sole()->amount)->toBe($expected);
})->with([
    'dot grouping' => ['/input@KasBot 15.000 makan siang', 15_000],
    'multiple dots' => ['1.500.000 laptop sleeve', 1_500_000],
    'comma grouping' => ['1,500,000 laptop sleeve', 1_500_000],
]);

test('tagihan parses names amounts and tenors without absorbing numeric fields', function (string $command, string $name, int $amount, int $tenor): void {
    $result = $this->parser->handleIncomingMessage($this->user->id, $command);
    $installment = Installment::query()->where('user_id', $this->user->id)->sole();

    expect($result->text)->toContain("Tagihan {$name} berhasil disimpan")
        ->and($installment->name)->toBe($name)
        ->and($installment->nominal_default)->toBe($amount)
        ->and($installment->sisa_tenor_bulan)->toBe($tenor)
        ->and($installment->active)->toBeTrue();
})->with([
    'single-word name' => ['/tagihan laptop 810234 4', 'laptop', 810_234, 4],
    'multi-word name' => ['/tagihan cicilan laptop gaming 1500000 12', 'cicilan laptop gaming', 1_500_000, 12],
]);

test('tagihan hapus deactivates only the current users active case-insensitive match', function (): void {
    $otherUser = User::query()->create([
        'telegram_user_id' => random_int(1, PHP_INT_MAX),
        'name' => 'Other User',
        'timezone' => 'Asia/Jakarta',
    ]);
    $owned = Installment::query()->create([
        'user_id' => $this->user->id,
        'name' => 'cicilan laptop gaming',
        'jenis' => 'tetap',
        'nominal_default' => 1_500_000,
        'sisa_tenor_bulan' => 12,
        'active' => true,
    ]);
    $other = Installment::query()->create([
        'user_id' => $otherUser->id,
        'name' => 'cicilan laptop gaming',
        'jenis' => 'tetap',
        'nominal_default' => 1_500_000,
        'sisa_tenor_bulan' => 12,
        'active' => true,
    ]);

    $result = $this->parser->handleIncomingMessage($this->user->id, '/tagihan_hapus CICILAN LAPTOP GAMING');

    expect($result->text)->toBe('✅ Tagihan cicilan laptop gaming berhasil dihapus.')
        ->and($owned->refresh()->active)->toBeFalse()
        ->and($other->refresh()->active)->toBeTrue();
});

test('tagihan hapus reports when no active matching record exists', function (): void {
    $result = $this->parser->handleIncomingMessage($this->user->id, '/tagihan_hapus laptop');

    expect($result->text)->toBe('❌ Tagihan laptop tidak ditemukan.');
});

test('longest normalized keyword wins and normalized keyword is globally unique', function (): void {
    app(FinancialEngineService::class)->processGajian($this->user->id, 400_000, '2026-08-25');
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-08-26 10:00:00', 'Asia/Jakarta'));
    $coffee = Category::query()->create(['name' => 'Kopi']);
    $milkCoffee = Category::query()->create(['name' => 'Kopi Susu']);
    CategoryKeyword::query()->create(['category_id' => $coffee->id, 'keyword' => 'kopi']);
    $specific = CategoryKeyword::query()->create([
        'category_id' => $milkCoffee->id,
        'keyword' => '  KOPI   SUSU  ',
    ]);

    $this->parser->handleIncomingMessage($this->user->id, '15000 beli kopi susu');

    expect($specific->normalized_keyword)->toBe('kopi susu')
        ->and(Transaction::query()->where('type', 'expense')->sole()->category_id)->toBe($milkCoffee->id);
    expect(fn () => CategoryKeyword::query()->create([
        'category_id' => $coffee->id,
        'keyword' => 'Kopi  Susu',
    ]))->toThrow(QueryException::class);
});

test('reserved category names have a database unique constraint', function (): void {
    Category::query()->create(['name' => 'Lainnya']);

    expect(fn () => Category::query()->create(['name' => 'Lainnya']))
        ->toThrow(QueryException::class);
});

test('phase five reporting commands are routed and export carries document metadata', function (): void {
    Storage::fake('local');
    app(FinancialEngineService::class)->processGajian($this->user->id, 400_000, '2026-08-25');
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-08-26 10:00:00', 'Asia/Jakarta'));

    expect($this->parser->handleIncomingMessage($this->user->id, '/status')->text)->toContain('STATUS KEUANGAN')
        ->and($this->parser->handleIncomingMessage($this->user->id, '/recap')->text)->toContain('REKAP KEUANGAN')
        ->and($this->parser->handleIncomingMessage($this->user->id, '/recap bulan')->text)->toContain('REKAP KEUANGAN')
        ->and($this->parser->handleIncomingMessage($this->user->id, '/proyeksi')->text)->toContain('PROYEKSI CICILAN');

    $export = $this->parser->handleIncomingMessage($this->user->id, '/export');

    expect($export->text)->toBe('✅ Export transaksi siap.')
        ->and($export->documentPath)->not->toBeNull()
        ->and($export->documentName)->toEndWith('.csv')
        ->and($export->documentMimeType)->toBe('text/csv')
        ->and($export->parseMode)->toBeNull();
});

test('natural expense response is the smart guide and unsupported recap is safely rejected', function (): void {
    app(FinancialEngineService::class)->processGajian($this->user->id, 400_000, '2026-08-25');
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-08-26 10:00:00', 'Asia/Jakarta'));

    $expense = $this->parser->handleIncomingMessage($this->user->id, '15000 bakso');
    $unsupported = $this->parser->handleIncomingMessage($this->user->id, '/recap minggu');

    expect($expense->text)->toContain('bakso tercatat')
        ->and($expense->text)->toContain('Batas aman:')
        ->and($unsupported->text)->toContain('Format command tidak valid');
});
