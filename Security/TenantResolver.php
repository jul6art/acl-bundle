<?php

declare(strict_types=1);

namespace Jul6Art\AclBundle\Security;

use Jul6Art\AclBundle\Contract\AclTenantInterface;
use Symfony\Component\HttpFoundation\Request;

/**
 * Works out which tenant a request is aimed at, from the three places one can be named.
 *
 * In order, because they are not equally trustworthy:
 *
 * 1. **A resolved tenant object** on a request attribute. Something upstream already loaded and
 *    validated it, so it wins.
 * 2. **A route parameter** — `/organizations/{organization}/users`. Present because it was matched
 *    against the route, but not verified against anything.
 * 3. **The tenant header**. The client's claim, and nothing more.
 *
 * Extracted from what used to be two private copies of this logic — one in the context resolver,
 * one in the voter's fallback path. They had already begun to differ, which for a tenant-scoping
 * rule means two answers to "whose data is this".
 *
 * > ⚠️ **Resolving a tenant is not authorising it.** All this says is which tenant the request
 * > claims; {@see PermissionDecisionService} is what compares that against the actor's own.
 */
final readonly class TenantResolver
{
    /**
     * @param list<string> $routeParameters request attributes that may carry a tenant slug, in
     *                                      order of preference
     */
    public function __construct(
        private string $tenantHeader,
        private string $tenantAttribute,
        private array $routeParameters,
    ) {
    }

    public function resolveSlug(Request $request): ?string
    {
        $tenant = $request->attributes->get($this->tenantAttribute);
        if ($tenant instanceof AclTenantInterface) {
            $slug = $tenant->getSlug();
            if (null !== $slug && '' !== $slug) {
                return $slug;
            }
        }

        foreach ($this->routeParameters as $parameter) {
            $value = $request->attributes->get($parameter);
            if (\is_string($value) && '' !== $value) {
                return $value;
            }
        }

        $header = $request->headers->get($this->tenantHeader);

        return \is_string($header) && '' !== $header ? $header : null;
    }
}
