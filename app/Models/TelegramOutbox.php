<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class TelegramOutbox extends Model
{
    protected $table = 'telegram_outbox';

    /** @var list<string> */
    protected $fillable = [
        'user_id', 'dedupe_key', 'type', 'text', 'parse_mode', 'document_path',
        'document_name', 'document_mime_type', 'status', 'attempts', 'available_at',
        'locked_at', 'sent_at', 'last_error',
    ];

    /** @var array<string, mixed> */
    protected $attributes = ['status' => 'pending', 'attempts' => 0];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'attempts' => 'integer',
            'available_at' => 'datetime',
            'locked_at' => 'datetime',
            'sent_at' => 'datetime',
        ];
    }
}
