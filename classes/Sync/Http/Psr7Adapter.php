<?php

declare(strict_types=1);

namespace Grav\Plugin\Sync\Http;

use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7Server\ServerRequestCreator;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * PSR-7 helpers for the legacy (no-api-plugin) dispatch path.
 *
 * Builds a ServerRequest from PHP superglobals on the way in and emits a
 * ResponseInterface back to the wire on the way out. Only used by
 * sync.php's onPageInitialized handler when the api plugin is not
 * available; on the api-plugin path the api router builds the request
 * itself and these helpers are not invoked.
 *
 * Implementation uses nyholm/psr7 + nyholm/psr7-server, which Grav core
 * already ships in its vendor tree (since 1.7), so no extra installation
 * is required at runtime even on a 1.7-only site.
 */
final class Psr7Adapter
{
    public static function fromGlobals(): ServerRequestInterface
    {
        $factory = new Psr17Factory();
        $creator = new ServerRequestCreator($factory, $factory, $factory, $factory);
        return $creator->fromGlobals();
    }

    /**
     * Send the response status, headers, and body to the wire and exit.
     * Mirrors what the api plugin's request bootstrap does for /api/v1/*
     * requests.
     */
    public static function emit(ResponseInterface $response): void
    {
        if (!headers_sent()) {
            $protocol = $response->getProtocolVersion();
            $status = $response->getStatusCode();
            $reason = $response->getReasonPhrase();
            header(sprintf('HTTP/%s %d %s', $protocol, $status, $reason), true, $status);

            foreach ($response->getHeaders() as $name => $values) {
                $first = true;
                foreach ($values as $value) {
                    header($name . ': ' . $value, $first, $status);
                    $first = false;
                }
            }
        }

        $body = $response->getBody();
        if ($body->isSeekable()) {
            $body->rewind();
        }
        while (!$body->eof()) {
            echo $body->read(8192);
        }

        exit;
    }
}
