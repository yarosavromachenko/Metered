<?php

declare(strict_types=1);

namespace Metered\Shared\Presentation\Http;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * RFC 9457 problem responses.
 *
 * Every failure this API produces looks the same, which is the point: a client
 * writes one error handler rather than one per endpoint, and the `type` is a
 * stable identifier it can branch on while `detail` stays free to be reworded.
 */
final class Problem
{
    private const string BASE_TYPE = 'https://metered.dev/problems/';

    /**
     * The path a problem refers to, in one form.
     *
     * Two places build problem documents — this one and the idempotency
     * middleware — and they disagreed about the leading slash until a test
     * compared them.
     */
    public static function instanceFor(Request $request): string
    {
        return '/' . ltrim($request->path(), '/');
    }

    /**
     * @param  array<string, mixed>  $extensions
     */
    public static function response(
        string $type,
        string $title,
        int $status,
        string $detail,
        string $instance,
        array $extensions = [],
    ): JsonResponse {
        return new JsonResponse(
            [
                'type' => self::BASE_TYPE . $type,
                'title' => $title,
                'status' => $status,
                'detail' => $detail,
                'instance' => $instance,
                ...$extensions,
            ],
            $status,
            ['Content-Type' => 'application/problem+json'],
        );
    }
}
