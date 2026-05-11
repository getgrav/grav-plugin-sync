<?php

declare(strict_types=1);

namespace Grav\Plugin\Sync\Http;

use Grav\Common\Grav;
use Grav\Common\User\Interfaces\UserInterface;
use Grav\Plugin\Sync\Controllers\SyncController;
use Grav\Plugin\Sync\Http\Exceptions\SyncHttpException;
use Grav\Plugin\Sync\Http\Exceptions\ValidationException;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Tiny URI -> SyncController dispatcher used when the api plugin is not
 * present (Grav 1.7, or 2.0 without api). Matches `/sync/*` paths
 * directly, captures `{route}` segments, decodes the JSON body, seeds
 * the request `api_user` attribute from the active session user, and
 * invokes the corresponding SyncController action.
 *
 * Routes intentionally mirror what onApiRegisterRoutes wires up under
 * `/api/v1` so the on-disk room ids and event payloads stay identical
 * regardless of which entry path served the request.
 */
final class SyncLegacyRouter
{
    /** @var list<array{method: string, regex: string, action: string, paramNames: list<string>}> */
    private array $routes;

    public function __construct(private readonly Grav $grav)
    {
        $this->routes = [
            $this->compile('GET',  '/sync/capabilities',                   'capabilities',   []),
            $this->compile('POST', '/sync/pages/{route}/init',             'init',           ['route']),
            $this->compile('POST', '/sync/pages/{route}/pull',             'pull',           ['route']),
            $this->compile('POST', '/sync/pages/{route}/push',             'push',           ['route']),
            $this->compile('POST', '/sync/pages/{route}/presence',         'presence',       ['route']),
            // Channel-scoped pub/sub endpoints. The channel id rides in
            // the query string (`?id=...`) because Grav's URI parser
            // strips `:`-bearing path segments as URI params and channel
            // ids legitimately contain colons.
            $this->compile('GET',  '/sync/channels/pull',                  'channelPull',    []),
            $this->compile('POST', '/sync/channels/publish',               'channelPublish', []),
        ];
    }

    /**
     * Try to match the incoming request against the legacy `/sync/*`
     * route table. Returns true iff the request was handled (response
     * emitted, script exits inside emit()).
     */
    public function tryHandle(string $path, string $method): bool
    {
        $match = null;
        $captured = [];
        foreach ($this->routes as $route) {
            if (preg_match($route['regex'], $path, $m)) {
                if ($route['method'] !== $method) {
                    // Path matches but method differs — emit 405 with
                    // the allowed methods for this exact path.
                    $allowed = [];
                    foreach ($this->routes as $r2) {
                        if (preg_match($r2['regex'], $path)) {
                            $allowed[] = $r2['method'];
                        }
                    }
                    $response = SyncResponse::error(
                        405,
                        'Method Not Allowed',
                        "Method '{$method}' is not allowed. Allowed: " . implode(', ', array_unique($allowed)) . '.',
                        ['Allow' => implode(', ', array_unique($allowed))]
                    );
                    Psr7Adapter::emit($response);
                    return true;
                }
                $match = $route;
                foreach ($route['paramNames'] as $name) {
                    $captured[$name] = isset($m[$name]) ? rawurldecode((string) $m[$name]) : null;
                }
                break;
            }
        }

        if ($match === null) {
            return false;
        }

        try {
            $request = Psr7Adapter::fromGlobals();
            $request = $request->withAttribute('route_params', $captured);
            $request = $this->seedAuthAttribute($request);
            $request = $this->seedJsonBody($request);

            $controller = new SyncController($this->grav, null);
            /** @var ResponseInterface $response */
            $response = $controller->{$match['action']}($request);
        } catch (SyncHttpException $e) {
            $errors = [];
            if ($e instanceof ValidationException) {
                $errors = $e->getValidationErrors();
            }
            $response = SyncResponse::error(
                $e->getStatusCode(),
                $e->getErrorTitle(),
                $e->getMessage(),
                $e->getHeaders(),
                $errors
            );
        } catch (\Throwable $e) {
            $this->grav['log']->error('sync legacy dispatch error: ' . $e->getMessage(), [
                'file' => $e->getFile(),
                'line' => $e->getLine(),
            ]);
            $response = SyncResponse::error(
                500,
                'Internal Server Error',
                $this->grav['config']->get('system.debugger.enabled')
                    ? $e->getMessage()
                    : 'An unexpected error occurred.'
            );
        }

        Psr7Adapter::emit($response);
        return true;
    }

