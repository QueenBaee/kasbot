<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class BudgetPeriod extends Model
{
    /** @var list<string> */
    protected $fillable = [
        'budget_cycle_id',
        'period_number',
        'start_date',
        'end_date',
        'allocated_amount',
        'carry_over_amount',
        'total_budget',
        'spent_amount',
        'remaining_amount',
        'status',
    ];

    /** @var array<string, mixed> */
    protected $attributes = [
        'carry_over_amount' => 0,
        'spent_amount' => 0,
        'status' => 'active',
    ];

    public function budgetCycle(): BelongsTo
    {
        return $this->belongsTo(BudgetCycle::class);
    }

    public function transactions(): HasMany
    {
        return $this->hasMany(Transaction::class);
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'period_number' => 'integer',
            'start_date' => 'date',
            'end_date' => 'date',
            'allocated_amount' => 'integer',
            'carry_over_amount' => 'integer',
            'total_budget' => 'integer',
            'spent_amount' => 'integer',
            'remaining_amount' => 'integer',
        ];
    }
}
