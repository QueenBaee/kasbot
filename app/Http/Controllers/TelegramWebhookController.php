<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\TelegramUpdate;
use App\Models\User;
use App\Services\CommandParserService;
use App\Services\TelegramOutboxService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

final class TelegramWebhookController extends Controller
{
    public function __construct(
        private readonly CommandParserService $commandParser,
        private readonly TelegramOutboxService $outbox,
    ) {}

    public function __invoke(Request $request): JsonResponse
    {
        $payload = $request->json()->all();
        $updateId = $payload['update_id'] ?? null;

        if (! is_int($updateId)) {
            Log::warning('Ignoring Telegram webhook with an invalid update ID.');

            return response()->json(['status' => 'ignored']);
        }

        $telegramUserId = $this->extractTelegramUserId($payload);

        if ($telegramUserId === null) {
            Log::warning('Ignoring Telegram webhook without a supported sender.', [
                'update_id' => $updateId,
            ]);

            return response()->json(['status' => 'ignored']);
        }

        $user = User::query()
            ->where('telegram_user_id', $telegramUserId)
            ->first();

        if (! $user instanceof User) {
            Log::warning('Ignoring Telegram webhook from an unregistered sender.', [
                'update_id' => $updateId,
                'telegram_user_id' => $telegramUserId,
            ]);

            return response()->json(['status' => 'ignored']);
        }

        $text = $this->extractMessageText($payload);

        if ($text === null) {
            return response()->json(['status' => 'ignored']);
        }

        try {
            $status = DB::transaction(function () use ($text, $updateId, $user): string {
                $telegramUpdate = TelegramUpdate::query()->createOrFirst(
                    ['telegram_update_id' => $updateId],
                    [
                        'user_id' => $user->getKey(),
                        'processed_at' => now(),
                    ],
                );

                if (! $telegramUpdate->wasRecentlyCreated) {
                    return 'already_processed';
                }

                $result = $this->commandParser->handleIncomingMessage(
                    (int) $user->getKey(),
                    $text,
                );

                $dedupeKey = 'webhook:'.$updateId;
                if ($result->documentPath !== null && $result->documentName !== null && $result->documentMimeType !== null) {
                    $this->outbox->enqueueDocument(
                        (int) $user->getKey(), $dedupeKey, $result->documentPath,
                        $result->documentName, $result->documentMimeType, $result->text,
                    );
                } else {
                    $this->outbox->enqueueMessage(
                        (int) $user->getKey(), $dedupeKey, $result->text, $result->parseMode,
                    );
                }

                return 'processed';
            }, 5);
        } catch (Throwable $exception) {
            Log::error('Telegram webhook command execution failed.', [
                'update_id' => $updateId,
                'user_id' => $user->getKey(),
                'exception_class' => $exception::class,
                'exception' => $exception,
            ]);

            return response()->json(['status' => 'failed']);
        }

        return response()->json(['status' => $status]);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function extractTelegramUserId(array $payload): ?int
    {
        foreach ([
            'message.from.id',
            'edited_message.from.id',
            'callback_query.from.id',
        ] as $path) {
            $telegramUserId = Arr::get($payload, $path);

            if (is_int($telegramUserId)) {
                return $telegramUserId;
            }
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function extractMessageText(array $payload): ?string
    {
        foreach (['message.text', 'edited_message.text'] as $path) {
            $text = Arr::get($payload, $path);

            if (is_string($text) && Str::of($text)->trim()->isNotEmpty()) {
                return $text;
            }
        }

        return null;
    }
}
