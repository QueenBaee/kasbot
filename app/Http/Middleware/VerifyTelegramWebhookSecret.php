<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class VerifyTelegramWebhookSecret
{
    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $expectedSecret = config('services.telegram.webhook_secret');
        $providedSecret = $request->header('X-Telegram-Bot-Api-Secret-Token');

        if (
            ! is_string($expectedSecret)
            || $expectedSecret === ''
            || ! is_string($providedSecret)
            || $providedSecret === ''
            || ! hash_equals($expectedSecret, $providedSecret)
        ) {
            abort(Response::HTTP_FORBIDDEN);
        }

        return $next($request);
    }
}
