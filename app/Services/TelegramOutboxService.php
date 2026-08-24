<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\TelegramOutbox;

final class TelegramOutboxService
{
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
}
