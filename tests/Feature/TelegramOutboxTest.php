<?php

declare(strict_types=1);

use App\Models\TelegramOutbox;
use App\Models\User;
use App\Services\TelegramOutboxService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    CarbonImmutable::setTestNow('2026-08-24 10:00:00');
    config(['services.telegram.bot_token' => 'test-token']);
    Http::preventStrayRequests();
    $this->user = User::query()->create([
        'telegram_user_id' => 9001, 'name' => 'Outbox User', 'timezone' => 'Asia/Jakarta',
    ]);
    $this->outbox = app(TelegramOutboxService::class);
});

afterEach(fn () => CarbonImmutable::setTestNow());

test('duplicate dedupe keys create only one outbox message', function (): void {
    $first = $this->outbox->enqueueMessage($this->user->id, 'same-key', 'First');
    $second = $this->outbox->enqueueMessage($this->user->id, 'same-key', 'Second');

    expect($first->id)->toBe($second->id)
        ->and(TelegramOutbox::query()->count())->toBe(1)
        ->and($second->text)->toBe('First');
});

test('pending message dispatch increments attempts and becomes sent', function (): void {
    Http::fake(['api.telegram.org/*' => Http::response(['ok' => true])]);
    $item = $this->outbox->enqueueMessage($this->user->id, 'message-1', 'Hello');

    $this->artisan('telegram:dispatch-outbox')->assertSuccessful();

    expect($item->refresh()->status)->toBe('sent')
        ->and($item->attempts)->toBe(1)
        ->and($item->sent_at)->not->toBeNull();
});

test('rate limited delivery is retried at telegram retry after', function (): void {
    Http::fake(['api.telegram.org/*' => Http::response([
        'ok' => false, 'parameters' => ['retry_after' => 90],
    ], 429)]);
    $item = $this->outbox->enqueueMessage($this->user->id, 'rate-limit', 'Hello');

    $this->artisan('telegram:dispatch-outbox')->assertSuccessful();

    expect($item->refresh()->status)->toBe('pending')
        ->and($item->attempts)->toBe(1)
        ->and($item->available_at?->timestamp)->toBe(now()->addSeconds(90)->timestamp);
});

test('maximum attempts marks a failed request terminal', function (): void {
    Http::fake(['api.telegram.org/*' => Http::response(['ok' => false], 500)]);
    $item = $this->outbox->enqueueMessage($this->user->id, 'terminal', 'Hello');
    $item->forceFill(['attempts' => 4])->save();

    $this->artisan('telegram:dispatch-outbox')->assertSuccessful();

    expect($item->refresh()->status)->toBe('failed')
        ->and($item->attempts)->toBe(5);
});

test('stale processing rows recover and one bad document does not stop another item', function (): void {
    Storage::fake('local');
    Http::fake(['api.telegram.org/*' => Http::response(['ok' => true])]);
    $bad = $this->outbox->enqueueDocument(
        $this->user->id, 'missing-document', Storage::disk('local')->path('exports/missing.csv'),
        'missing.csv', 'text/csv', 'Ready',
    );
    $good = $this->outbox->enqueueMessage($this->user->id, 'stale-message', 'Hello');
    $good->forceFill(['status' => 'processing', 'locked_at' => now()->subMinutes(11)])->save();

    $this->artisan('telegram:dispatch-outbox')->assertSuccessful();

    expect($bad->refresh()->status)->toBe('failed')
        ->and($good->refresh()->status)->toBe('sent')
        ->and($good->attempts)->toBe(1);
});

test('successful document delivery deletes only the private export file', function (): void {
    Storage::fake('local');
    Storage::disk('local')->put('exports/report.csv', 'content');
    Http::fake(['api.telegram.org/*' => Http::response(['ok' => true])]);
    $item = $this->outbox->enqueueDocument(
        $this->user->id, 'document', Storage::disk('local')->path('exports/report.csv'),
        'report.csv', 'text/csv', 'Ready',
    );

    $this->artisan('telegram:dispatch-outbox')->assertSuccessful();

    expect($item->refresh()->status)->toBe('sent')
        ->and(is_file($item->document_path))->toBeFalse();
});
