<?php

declare(strict_types=1);

namespace Grav\Plugin\Sync\Http\Exceptions;

/**
 * Base exception for sync's HTTP layer.
 *
 * When the api plugin is present, this extends api's ApiException so the
 * api router's `catch (ApiException $e)` path picks it up unchanged. When
 * the api plugin is absent (Grav 1.7 or 2.0 without api), it extends
 * RuntimeException directly. The legacy dispatcher in sync.php catches
 * SyncHttpException and maps it to an error response itself.
 *
 * The visible interface (getStatusCode / getErrorTitle / getHeaders) is
 * the same in both branches so callers don't have to care which parent
 * is active.
 */
if (\class_exists(\Grav\Plugin\Api\Exceptions\ApiException::class, true)) {
    abstract class SyncHttpException extends \Grav\Plugin\Api\Exceptions\ApiException
    {
        public function __construct(
            int $statusCode,
            string $errorTitle,
            string $detail = '',
            array $headers = [],
            ?\Throwable $previous = null,
        ) {
            parent::__construct($statusCode, $errorTitle, $detail, $headers, $previous);
        }
    }
} else {
    abstract class SyncHttpException extends \RuntimeException
    {
        public function __construct(
            protected readonly int $statusCode,
            protected readonly string $errorTitle,
            string $detail = '',
            protected readonly array $headers = [],
            ?\Throwable $previous = null,
        ) {
            parent::__construct($detail, $statusCode, $previous);
        }

        public function getStatusCode(): int
        {
            return $this->statusCode;
        }

        public function getErrorTitle(): string
        {
            return $this->errorTitle;
        }

        public function getHeaders(): array
        {
            return $this->headers;
        }
    }
}
