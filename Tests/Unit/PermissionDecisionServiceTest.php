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

    // ── La borne de l'administrateur de locataire : les refus de sa colonne ────

    /**
     * Le trou de cereezer (2026-11-13) : la grille montrait la case décochée, le moteur laissait passer
     * l'administrateur quand même. « Granted anything its tenant is not explicitly denied » — un refus écrit
     * dans la colonne de son rôle le borne.
     */
    public function testATenantAdminIsBoundByWhatTheGridRefusesTheirRole(): void
    {
        $service = $this->service(refusals: [self::ADMIN => ['permission:read']]);
        $admin = new TestUser(roles: [self::ADMIN], tenant: new TestTenant(1, 'globex'));

        self::assertFalse($service->isGranted($admin, 'permission:read'));
        self::assertTrue($service->isGranted($admin, 'un:code:jamais:decide'), 'Jamais décidé : il passe toujours.');
    }

    public function testTheRefusalHoldsOnTheApiOfTheirOwnTenantToo(): void
    {
        $service = $this->service(refusals: [self::ADMIN => ['permission:read']]);
        $admin = new TestUser(roles: [self::ADMIN], tenant: new TestTenant(1, 'globex'));

        self::assertFalse($service->isGranted($admin, 'permission:read', context: $this->context('permission:read', domain: 'globex', isApi: true)));
        self::assertTrue($service->isGranted($admin, 'user:read', context: $this->context('user:read', domain: 'globex', isApi: true)));
    }

    /**
     * Seule la colonne du rôle d'administration compte : un refus écrit pour `ROLE_USER`, que l'administrateur
     * porte aussi, ne le borne pas — la grille de plateforme garde ses cases décochées à `false`, et chacune
     * retirerait alors à l'administrateur ce que son propre rôle n'a jamais refusé.
     */
    public function testARefusalToAnotherRoleTheAdminHoldsDoesNotBindThem(): void
    {
        $service = $this->service(refusals: ['ROLE_USER' => ['user:read']]);

        self::assertTrue($service->isGranted(new TestUser(roles: [self::ADMIN, 'ROLE_USER']), 'user:read'));
    }

    /** L'ordre des règles tient : la surcharge du compte (4) et l'octroi d'un autre de ses rôles (5) passent avant. */
    public function testAnOverrideOrAnotherRolesGrantStillWins(): void
    {
        $refused = [self::ADMIN => ['invoice:read']];
        $admin = new TestUser(roles: [self::ADMIN, 'ROLE_ACCOUNTANT']);

        self::assertTrue($this->service(overrides: ['invoice:read' => true], refusals: $refused)->isGranted($admin, 'invoice:read'));
        self::assertTrue($this->service(rolePermissions: ['invoice:read'], refusals: $refused)->isGranted($admin, 'invoice:read'));
    }

    /** Un refus fait à la colonne d'administration ne touche que l'administrateur, jamais le super-admin. */
    public function testASuperAdminIsNeverBoundByTheGrid(): void
    {
        $service = $this->service(refusals: [self::ADMIN => ['permission:read']]);

        self::assertTrue($service->isGranted(new TestUser(roles: [self::ADMIN], superAdmin: true), 'permission:read'));
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

    // ── Applications sans locataires ─────────────────────────────────────────

    /**
     * ⚠️ Le défaut qui a motivé `multi_tenant`.
     *
     * En multi-locataire, une requête d'API dont le locataire n'est pas résolu est refusée — un
     * repli implicite transformerait un en-tête oublié en collection inter-locataires. Dans une
     * application qui n'a PAS de locataires, `TenantResolver` ne peut répondre que `null`, et cette
     * même règle refuse alors toute vérification derrière `/api/` à quiconque n'est pas super-admin.
     *
     * Le symptôme est une datatable vide et un 403 en console pour un administrateur qui détient
     * pourtant la permission, et rien dans une suite de tests ne le montre.
     */
    public function testAnApiRequestWithoutTenantIsRefusedInMultiTenantMode(): void
    {
        $service = $this->service(rolePermissions: ['cms:page:read']);

        self::assertFalse($service->isGranted(
            new TestUser(roles: ['ROLE_USER']),
            'cms:page:read',
            context: $this->context('cms:page:read', null, true),
        ));
    }

    public function testTheSameRequestPassesInSingleTenantMode(): void
    {
        $service = $this->service(rolePermissions: ['cms:page:read'], multiTenant: false);

        self::assertTrue($service->isGranted(
            new TestUser(roles: ['ROLE_USER']),
            'cms:page:read',
            context: $this->context('cms:page:read', null, true),
        ));
    }

    /**
     * En mono-locataire, l'administrateur de locataire passe sans qu'on lui compare un locataire
     * que personne ne peut résoudre. La clause de la règle 6 ne s'applique plus.
     */
    public function testTheTenantAdminPassesOnAnApiRequestInSingleTenantMode(): void
    {
        $service = $this->service(multiTenant: false);

        self::assertTrue($service->isGranted(
            new TestUser(roles: [self::ADMIN]),
            'cms:page:read',
            context: $this->context('cms:page:read', 'someone-else', true),
        ));
    }

    /** Le défaut reste strict : une application multi-locataire ne change pas de comportement. */
    public function testMultiTenantIsTheDefault(): void
    {
        $service = new PermissionDecisionService(self::ADMIN);

        self::assertFalse($service->isGranted(
            new TestUser(roles: [self::ADMIN]),
            'cms:page:read',
            context: $this->context('cms:page:read', null, true),
        ));
    }

    /**
     * @param array<string, bool>         $overrides
     * @param list<string>                $rolePermissions
     * @param array<string, list<string>> $refusals
     */
    private function service(array $overrides = [], array $rolePermissions = [], bool $multiTenant = true, array $refusals = []): PermissionDecisionService
    {
        return new PermissionDecisionService(
            self::ADMIN,
            new AclPermissionReadService(new InMemoryPermissionSetProvider($overrides, $rolePermissions, $refusals)),
            $multiTenant,
        );
    }

    private function context(string $permission, ?string $domain, bool $isApi): PermissionContext
    {
        return new PermissionContext($permission, 'x', 'y', $domain, null, $isApi);
    }
}
