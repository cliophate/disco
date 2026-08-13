<?php

use App\Http\Middleware\SecurityHeaders;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withCommands([
        __DIR__.'/../app/Console/Commands',
    ])
    ->withMiddleware(function (Middleware $middleware): void {
        $trustedProxies = array_values(array_filter(array_map(
            'trim',
            explode(',', (string) env('TRUSTED_PROXIES', '127.0.0.1,::1')),
        )));
        $middleware->trustProxies(
            at: $trustedProxies,
            headers: Request::HEADER_X_FORWARDED_FOR
                | Request::HEADER_X_FORWARDED_HOST
                | Request::HEADER_X_FORWARDED_PORT
                | Request::HEADER_X_FORWARDED_PROTO,
        );
        $middleware->redirectGuestsTo(fn (Request $request): ?string => $request->is('api/*', 'auth/*') ? null : '/');
        $middleware->append(SecurityHeaders::class);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*', 'auth/*') || $request->expectsJson(),
        );
        $isJsonEndpoint = fn (Request $request): bool => $request->is('api/*', 'auth/*') || $request->expectsJson();
        $exceptions->render(function (ValidationException $exception, Request $request) use ($isJsonEndpoint) {
            if (! $isJsonEndpoint($request)) {
                return null;
            }

            return response()->json([
                'code' => 'validation_failed',
                'message' => $exception->getMessage(),
                'errors' => $exception->errors(),
            ], $exception->status);
        });
        $exceptions->render(function (AuthenticationException $exception, Request $request) use ($isJsonEndpoint) {
            if (! $isJsonEndpoint($request)) {
                return null;
            }

            return response()->json([
                'code' => 'unauthenticated',
                'message' => 'Authentication is required.',
            ], 401);
        });
        $exceptions->render(function (HttpExceptionInterface $exception, Request $request) use ($isJsonEndpoint) {
            if (! $isJsonEndpoint($request)) {
                return null;
            }

            $status = $exception->getStatusCode();

            return response()->json([
                'code' => match ($status) {
                    404 => 'not_found',
                    409 => 'conflict',
                    419 => 'csrf_token_mismatch',
                    429 => 'rate_limited',
                    default => 'http_error',
                },
                'message' => $exception->getMessage() ?: 'The request could not be completed.',
            ], $status, $exception->getHeaders());
        });
    })->create();
