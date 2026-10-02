<?php

declare(strict_types=1);

namespace Metered\Shared\Presentation\Http;

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Metered\Shared\Application\Exception\Conflict;
use Metered\Shared\Application\Exception\NotFound;
use Metered\Shared\Domain\Access\PermissionDenied;
use Metered\Shared\Domain\Exception\DomainException;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Throwable;

/**
 * Renders every exception, framework ones included, as RFC 9457. Internal
 * exception messages appear in `detail` only in debug mode.
 */
final class ProblemRenderer
{
    public static function register(Exceptions $exceptions): void
    {
        $exceptions->shouldRenderJsonWhen(
            static fn(Request $request): bool => $request->is('api/*') || $request->expectsJson(),
        );

        $exceptions->render(
            static fn(Throwable $e, Request $request): ?Response => self::render($e, $request),
        );
    }

    private static function render(Throwable $e, Request $request): ?Response
    {
        if (! $request->is('api/*') && ! $request->expectsJson()) {
            return null;
        }

        $instance = Problem::instanceFor($request);

        return match (true) {
            $e instanceof ValidationException => Problem::response(
                'validation-failed',
                'Validation failed',
                422,
                'The request body did not pass validation.',
                $instance,
                ['errors' => self::pointers($e)],
            ),
            $e instanceof AuthenticationException => Problem::response(
                'unauthenticated',
                'Unauthenticated',
                401,
                'This endpoint requires a valid API key.',
                $instance,
            ),
            $e instanceof AuthorizationException => Problem::response(
                'forbidden',
                'Forbidden',
                403,
                'The credentials used are not allowed to perform this action.',
                $instance,
            ),
            // These messages are written for the caller and are shown.
            $e instanceof NotFound => Problem::response('not-found', 'Not found', 404, $e->getMessage(), $instance),
            $e instanceof Conflict => Problem::response('conflict', 'Conflict', 409, $e->getMessage(), $instance),
            $e instanceof DomainException => Problem::response(
                'rule-violated',
                'Rule violated',
                422,
                $e->getMessage(),
                $instance,
            ),
            $e instanceof PermissionDenied => Problem::response(
                'forbidden',
                'Forbidden',
                403,
                'The credentials used are not allowed to perform this action.',
                $instance,
            ),
            $e instanceof ModelNotFoundException => Problem::response(
                'not-found',
                'Not found',
                404,
                'No such resource exists in this project.',
                $instance,
            ),
            $e instanceof HttpExceptionInterface => Problem::response(
                self::slugFor($e->getStatusCode()),
                self::titleFor($e->getStatusCode()),
                $e->getStatusCode(),
                self::detailFor($e),
                $instance,
            ),
            default => Problem::response(
                'internal-error',
                'Internal server error',
                500,
                self::safeDetail($e),
                $instance,
            ),
        };
    }

    /**
     * @return list<array{pointer: string, detail: string}>
     */
    private static function pointers(ValidationException $e): array
    {
        $pointers = [];

        foreach ($e->errors() as $field => $messages) {
            if (! is_iterable($messages)) {
                continue;
            }

            foreach ($messages as $message) {
                if (! is_string($message)) {
                    continue;
                }

                $pointers[] = [
                    'pointer' => '/' . str_replace('.', '/', (string) $field),
                    'detail' => $message,
                ];
            }
        }

        return $pointers;
    }

    private static function detailFor(HttpExceptionInterface&Throwable $e): string
    {
        if ($e->getMessage() !== '') {
            return $e->getMessage();
        }

        return match ($e->getStatusCode()) {
            404 => 'No such resource exists in this project.',
            405 => 'That method is not allowed on this endpoint.',
            429 => 'Too many requests. Slow down and retry after the indicated delay.',
            503 => 'The service is shedding load. Retry after the indicated delay.',
            default => 'The request could not be completed.',
        };
    }

    private static function safeDetail(Throwable $e): string
    {
        // May contain a query or a secret: debug mode only.
        return config('app.debug') === true
            ? $e->getMessage()
            : 'Something went wrong on our side. The failure has been logged.';
    }

    private static function slugFor(int $status): string
    {
        return match ($status) {
            400 => 'bad-request',
            401 => 'unauthenticated',
            403 => 'forbidden',
            404 => 'not-found',
            405 => 'method-not-allowed',
            409 => 'conflict',
            413 => 'payload-too-large',
            422 => 'validation-failed',
            429 => 'rate-limited',
            503 => 'service-unavailable',
            default => 'http-error',
        };
    }

    private static function titleFor(int $status): string
    {
        return match ($status) {
            400 => 'Bad request',
            401 => 'Unauthenticated',
            403 => 'Forbidden',
            404 => 'Not found',
            405 => 'Method not allowed',
            409 => 'Conflict',
            413 => 'Payload too large',
            422 => 'Validation failed',
            429 => 'Too many requests',
            503 => 'Service unavailable',
            default => 'Request failed',
        };
    }
}
