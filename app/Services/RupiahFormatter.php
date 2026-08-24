<?php

declare(strict_types=1);

namespace App\Services;

use Illuminate\Support\Str;
use RuntimeException;

final class RupiahFormatter
{
    public function format(int $amount): string
    {
        $value = (string) $amount;
        $isNegative = Str::startsWith($value, '-');
        $digits = $isNegative ? Str::substr($value, 1) : $value;
        $formatted = preg_replace('/\B(?=(\d{3})+(?!\d))/', '.', $digits);

        if (! is_string($formatted)) {
            throw new RuntimeException('Rupiah formatting failed.');
        }

        return ($isNegative ? '-Rp' : 'Rp').$formatted;
    }
}