    /**
     * Seed the request `api_user` attribute that AbstractSyncController
     * reads in getUser().
     *
     * Auth resolution order:
     *   1. If something upstream already set api_user, keep it.
     *   2. If the api plugin is loaded, run its full AuthMiddleware chain
     *      (X-API-Token / Authorization Bearer / api's own session
     *      authenticator). AuthMiddleware throws UnauthorizedException
     *      when no credentials match; we catch and fall through so an
     *      anonymous request doesn't 401 the legacy path before the
     *      controller's own access check runs.
     *   3. Plain session-user fallback. Always works on Grav 1.7 and on
     *      Grav 2.0 setups without api.
     *
     * The mutex with `/api/v1/sync/*` is unchanged; this enrichment only
     * applies to the legacy `/sync/*` prefix and lets external clients
     * present the same credentials there.
     */
    private function seedAuthAttribute(ServerRequestInterface $request): ServerRequestInterface
    {
        if ($request->getAttribute('api_user') instanceof UserInterface) {
            return $request;
        }

        // Delegate to the api plugin's full auth chain when available so
        // X-API-Token and Authorization Bearer are honored on the legacy
        // path, not just session cookies.
        if (class_exists(\Grav\Plugin\Api\Middleware\AuthMiddleware::class)) {
            try {
                $middleware = new \Grav\Plugin\Api\Middleware\AuthMiddleware(
                    $this->grav,
                    $this->grav['config']
                );
                $authed = $middleware->processRequest($request);
                $user = $authed->getAttribute('api_user');
                if ($user instanceof UserInterface) {
                    return $authed;
                }
            } catch (\Throwable) {
                // AuthMiddleware throws when no authenticator matches;
                // fall through to the session-user fallback below.
            }
        }

        // Session auth fallback (always works, even on Grav 1.7 without api).
        $user = $this->grav['user'] ?? null;
        if ($user instanceof UserInterface && (bool) $user->authenticated) {
            return $request->withAttribute('api_user', $user);
        }
        return $request;
    }

    /**
     * Decode the raw JSON body and stash it as `json_body`, mirroring the
     * api plugin's JsonBodyParserMiddleware so AbstractSyncController's
     * getRequestBody() returns the parsed array on either path.
     */
    private function seedJsonBody(ServerRequestInterface $request): ServerRequestInterface
    {
        $contentType = $request->getHeaderLine('Content-Type');
        if (!str_contains($contentType, 'json')) {
            return $request->withAttribute('json_body', []);
        }

        $raw = (string) $request->getBody();
        if ($raw === '') {
            return $request->withAttribute('json_body', []);
        }

        $decoded = json_decode($raw, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            // Match api plugin behavior: bad JSON yields an empty parsed
            // body; downstream requireFields surfaces the real problem.
            return $request->withAttribute('json_body', []);
        }
        return $request->withAttribute('json_body', is_array($decoded) ? $decoded : []);
    }

    /**
     * Compile a route pattern of the form "/sync/pages/{route}/pull" into
     * a regex that captures the named placeholders. `{route}` is greedy
     * so it can absorb multi-segment Grav routes like "/blog/my-post".
     *
     * @param list<string> $paramNames
     * @return array{method: string, regex: string, action: string, paramNames: list<string>}
     */
    private function compile(string $method, string $pattern, string $action, array $paramNames): array
    {
        $regex = preg_replace_callback(
            '/\{(\w+)\}/',
            static function (array $m) {
                // Greedy so multi-segment routes (/blog/foo/bar) match.
                return '(?P<' . $m[1] . '>.+)';
            },
            $pattern
        );
        $regex = '#^' . $regex . '/?$#';
        return [
            'method' => $method,
            'regex' => $regex,
            'action' => $action,
            'paramNames' => $paramNames,
        ];
    }
}
