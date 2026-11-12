<?php

declare(strict_types=1);

namespace Jul6Art\AclBundle\DependencyInjection;

use Jul6Art\AclBundle\Doctrine\DoctrinePermissionStore;
use Symfony\Component\Config\Definition\Builder\TreeBuilder;
use Symfony\Component\Config\Definition\ConfigurationInterface;

/**
 * The bundle's configuration tree.
 *
 * Write an `->info()` on every node: it is what `config:dump-reference` shows, and it is the only
 * documentation a reader gets before opening the code.
 *
 * > ⚠️ **A node that decides something at compile time cannot be an env var.** `%env(bool:X)%`
 * > reaches a `booleanNode()` as the placeholder *string* and the config layer rejects it. Use a
 * > plain value for anything that gates service registration, and keep env vars for values passed
 * > through to a service at runtime (a `scalarNode` argument).
 */
class Configuration implements ConfigurationInterface
{
    #[\Override]
    public function getConfigTreeBuilder(): TreeBuilder
    {
        $treeBuilder = new TreeBuilder('acl');

        $treeBuilder->getRootNode()
            ->children()
                ->booleanNode('enabled')
                    ->info('Registers the bundle\'s services. false leaves it installed and inert.')
                    ->defaultTrue()
                ->end()
                ->scalarNode('super_admin_role')
                    ->info('The role that bypasses every check. Its name belongs to the application: this bundle never assumes ROLE_SUPER_ADMIN exists.')
                    ->defaultValue('ROLE_SUPER_ADMIN')
                    ->cannotBeEmpty()
                ->end()
                ->booleanNode('multi_tenant')
                    ->info('false in an application that has no tenants. Drops the "an API request must name its tenant" rule, which would otherwise refuse every /api/ permission check for anyone but a super admin — there being no tenant to resolve. Keep true whenever tenants exist.')
                    ->defaultTrue()
                ->end()
                ->scalarNode('tenant_admin_role')
                    ->info('The role allowed to delegate permissions inside its own tenant, and granted anything its tenant is not explicitly denied.')
                    ->defaultValue('ROLE_ORGANIZATION_ADMIN')
                    ->cannotBeEmpty()
                ->end()
                ->scalarNode('tenant_header')
                    ->info('HTTP header a client uses to name the tenant. Should hold the same value as the API bundle\'s api.tenant_header — pass the parameter rather than the literal.')
                    ->defaultValue('X-TENANT')
                    ->cannotBeEmpty()
                ->end()
                ->scalarNode('tenant_request_attribute')
                    ->info('Request attribute where an upstream listener puts the resolved tenant object (an AclTenantInterface). Checked first, because it has been validated.')
                    ->defaultValue('_tenant')
                    ->cannotBeEmpty()
                ->end()
                ->arrayNode('tenant_route_parameters')
                    ->info('Route parameters that may carry a tenant slug, tried in order after the resolved tenant object and before the header.')
                    ->scalarPrototype()->end()
                    ->defaultValue(['organization', 'organizationSlug', 'domain'])
                ->end()
                ->arrayNode('route_permissions')
                    ->info('Route name → permission code, for controllers that cannot carry #[CheckPermission]. The attribute is the normal way; this is the escape hatch.')
                    ->useAttributeAsKey('route')
                    ->scalarPrototype()->end()
                    ->defaultValue([])
                ->end()
                ->integerNode('context_listener_priority')
                    ->info('kernel.controller priority for the context listener. Must stay negative enough to run after whatever loads the tenant, or the resolver falls back to the client-supplied header.')
                    ->defaultValue(-10)
                ->end()
                ->arrayNode('storage')
                    ->info('The permissions in Doctrine (ADR-0001): the store, the provider the engine reads, the seed. Off by default: an application keeps its own storage until it turns this on — two providers would compete for the engine.')
                    ->canBeEnabled()
                    ->children()
                        ->scalarNode('user_class')
                            ->info('The application\'s account entity (an AclUserInterface): an override belongs to one, and Doctrine resolves the override\'s account to it.')
                            ->defaultNull()
                        ->end()
                        ->scalarNode('role_permission_class')
                            ->info('The application\'s entity extending Doctrine\\AbstractRolePermission: its table, unique constraint and id.')
                            ->defaultNull()
                        ->end()
                        ->scalarNode('user_permission_override_class')
                            ->info('The application\'s entity extending Doctrine\\AbstractUserPermissionOverride.')
                            ->defaultNull()
                        ->end()
                        ->scalarNode('catalog')
                            ->info('The service id (usually the class) of the application\'s PermissionCatalogInterface: the seed\'s defaults, the grid\'s codes. Without it, no seed.')
                            ->defaultNull()
                        ->end()
                        ->enumNode('role_scope')
                            ->info('global: one grid for everyone. tenant: a tenant\'s row wins over the platform\'s (the row without tenant), granted as refused.')
                            ->values([DoctrinePermissionStore::SCOPE_GLOBAL, DoctrinePermissionStore::SCOPE_TENANT])
                            ->defaultValue(DoctrinePermissionStore::SCOPE_GLOBAL)
                        ->end()
                        ->enumNode('override_scope')
                            ->info('global: an override applies everywhere. tenant: it belongs to the account\'s current tenant, and is read there only.')
                            ->values([DoctrinePermissionStore::SCOPE_GLOBAL, DoctrinePermissionStore::SCOPE_TENANT])
                            ->defaultValue(DoctrinePermissionStore::SCOPE_GLOBAL)
                        ->end()
                        ->scalarNode('tenant_property')
                            ->info('The tenant association of the application\'s entities, when a scope is tenant.')
                            ->defaultValue('organization')
                            ->cannotBeEmpty()
                        ->end()
                        ->booleanNode('role_hierarchy')
                            ->info('true: the roles an account reaches (security.role_hierarchy) grant too. Off by default: where each role\'s defaults are written out in full, turning it on changes decisions.')
                            ->defaultFalse()
                        ->end()
                    ->end()
                    ->validate()
                        ->ifTrue(static fn (array $storage): bool => true === ($storage['enabled'] ?? false) && (null === ($storage['user_class'] ?? null) || null === ($storage['role_permission_class'] ?? null) || null === ($storage['user_permission_override_class'] ?? null)))
                        ->thenInvalid('acl.storage needs user_class, role_permission_class and user_permission_override_class: the store writes those classes.')
                    ->end()
                ->end()
            ->end();

        return $treeBuilder;
    }
}
