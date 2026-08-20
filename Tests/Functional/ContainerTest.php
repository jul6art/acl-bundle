<?php

declare(strict_types=1);

namespace Jul6Art\AclBundle\Tests\Functional;

use Jul6Art\AclBundle\Contract\FeatureCheckerInterface;
use Jul6Art\AclBundle\Contract\PermissionSetProviderInterface;
use Jul6Art\AclBundle\Contract\PermissionStoreInterface;
use Jul6Art\AclBundle\Security\AclPermissionReadService;
use Jul6Art\AclBundle\Security\FeatureAccessListener;
use Jul6Art\AclBundle\Security\PermissionDecisionService;
use Jul6Art\AclBundle\Security\PermissionDelegationService;
use Jul6Art\AclBundle\Security\PermissionVoter;
use Jul6Art\AclBundle\Tests\Fixtures\InMemoryPermissionSetProvider;
use Jul6Art\AclBundle\Tests\Fixtures\InMemoryPermissionStore;
use Jul6Art\AclBundle\Tests\Fixtures\StaticFeatureChecker;
use PHPUnit\Framework\Attributes\CoversNothing;

/**
 * The first test to write, and the one that keeps paying: a real container, built with the bundle
 * registered.
 *
 * It catches what no unit test can — a services.yaml that does not parse, a reference to a service
 * that does not exist, a configuration node the extension reads under another name. Every one of
 * those is invisible until something boots.
 *
 * Every scenario boots with SecurityBundle, because this bundle cannot work without it: the
 * resolver and the voter both need the token storage. That came out of this very test failing —
 * symfony/security-bundle had been left in `require-dev`, which would have installed cleanly in a
 * consuming project and then failed to compile the container. It is a hard requirement, and it now
 * says so in composer.json.
 */
#[CoversNothing]
final class ContainerTest extends AbstractFunctionalTestCase
{
    public function testTheBundleBoots(): void
    {
        $container = $this->boot(withSecurity: true);

        self::assertTrue($container->getParameter('acl.enabled'));
        self::assertSame('ROLE_SUPER_ADMIN', $container->getParameter('acl.super_admin_role'));
        self::assertSame('ROLE_ORGANIZATION_ADMIN', $container->getParameter('acl.tenant_admin_role'));
    }

    /**
     * Les services conditionnels, dans les deux sens. Sans implémentation de contrat côté projet,
     * ils sont **absents** — et non présents mais cassés au premier appel.
     */
    public function testTheOptionalServicesAreAbsentWithoutTheirContract(): void
    {
        $container = $this->boot(withSecurity: true);

        self::assertFalse($container->has(AclPermissionReadService::class));
        self::assertFalse($container->has(PermissionDelegationService::class));
        self::assertFalse($container->has(FeatureAccessListener::class));

        // Le moteur, lui, reste debout : il décide sans stockage, en refusant.
        self::assertTrue($container->has(PermissionDecisionService::class));
        self::assertTrue($container->has(PermissionVoter::class));
    }

    public function testEachContractBringsItsServiceBack(): void
    {
        $container = $this->boot(withSecurity: true, contracts: [
            PermissionSetProviderInterface::class => InMemoryPermissionSetProvider::class,
            PermissionStoreInterface::class => InMemoryPermissionStore::class,
            FeatureCheckerInterface::class => StaticFeatureChecker::class,
        ]);

        self::assertTrue($container->has(AclPermissionReadService::class));
        self::assertTrue($container->has(PermissionDelegationService::class));
        self::assertTrue($container->has(FeatureAccessListener::class));
    }

    /**
     * `enabled: false` must leave the bundle installed and inert — an application should be able
     * to switch it off without uninstalling it, and without its optional dependencies becoming
     * required.
     */
    public function testItCanBeDisabled(): void
    {
        self::assertFalse($this->boot('test', ['enabled' => false], withSecurity: true)->hasParameter('acl.enabled'));
    }
}
