<?php

declare(strict_types=1);

namespace Jul6Art\AclBundle\Tests\Unit;

use Jul6Art\AclBundle\Security\AclPermissionReadService;
use Jul6Art\AclBundle\Tests\Fixtures\InMemoryPermissionSetProvider;
use Jul6Art\AclBundle\Tests\Fixtures\TestUser;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(AclPermissionReadService::class)]
final class AclPermissionReadServiceTest extends TestCase
{
    /**
     * La raison d'être de la classe, et la seule assertion qui la prouve : le nombre de lectures,
     * pas les réponses. Une page de formulaire CMS enchaîne une vingtaine de contrôles ; sans le
     * cache, ce sont quarante requêtes pour vingt booléens.
     */
    public function testTwentyChecksCostOneRead(): void
    {
        $provider = new InMemoryPermissionSetProvider(rolePermissions: ['cms:page:read']);
        $service = new AclPermissionReadService($provider);
        $user = new TestUser(id: 7);

        for ($i = 0; $i < 20; ++$i) {
            $service->isGrantedByRole($user, 'cms:page:read');
            $service->resolveUserOverride($user, 'cms:page:read');
        }

        self::assertSame(1, $provider->roleCalls);
        self::assertSame(1, $provider->overrideCalls);
    }

    public function testEachUserGetsItsOwnEntry(): void
    {
        $provider = new InMemoryPermissionSetProvider(rolePermissions: ['cms:page:read']);
        $service = new AclPermissionReadService($provider);

        $service->isGrantedByRole(new TestUser(id: 1), 'cms:page:read');
        $service->isGrantedByRole(new TestUser(id: 2), 'cms:page:read');

        self::assertSame(2, $provider->roleCalls);
    }

    /**
     * `false` n'est pas l'absence de surcharge : le premier refuse, le second laisse les rôles
     * décider. Confondre les deux fait disparaître la possibilité d'un refus explicite.
     */
    public function testAnExplicitDenialIsNotTheSameAsNoOverride(): void
    {
        $service = new AclPermissionReadService(new InMemoryPermissionSetProvider(['a:read' => false]));
        $user = new TestUser();

        self::assertFalse($service->resolveUserOverride($user, 'a:read'));
        self::assertNull($service->resolveUserOverride($user, 'b:read'));
    }

    /**
     * Le cas du worker : muter puis re-vérifier dans le même processus. Sur le web les deux sont
     * dans deux requêtes et le vidage est inutile ; en CLI le cache vit aussi longtemps que le
     * processus, et une permission fraîchement accordée resterait invisible.
     */
    public function testFlushingOneUserForcesARead(): void
    {
        $provider = new InMemoryPermissionSetProvider();
        $service = new AclPermissionReadService($provider);
        $user = new TestUser(id: 7);

        $service->resolveUserOverride($user, 'a:read');
        $provider->setOverride('a:read', true);
        self::assertNull($service->resolveUserOverride($user, 'a:read'), 'Le cache doit tenir tant qu\'on ne le vide pas.');

        $service->flush($user);

        self::assertTrue($service->resolveUserOverride($user, 'a:read'));
        self::assertSame(2, $provider->overrideCalls);
    }

    public function testFlushingEverythingForcesAReadForEveryUser(): void
    {
        $provider = new InMemoryPermissionSetProvider();
        $service = new AclPermissionReadService($provider);

        $service->resolveUserOverride(new TestUser(id: 1), 'a:read');
        $service->resolveUserOverride(new TestUser(id: 2), 'a:read');
        $service->flush();
        $service->resolveUserOverride(new TestUser(id: 1), 'a:read');

        self::assertSame(3, $provider->overrideCalls);
    }

    /**
     * ⚠️ Un utilisateur non persisté n'a pas d'identité stable. Le mettre en cache ferait partager
     * son jeu de permissions avec le suivant — exactement le défaut qu'un ACL ne peut pas se
     * permettre. Il n'est donc jamais interrogé et n'a rien.
     */
    public function testAnUnpersistedUserIsNeverCachedAndHasNothing(): void
    {
        $provider = new InMemoryPermissionSetProvider(['a:read' => true], ['b:read']);
        $service = new AclPermissionReadService($provider);
        $user = new TestUser(id: null);

        self::assertNull($service->resolveUserOverride($user, 'a:read'));
        self::assertFalse($service->isGrantedByRole($user, 'b:read'));
        self::assertSame(0, $provider->overrideCalls, 'Le stockage n\'a rien à dire d\'un utilisateur sans identité.');
    }
}
