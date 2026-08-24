<?php

declare(strict_types=1);

namespace App\Services;

use App\Exceptions\TelegramDeliveryException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Throwable;

final class TelegramBotService
{
    public function sendMessage(int $chatId, string $text, ?string $parseMode = null): void
    {
        $payload = ['chat_id' => $chatId, 'text' => $text];
        if ($parseMode !== null) {
            $payload['parse_mode'] = $parseMode;
        }

        $this->validate($this->post('sendMessage', $payload));
    }

    public function sendDocument(int $chatId, string $absolutePath, string $filename, ?string $caption = null): void
    {
        if (! is_file($absolutePath) || ! is_readable($absolutePath)) {
            throw new TelegramDeliveryException('Telegram document is missing or unreadable.', terminal: true);
        }

        $contents = fopen($absolutePath, 'rb');
        if ($contents === false) {
            throw new TelegramDeliveryException('Telegram document could not be opened.', terminal: true);
        }

        try {
            $request = $this->request()->attach('document', $contents, $filename);
            $payload = ['chat_id' => $chatId];
            if ($caption !== null && $caption !== '') {
                $payload['caption'] = $caption;
            }
            $this->validate($request->post($this->endpoint('sendDocument'), $payload));
        } catch (ConnectionException) {
            throw new TelegramDeliveryException('Telegram connection failed.');
        } catch (TelegramDeliveryException $exception) {
            throw $exception;
        } catch (Throwable) {
            throw new TelegramDeliveryException('Telegram document delivery failed.');
        } finally {
            fclose($contents);
        }
    }

    /** @param array<string, mixed> $payload */
    private function post(string $method, array $payload): Response
    {
        try {
            return $this->request()->post($this->endpoint($method), $payload);
        } catch (ConnectionException) {
            throw new TelegramDeliveryException('Telegram connection failed.');
        } catch (TelegramDeliveryException $exception) {
            throw $exception;
        } catch (Throwable) {
            throw new TelegramDeliveryException('Telegram delivery failed.');
        }
    }

    private function request(): PendingRequest
    {
        return Http::connectTimeout(3)->timeout(10)->acceptJson();
    }

    private function endpoint(string $method): string
    {
        $token = config('services.telegram.bot_token');
        if (! is_string($token) || $token === '') {
            throw new TelegramDeliveryException('Telegram bot token is not configured.', terminal: true);
        }

        return "https://api.telegram.org/bot{$token}/{$method}";
    }

    private function validate(Response $response): void
    {
        if ($response->successful() && $response->json('ok') === true) {
            return;
        }

        $retryAfter = $response->json('parameters.retry_after');
        throw new TelegramDeliveryException(
            'Telegram rejected the delivery.',
            $response->status(),
            is_int($retryAfter) && $retryAfter > 0 ? $retryAfter : null,
        );
    }
}
