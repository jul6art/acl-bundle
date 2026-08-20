<?php

declare(strict_types=1);

namespace Jul6Art\AclBundle\Security;

use Jul6Art\AclBundle\Attribute\CheckPermission;
use Jul6Art\AclBundle\Contract\AclUserInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;

/**
 * Turns a controller plus a request into the {@see PermissionContext} a decision needs.
 *
 * The permission comes from `#[CheckPermission]` — on the method first, then on the class — and
 * failing that from the configured route map. No permission means no context, which is not a
 * refusal: a controller that declares nothing is simply not gated by this mechanism.
 */
final readonly class PermissionContextResolver
{
    public function __construct(
        private PermissionCodeParser $parser,
        private PermissionRouteMap $routeMap,
        private TenantResolver $tenants,
        private TokenStorageInterface $tokenStorage,
    ) {
    }

    public function resolve(callable $controller, Request $request): ?PermissionContext
    {
        $permission = $this->resolvePermission($controller, $request);
        if (null === $permission) {
            return null;
        }

        $parsed = $this->parser->parse($permission);

        return new PermissionContext(
            permission: $permission,
            resource: $parsed['resource'],
            action: $parsed['action'],
            domain: $this->tenants->resolveSlug($request),
            actor: $this->actor(),
            isApi: $this->isApi($request),
        );
    }

    private function actor(): ?AclUserInterface
    {
        $user = $this->tokenStorage->getToken()?->getUser();

        return $user instanceof AclUserInterface ? $user : null;
    }

    /**
     * An API request is recognised by its path prefix, which is a convention rather than a fact —
     * but it is the same convention API Platform itself is mounted on, and the alternative (asking
     * the router which firewall matched) is not available this early.
     */
    private function isApi(Request $request): bool
    {
        return str_starts_with($request->getPathInfo(), '/api/');
    }

    private function resolvePermission(callable $controller, Request $request): ?string
    {
        $fromAttribute = $this->resolveFromAttribute($controller);
        if (null !== $fromAttribute) {
            return $fromAttribute;
        }

        $routeName = $request->attributes->getString('_route', '');

        return '' === $routeName ? null : $this->routeMap->resolve($routeName);
    }

    private function resolveFromAttribute(callable $controller): ?string
    {
        // Un contrôleur invocable ou une closure n'a pas de couple [objet, méthode] à réfléchir :
        // l'attribut n'y est pas atteignable, et la carte de routes prend le relais.
        if (!\is_array($controller)) {
            return null;
        }

        try {
            $method = new \ReflectionMethod($controller[0], $controller[1]);
        } catch (\ReflectionException) {
            return null;
        }

        // La méthode gagne sur la classe, et il n'y a pas de fusion : la déclaration la plus proche
        // de l'action est celle qui s'applique.
        $onMethod = $method->getAttributes(CheckPermission::class);
        if ([] !== $onMethod) {
            return $onMethod[0]->newInstance()->permission;
        }

        $onClass = $method->getDeclaringClass()->getAttributes(CheckPermission::class);

        return [] !== $onClass ? $onClass[0]->newInstance()->permission : null;
    }
}
