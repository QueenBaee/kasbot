<?php

declare(strict_types=1);

use App\Models\TelegramOutbox;
use App\Models\TelegramUpdate;
use App\Models\Transaction;
use App\Models\User;
use App\Services\FinancialEngineService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-08-25 10:00:00', 'Asia/Jakarta'));
    config(['services.telegram.webhook_secret' => 'test-webhook-secret']);
    Http::preventStrayRequests();
    $this->user = User::query()->create([
        'telegram_user_id' => random_int(1, PHP_INT_MAX),
        'name' => 'Webhook User',
        'timezone' => 'Asia/Jakarta',
    ]);
});

afterEach(fn () => CarbonImmutable::setTestNow());

test('duplicate webhook delivery cannot create a second expense', function (): void {
    app(FinancialEngineService::class)->processGajian($this->user->id, 400_000, '2026-08-25');
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-08-26 10:00:00', 'Asia/Jakarta'));
    $payload = [
        'update_id' => 123456,
        'message' => [
            'from' => ['id' => $this->user->telegram_user_id],
            'text' => '15000 bakso',
        ],
    ];
    $headers = ['X-Telegram-Bot-Api-Secret-Token' => 'test-webhook-secret'];

    $this->postJson('/api/telegram/webhook', $payload, $headers)
        ->assertOk()->assertJson(['status' => 'processed']);
    $this->postJson('/api/telegram/webhook', $payload, $headers)
        ->assertOk()->assertJson(['status' => 'already_processed']);

    expect(Transaction::query()->where('type', 'expense')->count())->toBe(1)
        ->and(TelegramUpdate::query()->where('telegram_update_id', 123456)->count())->toBe(1)
        ->and(TelegramOutbox::query()->count())->toBe(1)
        ->and(TelegramOutbox::query()->sole()->type)->toBe('message')
        ->and(TelegramOutbox::query()->sole()->dedupe_key)->toBe('webhook:123456');
});

test('unexpected webhook failure rolls back the idempotency marker', function (): void {
    $this->user->forceFill(['timezone' => 'Not/A-Timezone'])->save();
    $payload = [
        'update_id' => 654321,
        'message' => [
            'from' => ['id' => $this->user->telegram_user_id],
            'text' => '/gajian 100000',
        ],
    ];

    $this->postJson('/api/telegram/webhook', $payload, [
        'X-Telegram-Bot-Api-Secret-Token' => 'test-webhook-secret',
    ])->assertOk()->assertJson(['status' => 'failed']);

    expect(TelegramUpdate::query()->where('telegram_update_id', 654321)->exists())->toBeFalse()
        ->and(TelegramOutbox::query()->count())->toBe(0);
});

test('export webhook atomically creates a document outbox without telegram http', function (): void {
    Storage::fake('local');
    $payload = [
        'update_id' => 777888,
        'message' => [
            'from' => ['id' => $this->user->telegram_user_id],
            'text' => '/export',
        ],
    ];

    $this->postJson('/api/telegram/webhook', $payload, [
        'X-Telegram-Bot-Api-Secret-Token' => 'test-webhook-secret',
    ])->assertOk()->assertJson(['status' => 'processed']);

    $outbox = TelegramOutbox::query()->sole();
    expect($outbox->type)->toBe('document')
        ->and($outbox->dedupe_key)->toBe('webhook:777888')
        ->and($outbox->document_name)->toEndWith('.csv')
        ->and($outbox->document_mime_type)->toBe('text/csv')
        ->and(is_file($outbox->document_path))->toBeTrue();
    Http::assertNothingSent();
});
