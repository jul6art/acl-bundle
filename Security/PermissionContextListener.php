<?php

declare(strict_types=1);

namespace Jul6Art\AclBundle\Security;

use Symfony\Component\HttpKernel\Event\ControllerEvent;

/**
 * Resolves the request's permission context on `kernel.controller`, before anything votes.
 *
 * Registered from the bundle's extension with a **negative priority** rather than by an
 * `#[AsEventListener]` attribute, for two reasons that both bite in practice:
 *
 * - an attribute on a vendor class is only honoured if the application autoconfigures `vendor/`,
 *   which it should not;
 * - the priority is not decoration. This has to run *after* whatever loads and validates the
 *   tenant — API Platform's own listeners, an application's tenant subscriber — otherwise
 *   {@see TenantResolver} falls back to the client's header when a verified tenant object was
 *   about to be available one listener later.
 *
 * Sub-requests are skipped: an ESI fragment or a forwarded controller must not overwrite the
 * context the main controller is being judged against.
 */
final readonly class PermissionContextListener
{
    public function __construct(
        private PermissionContextResolver $resolver,
        private PermissionContextAccessor $accessor,
    ) {
    }

    public function onKernelController(ControllerEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $controller = $event->getController();

        $context = $this->resolver->resolve($controller, $event->getRequest());
        if (!$context instanceof PermissionContext) {
            return;
        }

        $this->accessor->set($event->getRequest(), $context);
    }
}
