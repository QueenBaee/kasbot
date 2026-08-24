<?php

declare(strict_types=1);

namespace App\Data;

final readonly class CommandResult
{
    public function __construct(
        public string $text,
        public ?string $parseMode = null,
        public ?string $documentPath = null,
        public ?string $documentName = null,
        public ?string $documentMimeType = null,
    ) {}
}
