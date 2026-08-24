<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Installment extends Model
{
    /** @var list<string> */
    protected $fillable = [
        'user_id',
        'name',
        'jenis',
        'nominal_default',
        'jadwal_khusus',
        'sisa_tenor_bulan',
        'active',
    ];

    /** @var array<string, mixed> */
    protected $attributes = [
        'nominal_default' => 0,
        'active' => true,
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function transactions(): HasMany
    {
        return $this->hasMany(Transaction::class);
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'nominal_default' => 'integer',
            'jadwal_khusus' => 'array',
            'sisa_tenor_bulan' => 'integer',
            'active' => 'boolean',
        ];
    }
}
