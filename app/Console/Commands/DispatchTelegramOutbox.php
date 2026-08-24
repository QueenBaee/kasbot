<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Exceptions\TelegramDeliveryException;
use App\Models\TelegramOutbox;
use App\Services\TelegramBotService;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Throwable;

#[Signature('telegram:dispatch-outbox')]
#[Description('Dispatch pending Telegram outbox deliveries')]
final class DispatchTelegramOutbox extends Command
{
    private const MAX_ATTEMPTS = 5;

    public function __construct(private readonly TelegramBotService $telegram)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        TelegramOutbox::query()->where('status', 'processing')
            ->where('locked_at', '<=', now()->subMinutes(10))
            ->update(['status' => 'pending', 'locked_at' => null]);

        for ($processed = 0; $processed < 100; $processed++) {
            $item = $this->claimNext();
            if (! $item instanceof TelegramOutbox) {
                break;
            }
            $this->deliver($item);
        }

        return self::SUCCESS;
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
            if ($user === null) {
                throw new TelegramDeliveryException('Outbox user is missing.');
            }

            if ($item->type === 'document') {
                $this->telegram->sendDocument(
                    $user->telegram_user_id, (string) $item->document_path,
                    (string) $item->document_name, $item->text,
                );
            } else {
                $this->telegram->sendMessage($user->telegram_user_id, (string) $item->text, $item->parse_mode);
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
                throw new \RuntimeException('Export cleanup failed.');
            }
        }
    }
}
