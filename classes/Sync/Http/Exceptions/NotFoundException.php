<?php

declare(strict_types=1);

namespace Grav\Plugin\Sync\Http\Exceptions;

if (\class_exists(\Grav\Plugin\Api\Exceptions\NotFoundException::class, true)) {
    final class NotFoundException extends \Grav\Plugin\Api\Exceptions\NotFoundException
    {
    }
} else {
    final class NotFoundException extends SyncHttpException
    {
        public function __construct(string $detail = 'The requested resource was not found.', ?\Throwable $previous = null)
        {
            parent::__construct(404, 'Not Found', $detail, [], $previous);
        }
    }
}
