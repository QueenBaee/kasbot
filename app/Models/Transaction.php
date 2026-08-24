<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Transaction extends Model
{
    /** @var list<string> */
    protected $fillable = [
        'user_id',
        'budget_cycle_id',
        'budget_period_id',
        'type',
        'amount',
        'description',
        'category_id',
        'installment_id',
        'source_wallet',
        'target_wallet',
        'reference_transaction_id',
        'status',
    ];

    /** @var array<string, mixed> */
    protected $attributes = [
        'status' => 'success',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function budgetCycle(): BelongsTo
    {
        return $this->belongsTo(BudgetCycle::class);
    }

    public function budgetPeriod(): BelongsTo
    {
        return $this->belongsTo(BudgetPeriod::class);
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }

    public function installment(): BelongsTo
    {
        return $this->belongsTo(Installment::class);
    }

    public function referenceTransaction(): BelongsTo
    {
        return $this->belongsTo(self::class, 'reference_transaction_id');
    }

    public function reversals(): HasMany
    {
        return $this->hasMany(self::class, 'reference_transaction_id');
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'amount' => 'integer',
        ];
    }
}
