<?php

declare(strict_types=1);

namespace Jul6Art\AclBundle\Security;

/**
 * Maps a route name to a permission code, for controllers that carry no `#[CheckPermission]`.
 *
 * ```yaml
 * acl:
 *     route_permissions:
 *         app_security_login: 'auth:login'
 *         app_legacy_export: 'export:read'
 * ```
 *
 * The attribute is the normal way; this is the escape hatch for a route whose controller cannot be
 * annotated — one built by a third-party bundle, or a legacy action being migrated. Keeping it as
 * configuration rather than a constant means the list is visible to whoever audits access, instead
 * of being a table inside a vendor class.
 *
 * An unmapped route resolves to null, and no context is set. That is not a refusal: authorisation
 * still comes from whatever `isGranted()` the controller performs.
 */
final readonly class PermissionRouteMap
{
    /**
     * @param array<string, string> $routePermissions route name → permission code
     */
    public function __construct(
        private array $routePermissions = [],
    ) {
    }

    public function resolve(string $routeName): ?string
    {
        return $this->routePermissions[$routeName] ?? null;
    }
}
