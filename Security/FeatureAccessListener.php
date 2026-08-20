<?php

declare(strict_types=1);

namespace Jul6Art\AclBundle\Security;

use Jul6Art\AclBundle\Attribute\RequiresFeature;
use Jul6Art\AclBundle\Contract\AclTenantInterface;
use Jul6Art\AclBundle\Contract\AclUserInterface;
use Jul6Art\AclBundle\Contract\FeatureCheckerInterface;
use Jul6Art\AclBundle\Event\FeatureAccessDeniedEvent;
use Jul6Art\AclBundle\Event\FeatureDenialReason;
use Psr\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\ControllerEvent;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;

/**
 * Enforces `#[RequiresFeature]` on `kernel.controller`.
 *
 * Unlike `#[CheckPermission]`, which only resolves a context, this one refuses by itself: a feature
 * flag has nothing to vote on — either the tenant has the module or it does not.
 *
 * Several codes are **OR**: one enabled feature is enough. A method-level declaration wins over the
 * class-level one, with no merging.
 *
 * Anonymous requests pass through untouched. A feature gate is not authentication, and answering
 * 403 here would hide the login redirect the firewall is about to issue.
 */
final readonly class FeatureAccessListener
{
    public function __construct(
        private TokenStorageInterface $tokenStorage,
        private FeatureCheckerInterface $features,
        private EventDispatcherInterface $dispatcher,
    ) {
    }

    public function onKernelController(ControllerEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $featureCodes = self::resolveRequiredFeatures($event->getController());
        if ([] === $featureCodes) {
            return;
        }

        $user = $this->tokenStorage->getToken()?->getUser();
        if (!$user instanceof AclUserInterface) {
            return;
        }

        if ($user->isSuperAdmin()) {
            return;
        }

        if (!$user->getTenant() instanceof AclTenantInterface) {
            $this->deny($event, $user, $featureCodes, FeatureDenialReason::NoTenant);

            return;
        }

        foreach ($featureCodes as $featureCode) {
            if ($this->features->isEnabled($user, $featureCode)) {
                return;
            }
        }

        $this->deny($event, $user, $featureCodes, FeatureDenialReason::Disabled);
    }

    /**
     * @param list<string> $featureCodes
     */
    private function deny(ControllerEvent $event, AclUserInterface $user, array $featureCodes, FeatureDenialReason $reason): void
    {
        $denial = new FeatureAccessDeniedEvent($event->getRequest(), $user, $featureCodes, $reason);
        $this->dispatcher->dispatch($denial);

        $response = $denial->getResponse();

        // Rien d'écouté : on refuse. Une porte de fonctionnalité qui s'ouvre faute de gestionnaire
        // transformerait chaque module payant en module gratuit.
        if (!$response instanceof Response) {
            throw new AccessDeniedException(\sprintf('None of the required features is enabled: %s.', implode(', ', $featureCodes)));
        }

        // Le contrôleur est remplacé plutôt que la réponse posée : à `kernel.controller`, seul le
        // contrôleur est encore modifiable.
        $event->setController(static fn (): Response => $response);
    }

    /**
     * @return list<string>
     */
    private static function resolveRequiredFeatures(callable $controller): array
    {
        if (!\is_array($controller)) {
            return [];
        }

        try {
            $method = new \ReflectionMethod($controller[0], $controller[1]);
        } catch (\ReflectionException) {
            return [];
        }

        $onMethod = $method->getAttributes(RequiresFeature::class);
        if ([] !== $onMethod) {
            return $onMethod[0]->newInstance()->featureCodes;
        }

        $onClass = $method->getDeclaringClass()->getAttributes(RequiresFeature::class);

        return [] !== $onClass ? $onClass[0]->newInstance()->featureCodes : [];
    }
}
