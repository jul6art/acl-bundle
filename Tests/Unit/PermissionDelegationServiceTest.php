<?php

declare(strict_types=1);

namespace Jul6Art\AclBundle\Tests\Unit;

use Jul6Art\AclBundle\Security\PermissionDelegationService;
use Jul6Art\AclBundle\Tests\Fixtures\InMemoryPermissionStore;
use Jul6Art\AclBundle\Tests\Fixtures\TestTenant;
use Jul6Art\AclBundle\Tests\Fixtures\TestUser;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Delegation is where privilege escalation happens if it happens at all. Every test below is a
 * refusal, plus the assertion that the refusal wrote **nothing** — a guard that throws after
 * writing is not a guard.
 */
#[CoversClass(PermissionDelegationService::class)]
final class PermissionDelegationServiceTest extends TestCase
{
    private const string ADMIN = 'ROLE_ORGANIZATION_ADMIN';

    private const string SUPER = 'ROLE_SUPER_ADMIN';

    public function testAnAdminDelegatesAPermissionTheyHold(): void
    {
        $store = new InMemoryPermissionStore(['cms:page:publish']);
        $acme = new TestTenant(1, 'acme');

        $this->service($store)->grantToUser(
            new TestUser(id: 1, roles: [self::ADMIN], tenant: $acme),
            new TestUser(id: 2, tenant: $acme),
            'cms:page:publish',
        );

        self::assertSame([['action' => 'grantToUser', 'permission' => 'cms:page:publish', 'target' => '2']], $store->writes);
    }

    /**
     * La règle qui tient tout le modèle : sans elle, un administrateur s'accorderait simplement la
     * permission qui lui manque.
     */
    public function testNobodyDelegatesToThemselves(): void
    {
        $store = new InMemoryPermissionStore(['cms:page:publish']);
        $actor = new TestUser(id: 1, roles: [self::ADMIN], tenant: new TestTenant(1, 'acme'));

        $this->expectExceptionMessageIsOrContains('yourself');

        try {
            $this->service($store)->grantToUser($actor, $actor, 'cms:page:publish');
        } finally {
            self::assertSame([], $store->writes);
        }
    }

    public function testAnOrdinaryUserCannotDelegate(): void
    {
        $store = new InMemoryPermissionStore(['cms:page:publish']);
        $acme = new TestTenant(1, 'acme');

        $this->expectExceptionMessageIsOrContains('tenant administrators');

        try {
            $this->service($store)->grantToUser(
                new TestUser(id: 1, roles: ['ROLE_USER'], tenant: $acme),
                new TestUser(id: 2, tenant: $acme),
                'cms:page:publish',
            );
        } finally {
            self::assertSame([], $store->writes);
        }
    }

    public function testAnAdminCannotReachIntoAnotherTenant(): void
    {
        $store = new InMemoryPermissionStore(['cms:page:publish']);

        $this->expectExceptionMessageIsOrContains('outside your own tenant');

        try {
            $this->service($store)->grantToUser(
                new TestUser(id: 1, roles: [self::ADMIN], tenant: new TestTenant(1, 'acme')),
                new TestUser(id: 2, tenant: new TestTenant(2, 'autre')),
                'cms:page:publish',
            );
        } finally {
            self::assertSame([], $store->writes);
        }
    }

    /**
     * ⚠️ Deux utilisateurs sans tenant ne sont **pas** dans le même tenant. Traiter null comme une
     * correspondance laisserait deux comptes non rattachés se déléguer des permissions.
     */
    public function testTwoUsersWithoutATenantAreNotInTheSameTenant(): void
    {
        $store = new InMemoryPermissionStore(['cms:page:publish']);

        $this->expectExceptionMessageIsOrContains('outside your own tenant');

        try {
            $this->service($store)->grantToUser(
                new TestUser(id: 1, roles: [self::ADMIN]),
                new TestUser(id: 2),
                'cms:page:publish',
            );
        } finally {
            self::assertSame([], $store->writes);
        }
    }

    /**
     * Le garde anti-escalade : on ne donne que ce qu'on a.
     */
    public function testAnAdminCannotDelegateWhatTheyDoNotHold(): void
    {
        $store = new InMemoryPermissionStore(['cms:page:read']);
        $acme = new TestTenant(1, 'acme');

        $this->expectExceptionMessageIsOrContains('do not have');

        try {
            $this->service($store)->grantToUser(
                new TestUser(id: 1, roles: [self::ADMIN], tenant: $acme),
                new TestUser(id: 2, tenant: $acme),
                'erp:invoice:validate',
            );
        } finally {
            self::assertSame([], $store->writes);
        }
    }

