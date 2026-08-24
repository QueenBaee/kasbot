<?php

namespace App\Models;

use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable;

    /** @var list<string> */
    protected $fillable = [
        'telegram_user_id',
        'name',
        'timezone',
    ];

    /** @var array<string, mixed> */
    protected $attributes = [
        'timezone' => 'Asia/Jakarta',
    ];

    public function wallet(): HasOne
    {
        return $this->hasOne(Wallet::class);
    }

    public function telegramUpdates(): HasMany
    {
        return $this->hasMany(TelegramUpdate::class);
    }

    public function telegramOutbox(): HasMany
    {
        return $this->hasMany(TelegramOutbox::class);
    }

    public function installments(): HasMany
    {
        return $this->hasMany(Installment::class);
    }

    public function budgetCycles(): HasMany
    {
        return $this->hasMany(BudgetCycle::class);
    }

    public function transactions(): HasMany
    {
        return $this->hasMany(Transaction::class);
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'telegram_user_id' => 'integer',
        ];
    }
}
