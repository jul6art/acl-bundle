<?php

declare(strict_types=1);

namespace Jul6Art\AclBundle\DependencyInjection;

use Jul6Art\AclBundle\Contract\FeatureCheckerInterface;
use Jul6Art\AclBundle\Contract\PermissionSetProviderInterface;
use Jul6Art\AclBundle\Contract\PermissionStoreInterface;
use Jul6Art\AclBundle\Security\AclPermissionReadService;
use Jul6Art\AclBundle\Security\FeatureAccessListener;
use Jul6Art\AclBundle\Security\PermissionContextListener;
use Jul6Art\AclBundle\Security\PermissionDecisionService;
use Jul6Art\AclBundle\Security\PermissionDelegationService;
use Jul6Art\AclBundle\Security\PermissionRouteMap;
use Jul6Art\AclBundle\Security\TenantResolver;
use Symfony\Component\Config\FileLocator;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Extension\Extension;
use Symfony\Component\DependencyInjection\Loader\YamlFileLoader;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Wires the permission engine, and only the parts the application has given it what to work with.
 *
 * Three services are conditional, and each is absent rather than broken:
 *
 * - **the read service and the voter's storage** need a {@see PermissionSetProviderInterface}.
 *   Without one, {@see PermissionDecisionService} runs with no storage: overrides and role grants
 *   are simply never found, so only a super admin or a tenant administrator gets through. That is
 *   the safe direction, and it is stated out loud rather than left to be discovered.
 * - **delegation** needs a {@see PermissionStoreInterface}. An application that reads permissions
 *   but never hands them out does not implement six mutation methods to get a voter working.
 * - **the feature gate** needs a {@see FeatureCheckerInterface}. Without one, `#[RequiresFeature]`
 *   is inert — which is the honest behaviour: refusing every gated page in an application that has
 *   no feature system would break it, while the attribute plainly has nothing to check.
 *
 * The checks are `interface_exists()`-free on purpose: these interfaces always exist, since the
 * bundle ships them. What is uncertain is whether the *application* registered an implementation,
 * and that is a service question — so it is settled in a compiler pass, not here. An extension
 * runs before the other bundles have configured anything, so `$container->has()` at this point
 * always answers false.
 */
class AclExtension extends Extension
{
    #[\Override]
    public function load(array $configs, ContainerBuilder $container): void
    {
        $loader = new YamlFileLoader($container, new FileLocator(__DIR__.'/../Resources/config'));
        $loader->load('services.yaml');

        $config = $this->processConfiguration(new Configuration(), $configs);

        if (false === ($config['enabled'] ?? true)) {
            return;
        }

        $superAdminRole = self::asString($config['super_admin_role'] ?? null, 'ROLE_SUPER_ADMIN');
        $tenantAdminRole = self::asString($config['tenant_admin_role'] ?? null, 'ROLE_ORGANIZATION_ADMIN');

        $container->setParameter('acl.enabled', true);
        $container->setParameter('acl.super_admin_role', $superAdminRole);
        $container->setParameter('acl.tenant_admin_role', $tenantAdminRole);

        $container->getDefinition(TenantResolver::class)
            ->setArgument('$tenantHeader', self::asString($config['tenant_header'] ?? null, 'X-TENANT'))
            ->setArgument('$tenantAttribute', self::asString($config['tenant_request_attribute'] ?? null, '_tenant'))
            ->setArgument('$routeParameters', self::stringList($config['tenant_route_parameters'] ?? []));

        $container->getDefinition(PermissionRouteMap::class)
            ->setArgument('$routePermissions', self::stringMap($config['route_permissions'] ?? []));

        $container->getDefinition(PermissionDecisionService::class)
            ->setArgument('$tenantAdminRole', $tenantAdminRole);

        $container->getDefinition(PermissionDelegationService::class)
            ->setArgument('$tenantAdminRole', $tenantAdminRole)
            ->setArgument('$superAdminRole', $superAdminRole);

        // ⚠️ La priorité n'est pas décorative : ce listener doit passer APRÈS celui qui charge et
        // valide le tenant, sinon TenantResolver retombe sur l'en-tête fourni par le client alors
        // qu'un objet vérifié allait être disponible un listener plus loin.
        $priority = \is_int($config['context_listener_priority'] ?? null) ? $config['context_listener_priority'] : -10;
        $container->getDefinition(PermissionContextListener::class)
            ->addTag('kernel.event_listener', [
                'event' => KernelEvents::CONTROLLER,
                'method' => 'onKernelController',
                'priority' => $priority,
            ]);

        $container->getDefinition(FeatureAccessListener::class)
            ->addTag('kernel.event_listener', [
                'event' => KernelEvents::CONTROLLER,
                'method' => 'onKernelController',
            ]);

        // Utilisé par le pass de compilation pour savoir s'il doit retirer le service de lecture :
        // un `Definition` inspecté après coup ne dit pas ce que la configuration valait.
        $container->setParameter('acl.read_service_class', AclPermissionReadService::class);
    }

    /**
     * @return array<string, string>
     */
    private static function stringMap(mixed $value): array
    {
        if (!\is_array($value)) {
            return [];
        }

        $map = [];
        foreach ($value as $key => $item) {
            if (\is_string($key) && \is_string($item)) {
                $map[$key] = $item;
            }
        }

        return $map;
    }

    /**
     * @return list<string>
     */
    private static function stringList(mixed $value): array
    {
        if (!\is_array($value)) {
            return [];
        }

        return array_values(array_filter($value, \is_string(...)));
    }

    private static function asString(mixed $value, string $fallback): string
    {
        return \is_string($value) && '' !== $value ? $value : $fallback;
    }
}
