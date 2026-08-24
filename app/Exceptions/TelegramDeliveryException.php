<?php

declare(strict_types=1);

namespace App\Exceptions;

use RuntimeException;

final class TelegramDeliveryException extends RuntimeException
{
    public function __construct(
        string $message,
        public readonly ?int $httpStatus = null,
        public readonly ?int $retryAfter = null,
        public readonly bool $terminal = false,
    ) {
        parent::__construct($message);
    }
}
