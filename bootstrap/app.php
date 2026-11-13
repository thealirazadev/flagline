<?php

use App\Http\Middleware\AuthenticateSdkKey;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Exceptions\ThrottleRequestsException;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\MethodNotAllowedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->alias(['sdk.key' => AuthenticateSdkKey::class]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // Every /api/* error uses the documented envelope; details are logged, never returned.
        $exceptions->render(function (Throwable $e, Request $request) {
            if (! $request->is('api/*')) {
                return null;
            }

            return match (true) {
                $e instanceof NotFoundHttpException => AuthenticateSdkKey::error(404, 'not_found', 'There is no API route at this path.'),
                $e instanceof MethodNotAllowedHttpException => AuthenticateSdkKey::error(405, 'method_not_allowed', 'The ruleset is read with GET.'),
                $e instanceof ThrottleRequestsException => AuthenticateSdkKey::error(429, 'rate_limited', 'Too many requests for this SDK key. Slow the polling down.')
                    ->withHeaders($e->getHeaders()),
                default => AuthenticateSdkKey::error(500, 'server_error', 'Something went wrong on the flag server.'),
            };
        });
    })->create();
