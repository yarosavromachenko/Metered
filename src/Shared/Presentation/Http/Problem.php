<?php

declare(strict_types=1);

namespace Metered\Shared\Presentation\Http;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * RFC 9457 problem responses. `type` is stable; `detail` may change wording.
 */
final class Problem
{
    private const string BASE_TYPE = 'https://metered.dev/problems/';

    /**
     * Shared with the idempotency middleware so both format the path the same way.
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
