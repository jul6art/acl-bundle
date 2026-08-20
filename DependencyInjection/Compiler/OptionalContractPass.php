<?php

declare(strict_types=1);

namespace Jul6Art\AclBundle\DependencyInjection\Compiler;

use Jul6Art\AclBundle\Contract\FeatureCheckerInterface;
use Jul6Art\AclBundle\Contract\PermissionSetProviderInterface;
use Jul6Art\AclBundle\Contract\PermissionStoreInterface;
use Jul6Art\AclBundle\Security\AclPermissionReadService;
use Jul6Art\AclBundle\Security\FeatureAccessListener;
use Jul6Art\AclBundle\Security\PermissionDecisionService;
use Jul6Art\AclBundle\Security\PermissionDelegationService;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;

/**
 * Removes the services whose contract the application did not implement.
 *
 * This has to be a compiler pass and not a check in the extension: an extension runs before the
 * other bundles have configured anything, so asking whether a service exists there always answers
 * no. By the time a pass runs, the application's own `services.yaml` has been loaded and the
 * question is answerable.
 *
 * What each removal means for behaviour is deliberate, and none of it fails open:
 *
 * - **no `PermissionSetProviderInterface`** → the read service goes, and the decision service runs
 *   without storage. Overrides and role grants are never found, so only a super admin or a tenant
 *   administrator is granted anything. An ACL whose storage is missing must refuse, not allow —
 *   and the alternative, an unresolvable argument, would be a container error at boot on an
 *   application that simply has not wired the optional half yet.
 * - **no `PermissionStoreInterface`** → delegation goes. Injecting it would fail at boot; leaving
 *   it out means an application that only reads permissions never implements six mutation methods.
 * - **no `FeatureCheckerInterface`** → the feature listener goes, and `#[RequiresFeature]` stops
 *   being enforced. That is the one place where absence relaxes a check, and it is the honest
 *   reading: the attribute has nothing to check against, and refusing every gated page in an
 *   application with no feature system would break it outright.
 */
final class OptionalContractPass implements CompilerPassInterface
{
    #[\Override]
    public function process(ContainerBuilder $container): void
    {
        if (!self::hasImplementation($container, PermissionSetProviderInterface::class)) {
            $container->removeDefinition(AclPermissionReadService::class);

            if ($container->hasDefinition(PermissionDecisionService::class)) {
                $container->getDefinition(PermissionDecisionService::class)->setArgument('$permissions', null);
            }
        }

        if (!self::hasImplementation($container, PermissionStoreInterface::class)) {
            $container->removeDefinition(PermissionDelegationService::class);
        }

        if (!self::hasImplementation($container, FeatureCheckerInterface::class)) {
            $container->removeDefinition(FeatureAccessListener::class);
        }
    }

    /**
     * An alias is what autowiring actually resolves, so it counts as much as a definition: a
     * project registering its implementation under its own class name and aliasing the interface
     * to it — the ordinary Symfony pattern — must not be read as having registered nothing.
     */
    private static function hasImplementation(ContainerBuilder $container, string $interface): bool
    {
        return $container->hasAlias($interface) || $container->hasDefinition($interface);
    }
}
