<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->alias([
            'isAdmin' => \App\Http\Middleware\IsAdmin::class,
            'role'    => \App\Http\Middleware\EnsureRole::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->reportable(function (\Throwable $e) {
            \App\Services\LogService::error($e->getMessage(), [
                'file' => $e->getFile(),
                'line' => $e->getLine(),
                'trace' => substr($e->getTraceAsString(), 0, 1000)
            ]);
        });

        // Production safety net: for any unhandled exception that bubbles
        // up to the framework on a JSON/API request, return a generic
        // message instead of leaking the raw exception text (which can
        // include SQL fragments, file paths, or other internals).
        // The original exception is still logged via the reporter above.
        $exceptions->render(function (\Throwable $e, \Illuminate\Http\Request $request) {
            if (config('app.debug')) {
                return null; // let Laravel render the verbose response
            }
            if (!$request->expectsJson() && !$request->is('api/*')) {
                return null;
            }

            // Preserve framework-specific HTTP exceptions that already carry
            // an intentional, user-safe status / message (e.g. 401, 403,
            // 404, 422, 429). Only replace 5xx and unknown errors.
            if ($e instanceof \Symfony\Component\HttpKernel\Exception\HttpExceptionInterface) {
                $status = $e->getStatusCode();
                if ($status < 500) {
                    return null;
                }
            }

            return response()->json([
                'status' => 'error',
                'statusMessage' => 'Terjadi kesalahan pada server. Silakan coba lagi.',
            ], 500);
        });
    })->create();
