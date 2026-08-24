<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class CategoryKeyword extends Model
{
    /** @var list<string> */
    protected $fillable = [
        'category_id',
        'keyword',
    ];

    protected static function booted(): void
    {
        static::saving(function (CategoryKeyword $categoryKeyword): void {
            $categoryKeyword->keyword = Str::of($categoryKeyword->keyword)
                ->trim()
                ->squish()
                ->value();
            $categoryKeyword->normalized_keyword = self::normalizeKeyword(
                $categoryKeyword->keyword
            );
        });
    }

    public static function normalizeKeyword(string $keyword): string
    {
        return Str::of($keyword)
            ->trim()
            ->squish()
            ->lower()
            ->value();
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }
}