    public function testASuperAdminIsAboveEveryRule(): void
    {
        $store = new InMemoryPermissionStore();

        $this->service($store)->grantToUser(
            new TestUser(id: 1, superAdmin: true),
            new TestUser(id: 2, tenant: new TestTenant(9, 'ailleurs')),
            'un:code:quelconque',
        );

        self::assertCount(1, $store->writes);
    }

    public function testRevokingAlsoRequiresHoldingThePermission(): void
    {
        $store = new InMemoryPermissionStore([]);
        $acme = new TestTenant(1, 'acme');

        $this->expectExceptionMessageIsOrContains('revoke a permission you do not have');

        $this->service($store)->removeUserOverride(
            new TestUser(id: 1, roles: [self::ADMIN], tenant: $acme),
            new TestUser(id: 2, tenant: $acme),
            'cms:page:publish',
        );
    }

    public function testAnExplicitDenialGoesThroughTheSameGuards(): void
    {
        $store = new InMemoryPermissionStore(['erp:invoice:validate']);
        $acme = new TestTenant(1, 'acme');

        $this->service($store)->denyToUser(
            new TestUser(id: 1, roles: [self::ADMIN], tenant: $acme),
            new TestUser(id: 2, tenant: $acme),
            'erp:invoice:validate',
        );

        self::assertSame('denyToUser', $store->writes[0]['action']);
    }

    // ── Gabarits de rôle : plus strict, parce que l'écriture porte sur tout le monde ──────────

    /**
     * ⚠️ Modifier un gabarit de rôle change la permission de **tous** les porteurs du rôle. Sans
     * tenant nommé, l'écriture est inter-tenants — c'est précisément l'escalade que cette classe
     * existe pour empêcher.
     */
    public function testATenantMustBeNamedToTouchARoleTemplate(): void
    {
        $store = new InMemoryPermissionStore(['cms:page:publish']);

        $this->expectExceptionMessageIsOrContains('tenant is required');

        try {
            $this->service($store)->grantToRole(
                new TestUser(id: 1, roles: [self::ADMIN], tenant: new TestTenant(1, 'acme')),
                'ROLE_EDITOR',
                'cms:page:publish',
            );
        } finally {
            self::assertSame([], $store->writes);
        }
    }

    public function testTheSuperAdminRoleTemplateIsUntouchable(): void
    {
        $store = new InMemoryPermissionStore(['cms:page:publish']);
        $acme = new TestTenant(1, 'acme');

        $this->expectExceptionMessageIsOrContains('super admin role');

        try {
            $this->service($store)->grantToRole(
                new TestUser(id: 1, roles: [self::ADMIN], tenant: $acme),
                self::SUPER,
                'cms:page:publish',
                $acme,
            );
        } finally {
            self::assertSame([], $store->writes);
        }
    }

    public function testAnAdminGrantsToARoleWithinTheirTenant(): void
    {
        $store = new InMemoryPermissionStore(['cms:page:publish']);
        $acme = new TestTenant(1, 'acme');

        $this->service($store)->grantToRole(
            new TestUser(id: 1, roles: [self::ADMIN], tenant: $acme),
            'ROLE_EDITOR',
            'cms:page:publish',
            $acme,
        );

        self::assertSame([['action' => 'grantToRole', 'permission' => 'cms:page:publish', 'target' => 'ROLE_EDITOR']], $store->writes);
    }

    public function testASuperAdminMayTouchAGlobalRoleTemplate(): void
    {
        $store = new InMemoryPermissionStore();

        $this->service($store)->revokeFromRole(new TestUser(id: 1, superAdmin: true), self::SUPER, 'cms:page:publish');

        self::assertCount(1, $store->writes);
    }

    /**
     * Les deux noms de rôles viennent de la configuration : un projet dont le rôle suprême
     * s'appelle autrement doit être protégé de la même façon.
     */
    public function testTheProtectedRoleNamesAreConfigured(): void
    {
        $store = new InMemoryPermissionStore(['cms:page:publish']);
        $acme = new TestTenant(1, 'acme');
        $service = new PermissionDelegationService($store, 'ROLE_WORKSPACE_OWNER', 'ROLE_ROOT');

        $this->expectExceptionMessageIsOrContains('super admin role');

        $service->grantToRole(
            new TestUser(id: 1, roles: ['ROLE_WORKSPACE_OWNER'], tenant: $acme),
            'ROLE_ROOT',
            'cms:page:publish',
            $acme,
        );
    }

    private function service(InMemoryPermissionStore $store): PermissionDelegationService
    {
        return new PermissionDelegationService($store, self::ADMIN, self::SUPER);
    }
}
