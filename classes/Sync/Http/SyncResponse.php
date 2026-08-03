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
 *   - JSON encoded with `JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE`,
 *     plus `JSON_INVALID_UTF8_SUBSTITUTE` (see json() below) to match the
 *     same flag on ApiResponse.
 *   - Uses Grav core's PSR-7 Response so the wire bytes match the api
 *     plugin's, which uses the same class.
 */
final class SyncResponse
{
    /**
     * Encode a response body without ever handing a `false` to the body.
     *
     * json_encode() returns false on malformed UTF-8 and the PSR-7 stream
     * type-hints a string, so the false surfaced as an unhandled TypeError
     * instead of a response. JSON_INVALID_UTF8_SUBSTITUTE swaps the bad bytes
     * for U+FFFD. Mirrors ApiResponse/ErrorResponse in grav-plugin-api, which
     * this class is contractually byte-identical to.
     *
     * @param array<string,mixed> $headers
     * @param array<string,mixed> $body
     * @param bool $downgradeOnFailure Whether a hard encoding failure (recursion,
     *   INF/NAN) should become a 500. False keeps the caller's status, which is
     *   what the error builder wants: the status is the part a client acts on
     *   and it is already known good, so only the body degrades.
     */
    private static function json(int $status, array $headers, array $body, bool $downgradeOnFailure): ResponseInterface
    {
        $flags = JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE;

        $json = json_encode($body, $flags);

        if ($json === false) {
            $reason = json_last_error_msg();

            if ($downgradeOnFailure) {
                $status = 500;
            }

            $json = json_encode([
                'status' => $status,
                'title' => $body['title'] ?? 'Error',
                'detail' => 'The response could not be encoded as JSON: ' . $reason,
            ], $flags) ?: '{"status":' . $status . ',"title":"Error","detail":"The response could not be encoded as JSON."}';
        }

        return new Response($status, $headers, $json);
    }

    public static function create(mixed $data, int $status = 200, array $headers = []): ResponseInterface
    {
        $body = ['data' => $data];

        $headers = array_merge($headers, [
            'Content-Type' => 'application/json',
            'Cache-Control' => 'no-store, max-age=0',
        ]);

        return self::json($status, $headers, $body, true);
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

        return self::json($status, $headers, $body, false);
    }
}
