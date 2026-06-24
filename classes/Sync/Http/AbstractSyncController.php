<?php

declare(strict_types=1);

namespace Grav\Plugin\Sync\Http;

use Grav\Common\Config\Config;
use Grav\Common\Grav;
use Grav\Common\User\Interfaces\UserInterface;
use Grav\Common\Utils;
use Grav\Plugin\Sync\Http\Exceptions\ForbiddenException;
use Grav\Plugin\Sync\Http\Exceptions\UnauthorizedException;
use Grav\Plugin\Sync\Http\Exceptions\ValidationException;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Base class for sync's HTTP controllers.
 *
 * Replaces sync's old dependency on grav-plugin-api's AbstractApiController
 * with a minimal, self-contained equivalent that works on Grav 1.7+ AND
 * 2.0+. The helper signatures and semantics match what AbstractApiController
 * provided, so SyncController bodies needed only a one-line swap of imports
 * and response/exception class names.
 *
 * Auth resolution:
 *   - On the api-plugin path the api router pre-populates the `api_user`
 *     request attribute. `getUser()` reads it directly, matching the
 *     existing pipeline.
 *   - On the legacy path (no api plugin) the dispatcher in sync.php seeds
 *     `api_user` from `$grav['user']` if a session user is present. Code
 *     here doesn't need to know which path it's on.
 *
 * Permission resolution prefers the api plugin's PermissionResolver (so
 * api.access gating, super-admin bypass, and dot-path inheritance behave
 * identically when api is loaded). On Grav 1.7 without api, it falls back
 * to a direct user-access lookup with the same dot-path walk-up but no
 * api.access pre-gate (api.access isn't registered as a permission there).
 */
abstract class AbstractSyncController
{
    protected readonly Config $config;

    /** @var array<string, mixed>|null Cached flat user access map. */
    private ?array $flatAccess = null;
    private ?UserInterface $flatAccessUser = null;

    /**
     * Two-arg form `($grav, $config)` matches what the api plugin's router
     * uses to instantiate controllers (see ApiRouter::handleRoute), so the
     * api-plugin path keeps working with no router-side change. The
     * single-arg form `($grav)` is convenient for the legacy dispatcher,
     * which resolves the config from the container itself.
     */
    public function __construct(
        protected readonly Grav $grav,
        ?Config $config = null,
    ) {
        $this->config = $config ?? $grav['config'];
    }

    /**
     * Resolve the authenticated user. The api plugin's AuthMiddleware sets
     * the `api_user` attribute. The legacy dispatcher seeds it from
     * `$grav['user']` if a session user is logged in.
     */
    protected function getUser(ServerRequestInterface $request): UserInterface
    {
        $user = $request->getAttribute('api_user');
        if (!$user instanceof UserInterface) {
            throw new UnauthorizedException();
        }
        return $user;
    }

    /**
     * Like getUser() but for endpoints that admit guests (public-route
     * optimistic auth): null when no credentials were presented, leaving
     * the access decision to each channel's authCallback.
     */
    protected function getUserOptional(ServerRequestInterface $request): ?UserInterface
    {
        $user = $request->getAttribute('api_user');

        return $user instanceof UserInterface ? $user : null;
    }

    /**
     * Verify the user has the required permission. Mirrors
     * AbstractApiController::requirePermission() when the api plugin is
     * loaded; falls back to a direct access-array lookup otherwise.
     */
    protected function requirePermission(ServerRequestInterface $request, string $permission): void
    {
        $user = $this->getUser($request);

        // Super admin bypass — same key the api plugin uses.
        if ((bool) $user->get('access.api.super')) {
            return;
        }

        if (\class_exists(\Grav\Plugin\Api\PermissionResolver::class, true)
            && isset($this->grav['permissions'])) {
            $resolver = new \Grav\Plugin\Api\PermissionResolver($this->grav['permissions']);

            // Match AbstractApiController: gate api.access first, then the
            // specific permission. This branch only runs when api is
            // loaded, so api.access is a registered permission.
            if (!$resolver->resolve($user, 'api.access')) {
                throw new ForbiddenException('API access is not enabled for this user.');
            }
            if (!$resolver->resolve($user, $permission)) {
                throw new ForbiddenException("Missing required permission: {$permission}");
            }
            return;
        }

        // Legacy fallback: walk up dot-path on user access map directly.
        // No api.access gate (api isn't loaded so it's not a registered
        // permission in the first place).
        if (!$this->resolvePermission($user, $permission)) {
            throw new ForbiddenException("Missing required permission: {$permission}");
        }
    }

    /**
     * Get the parsed JSON request body. The api plugin's
     * JsonBodyParserMiddleware sets `json_body`; the legacy dispatcher
     * does the same after parsing php://input.
     *
     * Returns an empty array for empty/missing bodies (matching
     * AbstractApiController behavior so endpoints that tolerate empty
     * bodies, e.g. capabilities and presence-leave variants, keep working).
     */
    protected function getRequestBody(ServerRequestInterface $request): array
    {
        $body = $request->getAttribute('json_body');
        if ($body === null) {
            $body = $request->getParsedBody();
        }
        return is_array($body) ? $body : [];
    }

    /**
     * Validate that the named fields are present and non-blank in the
     * request body. Throws ValidationException listing each missing field.
     */
    protected function requireFields(array $body, array $fields): void
    {
        $missing = [];
        foreach ($fields as $field) {
            if (!isset($body[$field]) || (is_string($body[$field]) && trim($body[$field]) === '')) {
                $missing[] = $field;
            }
        }

        if ($missing) {
            throw new ValidationException(
                'Missing required fields: ' . implode(', ', $missing),
                array_map(fn($f) => ['field' => $f, 'message' => "The '{$f}' field is required."], $missing)
            );
        }
    }

    /**
     * Pull a routed URL parameter out of the PSR-7 request attributes.
     * Both the api router and the legacy dispatcher store captured
     * placeholders under `route_params`.
     */
    protected function getRouteParam(ServerRequestInterface $request, string $name): ?string
    {
        $params = $request->getAttribute('route_params', []);
        return isset($params[$name]) ? (string) $params[$name] : null;
    }

    /**
     * Direct access-array lookup with dot-path walk-up. Used only on the
     * legacy (no-api-plugin) code path. Returns true iff an explicit grant
     * exists at the requested key or any parent key up the dot-path.
     */
    private function resolvePermission(UserInterface $user, string $permission): bool
    {
        if ($this->flatAccess === null || $this->flatAccessUser !== $user) {
            $nested = $user->get('access');
            $this->flatAccess = is_array($nested)
                ? Utils::arrayFlattenDotNotation($nested)
                : [];
            $this->flatAccessUser = $user;
        }
        $flat = $this->flatAccess;

        $key = $permission;
        while ($key !== '') {
            if (array_key_exists($key, $flat)) {
                $value = $flat[$key];
                if (is_bool($value)) {
                    return $value;
                }
                if ($value === 1 || $value === '1' || $value === 'true') {
                    return true;
                }
                if ($value === 0 || $value === '0' || $value === 'false' || $value === null) {
                    return false;
                }
            }
            $pos = strrpos($key, '.');
            $key = $pos !== false ? substr($key, 0, $pos) : '';
        }

        return false;
    }
}
