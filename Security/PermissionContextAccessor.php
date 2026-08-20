<?php

declare(strict_types=1);

namespace Jul6Art\AclBundle\Security;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Stores and retrieves the request's {@see PermissionContext}.
 *
 * A request attribute rather than a property on the service: a service would keep the context of
 * the *last* request it saw, which under a sub-request — an ESI fragment, a forwarded controller —
 * means deciding one controller's permission against another's context.
 */
final readonly class PermissionContextAccessor
{
    public const string REQUEST_ATTRIBUTE = '_permission_context';

    public function __construct(
        private RequestStack $requestStack,
    ) {
    }

    public function set(Request $request, PermissionContext $context): void
    {
        $request->attributes->set(self::REQUEST_ATTRIBUTE, $context);
    }

    /**
     * The context of the given request, or of the one being handled. Null when there is no request
     * at all (a console command) or when nothing resolved a context for it — which is not an error:
     * a controller with no `#[CheckPermission]` and no route mapping simply has none.
     */
    public function get(?Request $request = null): ?PermissionContext
    {
        $targetRequest = $request ?? $this->requestStack->getCurrentRequest();
        if (!$targetRequest instanceof Request) {
            return null;
        }

        $context = $targetRequest->attributes->get(self::REQUEST_ATTRIBUTE);

        return $context instanceof PermissionContext ? $context : null;
    }
}
