<?php

declare(strict_types=1);

namespace Grav\Plugin\Sync\Http;

use Grav\Framework\Psr7\Response;
use Psr\Http\Message\ResponseInterface;

/**
 * JSON response builder for sync's HTTP layer.
 *
 * Output is byte-for-byte identical to grav-plugin-api's ApiResponse so
 * existing consumers hitting `/api/v1/sync/*` see no difference after the
 * controllers swap their response builder. Specifically:
 *
 *   - Body wraps the payload as `{"data": <payload>}`.
 *   - Headers include `Content-Type: application/json` (no charset suffix,
 *     to match ApiResponse) and `Cache-Control: no-store, max-age=0`.
 *   - JSON encoded with `JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE`.
 *   - Uses Grav core's PSR-7 Response so the wire bytes match the api
 *     plugin's, which uses the same class.
 */
final class SyncResponse
{
    public static function create(mixed $data, int $status = 200, array $headers = []): ResponseInterface
    {
        $body = ['data' => $data];

        $headers = array_merge($headers, [
            'Content-Type' => 'application/json',
            'Cache-Control' => 'no-store, max-age=0',
        ]);

        return new Response(
            $status,
            $headers,
            json_encode($body, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
        );
    }

    /**
     * RFC 7807 Problem Details error response. Used by the legacy dispatcher
     * to map sync's HTTP exceptions to the same shape api's ErrorResponse
     * produces.
     *
     * @param array<int, array<string, mixed>> $errors Optional per-field validation errors.
     */
    public static function error(int $status, string $title, string $detail, array $headers = [], array $errors = []): ResponseInterface
    {
        $body = [
            'status' => $status,
            'title' => $title,
            'detail' => $detail,
        ];
        if ($errors !== []) {
            $body['errors'] = $errors;
        }

        $headers = array_merge($headers, [
            'Content-Type' => 'application/problem+json',
            'Cache-Control' => 'no-store, max-age=0',
        ]);

        return new Response($status, $headers, json_encode($body, JSON_UNESCAPED_SLASHES));
    }
}
