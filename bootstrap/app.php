<?php

use App\Http\Middleware\EnsureBotAllowed;
use App\Http\Middleware\EnsureRelayKey;
use App\Http\Middleware\IpAllowlist;
use App\Http\Middleware\LogRelayRequest;
use App\Support\TelegramError;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Exceptions\HttpResponseException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        api: __DIR__.'/../routes/api.php',
        apiPrefix: '',
        commands: __DIR__.'/../routes/console.php',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->alias([
            'relay.bot' => EnsureBotAllowed::class,
            'relay.key' => EnsureRelayKey::class,
            'relay.ip' => IpAllowlist::class,
            'relay.log' => LogRelayRequest::class,
        ]);
        $middleware->trustProxies(at: env('TRUSTED_PROXIES', ''));
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->render(function (Throwable $e, $request) {
            if ($e instanceof HttpResponseException) {
                return $e->getResponse();
            }

            if ($e instanceof NotFoundHttpException) {
                return response('', 404);
            }

            return TelegramError::make(500, 'Relay internal error');
        });
    })->create();
