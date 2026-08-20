<?php

declare(strict_types=1);

namespace Jul6Art\AclBundle\Tests\Unit;

use Jul6Art\AclBundle\Security\AclPermissionReadService;
use Jul6Art\AclBundle\Security\PermissionContext;
use Jul6Art\AclBundle\Security\PermissionDecisionService;
use Jul6Art\AclBundle\Tests\Fixtures\InMemoryPermissionSetProvider;
use Jul6Art\AclBundle\Tests\Fixtures\TestTenant;
use Jul6Art\AclBundle\Tests\Fixtures\TestUser;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * The order of the rules is the security model. Each test below is one rule, and the reason it is
 * where it is.
 */
#[CoversClass(PermissionDecisionService::class)]
final class PermissionDecisionServiceTest extends TestCase
{
    private const string ADMIN = 'ROLE_ORGANIZATION_ADMIN';

    /**
     * Avant le contrôle d'activité, à dessein : un compte de support doit pouvoir atteindre un
     * tenant désactivé pour le réparer.
     */
    public function testASuperAdminPassesEvenWhenDeactivated(): void
    {
        $user = new TestUser(active: false, superAdmin: true);

        self::assertTrue($this->service()->isGranted($user, 'cms:page:read'));
    }

    /**
     * Désactiver un compte doit suffire. Sans cette règle, il faudrait aussi retirer une à une ses
     * permissions — ce qui n'est pas un modèle de sécurité, c'est une liste de choses à ne pas
     * oublier.
     */
    public function testADeactivatedAccountIsRefusedDespiteAGrantedRole(): void
    {
        $user = new TestUser(roles: [self::ADMIN], active: false);

        self::assertFalse($this->service(rolePermissions: ['cms:page:read'])->isGranted($user, 'cms:page:read'));
    }

    /**
     * ⚠️ La règle la plus importante du fichier. Une requête d'API dont le tenant n'a pas été
     * résolu est **refusée**, et non rabattue sur le tenant de l'appelant : un repli implicite
     * transformerait un en-tête oublié en collection inter-tenants, avec un 200 indiscernable
     * d'une réponse correcte.
     */
    public function testAnApiRequestWithoutAResolvedTenantIsRefused(): void
    {
        $user = new TestUser(roles: [self::ADMIN], tenant: new TestTenant(1, 'acme'));

        self::assertFalse($this->service(rolePermissions: ['cms:page:read'])->isGranted(
            $user,
            'cms:page:read',
            context: $this->context('cms:page:read', domain: null, isApi: true),
        ));
    }

    public function testTheSameRequestOutsideTheApiIsAllowed(): void
    {
        $user = new TestUser(roles: [self::ADMIN], tenant: new TestTenant(1, 'acme'));

        self::assertTrue($this->service(rolePermissions: ['cms:page:read'])->isGranted(
            $user,
            'cms:page:read',
            context: $this->context('cms:page:read', domain: null, isApi: false),
        ));
    }

    public function testAUserGrantOverridesRoles(): void
    {
        $user = new TestUser();

        self::assertTrue($this->service(overrides: ['cms:page:publish' => true])->isGranted($user, 'cms:page:publish'));
    }

    /**
     * Le cas qui justifie la précédence : « tout le monde dans ce rôle, sauf cette personne ». Un
     * refus explicite doit battre un rôle qui accorde, sinon la surcharge ne sert à rien.
     */
    public function testAUserDenialOverridesAGrantingRole(): void
    {
        $user = new TestUser(roles: [self::ADMIN]);

        self::assertFalse($this->service(
            overrides: ['erp:invoice:validate' => false],
            rolePermissions: ['erp:invoice:validate'],
        )->isGranted($user, 'erp:invoice:validate'));
    }

    public function testARoleGrantPasses(): void
    {
        self::assertTrue($this->service(rolePermissions: ['sirh:payslip:read'])->isGranted(new TestUser(), 'sirh:payslip:read'));
    }

    /**
     * L'administrateur de tenant passe sur ce que le stockage n'accorde pas explicitement — c'est
     * ce qui rend un nouveau code de permission utilisable avant d'avoir été semé en base.
     */
    public function testATenantAdminPassesWithoutAnExplicitGrant(): void
    {
        self::assertTrue($this->service()->isGranted(new TestUser(roles: [self::ADMIN]), 'un:code:inconnu'));
    }

    /**
     * Mais pas sur le tenant d'autrui. C'est toute la différence entre un administrateur et un
     * lecteur inter-tenants.
     */
    public function testATenantAdminIsRefusedOnAnotherTenant(): void
    {
        $user = new TestUser(roles: [self::ADMIN], tenant: new TestTenant(1, 'acme'));

        self::assertFalse($this->service()->isGranted(
            $user,
            'cms:page:read',
            context: $this->context('cms:page:read', domain: 'autre-tenant', isApi: true),
        ));
        self::assertTrue($this->service()->isGranted(
            $user,
            'cms:page:read',
            context: $this->context('cms:page:read', domain: 'acme', isApi: true),
        ));
    }

    public function testATenantAdminWithoutATenantIsRefusedOnAnApiRequest(): void
    {
        $user = new TestUser(roles: [self::ADMIN]);

        self::assertFalse($this->service()->isGranted(
            $user,
            'cms:page:read',
            context: $this->context('cms:page:read', domain: 'acme', isApi: true),
        ));
    }

    public function testAnOrdinaryUserIsRefusedWhatNothingGrants(): void
    {
        self::assertFalse($this->service()->isGranted(new TestUser(roles: ['ROLE_USER']), 'cms:page:read'));
    }

    /**
     * ⚠️ Sans stockage branché, le moteur **refuse** — il n'ouvre pas. Un ACL dont le stockage
     * manque et qui laisserait passer est une faille invisible dans une suite verte.
     */
    public function testWithoutStorageOnlyAdminsPass(): void
    {
        $service = new PermissionDecisionService(self::ADMIN);

        self::assertFalse($service->isGranted(new TestUser(roles: ['ROLE_USER']), 'cms:page:read'));
        self::assertTrue($service->isGranted(new TestUser(roles: [self::ADMIN]), 'cms:page:read'));
        self::assertTrue($service->isGranted(new TestUser(superAdmin: true), 'cms:page:read'));
    }

    /**
     * Le nom du rôle vient de la configuration : le bundle ne suppose pas
     * `ROLE_ORGANIZATION_ADMIN`.
     */
    public function testTheTenantAdminRoleNameIsConfigured(): void
    {
        $service = new PermissionDecisionService('ROLE_WORKSPACE_OWNER');

        self::assertTrue($service->isGranted(new TestUser(roles: ['ROLE_WORKSPACE_OWNER']), 'cms:page:read'));
        self::assertFalse($service->isGranted(new TestUser(roles: [self::ADMIN]), 'cms:page:read'));
    }

    /**
     * @param array<string, bool> $overrides
     * @param list<string>        $rolePermissions
     */
    private function service(array $overrides = [], array $rolePermissions = []): PermissionDecisionService
    {
        return new PermissionDecisionService(
            self::ADMIN,
            new AclPermissionReadService(new InMemoryPermissionSetProvider($overrides, $rolePermissions)),
        );
    }

    private function context(string $permission, ?string $domain, bool $isApi): PermissionContext
    {
        return new PermissionContext($permission, 'x', 'y', $domain, null, $isApi);
    }
}
