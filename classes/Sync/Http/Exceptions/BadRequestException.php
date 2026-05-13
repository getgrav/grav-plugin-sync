<?php

declare(strict_types=1);

namespace Grav\Plugin\Sync\Http\Exceptions;

/**
 * 400 Bad Request. The api plugin doesn't ship its own BadRequestException,
 * so on the api-plugin path we synthesize one by extending api's
 * ApiException directly with a 400 status; the api router's
 * `catch (ApiException $e)` branch then catches it normally.
 */
if (\class_exists(\Grav\Plugin\Api\Exceptions\ApiException::class, true)) {
    final class BadRequestException extends \Grav\Plugin\Api\Exceptions\ApiException
    {
        public function __construct(string $detail = 'The request is malformed.', ?\Throwable $previous = null)
        {
            parent::__construct(400, 'Bad Request', $detail, [], $previous);
        }
    }
} else {
    final class BadRequestException extends SyncHttpException
    {
        public function __construct(string $detail = 'The request is malformed.', ?\Throwable $previous = null)
        {
            parent::__construct(400, 'Bad Request', $detail, [], $previous);
        }
    }
}
