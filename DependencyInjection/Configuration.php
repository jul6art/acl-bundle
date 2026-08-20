<?php

declare(strict_types=1);

namespace Jul6Art\AclBundle\DependencyInjection;

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
            ->end();

        return $treeBuilder;
    }
}
