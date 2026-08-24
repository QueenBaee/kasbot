<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TelegramUpdate extends Model
{
    /** @var list<string> */
    protected $fillable = [
        'telegram_update_id',
        'user_id',
        'processed_at',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'telegram_update_id' => 'integer',
            'processed_at' => 'datetime',
        ];
    }
}
