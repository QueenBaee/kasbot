<?php

declare(strict_types=1);

namespace App\Services;

use App\Exceptions\TelegramDeliveryException;
use App\Models\TelegramOutbox;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Throwable;

final class TelegramOutboxService
{
    private const MAX_ATTEMPTS = 5;

    public function __construct(private readonly TelegramBotService $telegram) {}

    public function enqueueMessage(int $userId, string $dedupeKey, string $text, ?string $parseMode = null): TelegramOutbox
    {
        return TelegramOutbox::query()->createOrFirst(
            ['dedupe_key' => $dedupeKey],
            ['user_id' => $userId, 'type' => 'message', 'text' => $text, 'parse_mode' => $parseMode],
        );
    }

    public function enqueueDocument(
        int $userId,
        string $dedupeKey,
        string $absolutePath,
        string $filename,
        string $mimeType,
        ?string $caption = null,
    ): TelegramOutbox {
        return TelegramOutbox::query()->createOrFirst(
            ['dedupe_key' => $dedupeKey],
            [
                'user_id' => $userId, 'type' => 'document', 'text' => $caption,
                'document_path' => $absolutePath, 'document_name' => $filename,
                'document_mime_type' => $mimeType,
            ],
        );
    }

    public function dispatchPending(int $limit = 100): int
    {
        TelegramOutbox::query()->where('status', 'processing')
            ->where('locked_at', '<=', now()->subMinutes(10))
            ->update(['status' => 'pending', 'locked_at' => null]);

        $processedCount = 0;

        for ($processed = 0; $processed < $limit; $processed++) {
            $item = $this->claimNext();
            if (! $item instanceof TelegramOutbox) {
                break;
            }
            $this->deliver($item);
            $processedCount++;
        }

        return $processedCount;
    }

    private function claimNext(): ?TelegramOutbox
    {
        return DB::transaction(function (): ?TelegramOutbox {
            $item = TelegramOutbox::query()->where('status', 'pending')
                ->where(fn ($query) => $query->whereNull('available_at')->orWhere('available_at', '<=', now()))
                ->orderBy('id')->lockForUpdate()->first();

            if (! $item instanceof TelegramOutbox) {
                return null;
            }

            $item->forceFill([
                'status' => 'processing', 'attempts' => $item->attempts + 1,
                'locked_at' => now(), 'last_error' => null,
            ])->save();

            return $item->refresh();
        }, 3);
    }

    private function deliver(TelegramOutbox $item): void
    {
        try {
            $user = $item->user()->first();
            if (! $user instanceof User) {
                throw new TelegramDeliveryException("User not found for user_id: {$item->user_id}", terminal: true);
            }

            if (empty($user->telegram_user_id)) {
                throw new TelegramDeliveryException("Telegram user ID is not configured for user_id: {$item->user_id}", terminal: true);
            }

            $chatId = $user->telegram_user_id;

            if ($item->type === 'document') {
                $this->telegram->sendDocument(
                    $chatId, (string) $item->document_path,
                    (string) $item->document_name, $item->text,
                );
            } else {
                $this->telegram->sendMessage($chatId, (string) $item->text, $item->parse_mode);
            }

            $item->forceFill([
                'status' => 'sent', 'sent_at' => now(), 'locked_at' => null,
                'available_at' => null, 'last_error' => null,
            ])->save();

            if ($item->type === 'document') {
                try {
                    $this->deleteExportSafely((string) $item->document_path);
                } catch (Throwable $exception) {
                    Log::warning('Sent Telegram export could not be deleted.', [
                        'outbox_id' => $item->id,
                        'user_id' => $item->user_id,
                        'exception_class' => $exception::class,
                    ]);
                }
            }
        } catch (Throwable $exception) {
            $this->markFailure($item, $exception);
        }
    }

    private function markFailure(TelegramOutbox $item, Throwable $exception): void
    {
        $terminal = $item->attempts >= self::MAX_ATTEMPTS
            || ($exception instanceof TelegramDeliveryException && $exception->terminal);
        $retryAfter = $exception instanceof TelegramDeliveryException ? $exception->retryAfter : null;
        $delay = $retryAfter ?? min(300, 15 * (2 ** max(0, $item->attempts - 1)));

        $item->forceFill([
            'status' => $terminal ? 'failed' : 'pending',
            'available_at' => $terminal ? null : now()->addSeconds($delay),
            'locked_at' => null,
            'last_error' => mb_substr($exception->getMessage(), 0, 1000),
        ])->save();

        Log::warning('Telegram outbox delivery failed.', [
            'outbox_id' => $item->id, 'user_id' => $item->user_id,
            'attempt' => $item->attempts,
            'http_status' => $exception instanceof TelegramDeliveryException ? $exception->httpStatus : null,
            'exception_class' => $exception::class,
        ]);
    }

    private function deleteExportSafely(string $path): void
    {
        $exportRoot = realpath(Storage::disk('local')->path('exports'));
        $resolvedPath = realpath($path);

        if ($exportRoot === false || $resolvedPath === false) {
            return;
        }

        $prefix = rtrim($exportRoot, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR;
        if (str_starts_with($resolvedPath, $prefix) && is_file($resolvedPath)) {
            if (! unlink($resolvedPath)) {
                throw new RuntimeException('Export cleanup failed.');
            }
        }
    }
}
