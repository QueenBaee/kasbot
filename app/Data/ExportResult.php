<?php

declare(strict_types=1);

namespace App\Data;

final readonly class ExportResult
{
    public function __construct(
        public string $absolutePath,
        public string $filename,
        public string $mimeType,
    ) {}
}
