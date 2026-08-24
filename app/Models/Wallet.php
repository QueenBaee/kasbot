<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Wallet extends Model
{
    /** @var list<string> */
    protected $fillable = [
        'user_id',
        'uang_dingin',
        'dompet_jajan_aktif',
    ];

    /** @var array<string, mixed> */
    protected $attributes = [
        'uang_dingin' => 0,
        'dompet_jajan_aktif' => 0,
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'uang_dingin' => 'integer',
            'dompet_jajan_aktif' => 'integer',
        ];
    }
}
