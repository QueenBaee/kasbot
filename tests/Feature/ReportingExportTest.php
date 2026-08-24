<?php

declare(strict_types=1);

use App\Models\Transaction;
use App\Models\User;
use App\Services\ReportingService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Storage::fake('local');
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-08-24 23:05:00', 'Asia/Jakarta'));
    $this->user = User::query()->create(['telegram_user_id' => 5001, 'name' => 'Export User', 'timezone' => 'Asia/Jakarta']);
    $this->otherUser = User::query()->create(['telegram_user_id' => 5002, 'name' => 'Other User', 'timezone' => 'Asia/Jakarta']);
});

afterEach(fn () => CarbonImmutable::setTestNow());

test('export streams complete user audit history and neutralizes spreadsheet formulas', function (): void {
    $original = Transaction::query()->create([
        'user_id' => $this->user->id, 'type' => 'expense', 'amount' => 15_000,
        'description' => '=HYPERLINK("bad")', 'source_wallet' => 'dompet_jajan_aktif', 'status' => 'reversed',
    ]);
    Transaction::query()->create([
        'user_id' => $this->user->id, 'type' => 'reversal', 'amount' => 15_000,
        'description' => 'Pembatalan', 'reference_transaction_id' => $original->id, 'status' => 'success',
    ]);
    Transaction::query()->create([
        'user_id' => $this->otherUser->id, 'type' => 'income', 'amount' => 999_999,
        'description' => 'Private other user row', 'status' => 'success',
    ]);

    $export = app(ReportingService::class)->handleExport($this->user->id);
    $contents = file_get_contents($export->absolutePath);

    expect($export->mimeType)->toBe('text/csv')
        ->and($export->filename)->toStartWith('kasbot-transactions-20260824-230500-')
        ->and($contents)->toStartWith("\xEF\xBB\xBF")
        ->and($contents)->toContain("'=HYPERLINK")
        ->and($contents)->toContain('reversed')
        ->and($contents)->toContain('reversal')
        ->and($contents)->not->toContain('Private other user row')
        ->and(substr_count($contents, "\n"))->toBe(3);
});

test('export crosses chunk boundaries without losing rows', function (): void {
    $now = now();
    $rows = [];
    for ($index = 1; $index <= 510; $index++) {
        $rows[] = [
            'user_id' => $this->user->id, 'type' => 'income', 'amount' => $index,
            'description' => "Income {$index}", 'status' => 'success',
            'created_at' => $now, 'updated_at' => $now,
        ];
    }
    Transaction::query()->insert($rows);

    $export = app(ReportingService::class)->handleExport($this->user->id);
    $contents = file_get_contents($export->absolutePath);

    expect(substr_count($contents, "\n"))->toBe(511)
        ->and($contents)->toContain('Income 510');
});
