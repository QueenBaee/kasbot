<?php

declare(strict_types=1);

use App\Exceptions\TelegramDeliveryException;
use App\Services\TelegramBotService;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

beforeEach(function (): void {
    config(['services.telegram.bot_token' => 'test-token']);
    Http::preventStrayRequests();
});

test('send message validates telegram response and includes explicit parse mode', function (): void {
    Http::fake(['api.telegram.org/*' => Http::response(['ok' => true, 'result' => []])]);

    app(TelegramBotService::class)->sendMessage(12345, 'Hello', 'HTML');

    Http::assertSent(fn (Request $request): bool => $request->url() === 'https://api.telegram.org/bottest-token/sendMessage'
        && $request['chat_id'] === 12345 && $request['text'] === 'Hello' && $request['parse_mode'] === 'HTML');
});

test('send document uses multipart and requires a readable private file', function (): void {
    Storage::fake('local');
    Storage::disk('local')->put('exports/report.csv', 'content');
    Http::fake(['api.telegram.org/*' => Http::response(['ok' => true])]);

    app(TelegramBotService::class)->sendDocument(
        12345, Storage::disk('local')->path('exports/report.csv'), 'report.csv', 'Ready',
    );

    Http::assertSent(fn (Request $request): bool => $request->url() === 'https://api.telegram.org/bottest-token/sendDocument'
        && $request->isMultipart());
});

test('telegram ok false and retry after become a sanitized delivery failure', function (): void {
    Http::fake(['api.telegram.org/*' => Http::response([
        'ok' => false, 'parameters' => ['retry_after' => 60],
    ], 429)]);

    try {
        app(TelegramBotService::class)->sendMessage(12345, 'Hello');
        $this->fail('Expected Telegram delivery failure.');
    } catch (TelegramDeliveryException $exception) {
        expect($exception->httpStatus)->toBe(429)
            ->and($exception->retryAfter)->toBe(60)
            ->and($exception->getMessage())->not->toContain('test-token');
    }
});
