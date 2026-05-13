<?php

declare(strict_types=1);

namespace Grav\Plugin\Sync\Http\Exceptions;

if (\class_exists(\Grav\Plugin\Api\Exceptions\UnauthorizedException::class, true)) {
    final class UnauthorizedException extends \Grav\Plugin\Api\Exceptions\UnauthorizedException
    {
    }
} else {
    final class UnauthorizedException extends SyncHttpException
    {
        public function __construct(string $detail = 'Authentication is required.', ?\Throwable $previous = null)
        {
            parent::__construct(401, 'Unauthorized', $detail, ['WWW-Authenticate' => 'Bearer'], $previous);
        }
    }
}
