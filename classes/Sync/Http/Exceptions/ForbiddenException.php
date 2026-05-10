<?php

declare(strict_types=1);

namespace Grav\Plugin\Sync\Http\Exceptions;

if (\class_exists(\Grav\Plugin\Api\Exceptions\ForbiddenException::class, true)) {
    final class ForbiddenException extends \Grav\Plugin\Api\Exceptions\ForbiddenException
    {
    }
} else {
    final class ForbiddenException extends SyncHttpException
    {
        public function __construct(string $detail = 'You do not have permission to perform this action.', ?\Throwable $previous = null)
        {
            parent::__construct(403, 'Forbidden', $detail, [], $previous);
        }
    }
}
