<?php

declare(strict_types=1);

use App\Models\Category;
use App\Models\Installment;
use App\Models\User;
use App\Services\FinancialEngineService;
use App\Services\ReportingService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-08-25 10:00:00', 'Asia/Jakarta'));
    $this->user = User::query()->create([
        'telegram_user_id' => random_int(1, PHP_INT_MAX),
        'name' => 'Reporting User',
        'timezone' => 'Asia/Jakarta',
    ]);
    $this->engine = app(FinancialEngineService::class);
    $this->reporting = app(ReportingService::class);
});

afterEach(fn () => CarbonImmutable::setTestNow());

test('smart guide counts remaining days inclusively and uses conservative integer division', function (): void {
    $this->engine->processGajian($this->user->id, 400_000, '2026-08-25');
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-09-05 10:00:00', 'Asia/Jakarta'));
    $expense = $this->engine->recordExpense($this->user->id, 10_001, 'Bakso Pak Yud');

    $response = $this->reporting->getSmartGuideResponse($this->user->id, $expense);

    expect($response)->toContain('Sisa waktu: 4 hari')
        ->and($response)->toContain('Batas aman: Rp47.499/hari');
});

test('smart guide uses one day on the final date', function (): void {
    $this->engine->processGajian($this->user->id, 400_000, '2026-08-25');
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-09-01 10:00:00', 'Asia/Jakarta'));
    $expense = $this->engine->recordExpense($this->user->id, 10_000, 'Makan siang');

    expect($this->reporting->getSmartGuideResponse($this->user->id, $expense))
        ->toContain('Sisa waktu: 1 hari');
});

test('overspend warns without consuming cold money and suggests an explicit transfer', function (): void {
    $this->engine->processGajian($this->user->id, 40_000, '2026-08-25');
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-08-26 10:00:00', 'Asia/Jakarta'));
    $coldBefore = $this->engine->syncWalletCache($this->user->id)->uang_dingin;
    $expense = $this->engine->recordExpense($this->user->id, 15_000, 'Makan besar');

    $response = $this->reporting->getSmartGuideResponse($this->user->id, $expense);

    expect($response)->toContain('Jatah periode sudah minus Rp5.000')
        ->and($response)->toContain('Uang dingin tidak dipotong otomatis')
        ->and($response)->toContain('/ambil_dingin <nominal>')
        ->and($this->user->wallet()->firstOrFail()->uang_dingin)->toBe($coldBefore);
});

test('status derives totals from successful ledger rows and rebuilds wallet projection', function (): void {
    Installment::query()->create([
        'user_id' => $this->user->id, 'name' => 'Motor', 'jenis' => 'tetap',
        'nominal_default' => 40_000, 'sisa_tenor_bulan' => 2, 'active' => true,
    ]);
    $this->engine->processGajian($this->user->id, 440_000, '2026-08-25');
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-08-26 10:00:00', 'Asia/Jakarta'));
    $this->engine->recordIncome($this->user->id, 50_000, 'Bonus');
    $this->engine->recordExpense($this->user->id, 10_000, 'Bakso');
    $this->engine->recordExpense($this->user->id, 20_000, 'Kopi');
    $this->engine->undoLastTransaction($this->user->id);
    $this->user->wallet()->firstOrFail()->forceFill(['uang_dingin' => 999, 'dompet_jajan_aktif' => 999])->save();

    $status = $this->reporting->handleStatus($this->user->id);

    expect($status)->toContain('Gaji: Rp440.000')
        ->and($status)->toContain('Tambahan: Rp50.000')
        ->and($status)->toContain('Total: Rp490.000')
        ->and($status)->toContain('Cicilan: Rp40.000')
        ->and($status)->toContain('Pengeluaran harian: Rp10.000')
        ->and($status)->not->toContain('Rp999');
});

test('status replaces safe daily guidance with an overspend warning', function (): void {
    $this->engine->processGajian($this->user->id, 40_000, '2026-08-25');
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-08-26 10:00:00', 'Asia/Jakarta'));
    $this->engine->recordExpense($this->user->id, 15_000, 'Overspend');

    $status = $this->reporting->handleStatus($this->user->id);

    expect($status)->toContain('Jatah periode minus Rp5.000')
        ->and($status)->toContain('Uang dingin tidak dipotong otomatis')
        ->and($status)->not->toContain('Batas aman:');
});

test('recap excludes transfers reversals and reversed originals', function (): void {
    $this->engine->processGajian($this->user->id, 400_000, '2026-08-25');
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-08-26 10:00:00', 'Asia/Jakarta'));
    $food = Category::query()->create(['name' => 'Makanan']);
    $expense = $this->engine->recordExpense($this->user->id, 10_000, 'Bakso');
    $expense->forceFill(['category_id' => $food->id])->save();
    $this->engine->recordExpense($this->user->id, 20_000, 'Kopi');
    $this->engine->undoLastTransaction($this->user->id);
    $this->engine->transferAmbilDingin($this->user->id, 5_000);

    $recap = $this->reporting->handleRecap($this->user->id);

    expect($recap)->toContain('Jajan/pengeluaran: Rp10.000')
        ->and($recap)->toContain('Total keluar: Rp10.000')
        ->and($recap)->toContain('Makanan Rp10.000 • 100,0%')
        ->and($recap)->not->toContain('Rp20.000');
});

test('recap safely handles a cycle with zero expenses', function (): void {
    $this->engine->processGajian($this->user->id, 400_000, '2026-08-25');
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-08-26 10:00:00', 'Asia/Jakarta'));

    $recap = $this->reporting->handleRecap($this->user->id);

    expect($recap)->toContain('Total keluar: Rp0')
        ->and($recap)->not->toContain('NAN')
        ->and($recap)->not->toContain('INF');
});

test('projection uses fixed overrides and leaves unknown revolving amounts unknown', function (): void {
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-08-24 10:00:00', 'Asia/Jakarta'));
    Installment::query()->create([
        'user_id' => $this->user->id, 'name' => 'Motor', 'jenis' => 'tetap',
        'nominal_default' => 800_000, 'jadwal_khusus' => ['2026-09' => 700_000],
        'sisa_tenor_bulan' => 3, 'active' => true,
    ]);
    Installment::query()->create([
        'user_id' => $this->user->id, 'name' => 'Paylater', 'jenis' => 'revolving',
        'nominal_default' => 0, 'jadwal_khusus' => ['2026-09' => 350_000],
        'sisa_tenor_bulan' => null, 'active' => true,
    ]);

    $projection = $this->reporting->handleProyeksi($this->user->id);

    expect($projection)->toContain('Motor — sisa 3 bulan')
        ->and($projection)->toContain('Lunas sekitar Okt 2026')
        ->and($projection)->toContain('Agu 2026: Rp800.000 + tagihan belum diatur')
        ->and($projection)->toContain('Sep 2026: Rp1.050.000')
        ->and($projection)->toContain('Paylater — tenor tidak terbatas / belum ditentukan')
        ->and($projection)->not->toContain('Paylater — tenor tidak terbatas / belum ditentukan\n  Lunas');
});
