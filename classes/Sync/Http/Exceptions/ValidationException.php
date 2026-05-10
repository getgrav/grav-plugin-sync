<?php

declare(strict_types=1);

namespace Grav\Plugin\Sync\Http\Exceptions;

/**
 * 422 Unprocessable Entity. Carries an optional list of per-field error
 * objects shaped like the api plugin's ValidationException output:
 *   [['field' => 'foo', 'message' => "The 'foo' field is required."], ...]
 *
 * When the api plugin is loaded this extends api's ValidationException so
 * api's ErrorResponse::fromException() picks up the errors array via its
 * `instanceof \Grav\Plugin\Api\Exceptions\ValidationException` branch.
 * Otherwise it extends our local SyncHttpException base.
 */
if (\class_exists(\Grav\Plugin\Api\Exceptions\ValidationException::class, true)) {
    final class ValidationException extends \Grav\Plugin\Api\Exceptions\ValidationException
    {
        public function __construct(
            string $detail = 'The request data is invalid.',
            array $errors = [],
            ?\Throwable $previous = null,
        ) {
            parent::__construct($detail, $errors, $previous);
        }
    }
} else {
    final class ValidationException extends SyncHttpException
    {
        public function __construct(
            string $detail = 'The request data is invalid.',
            private readonly array $errors = [],
            ?\Throwable $previous = null,
        ) {
            parent::__construct(422, 'Unprocessable Entity', $detail, [], $previous);
        }

        public function getValidationErrors(): array
        {
            return $this->errors;
        }
    }
}
