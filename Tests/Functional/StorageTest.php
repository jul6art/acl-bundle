<?php

declare(strict_types=1);

namespace Jul6Art\AclBundle\Tests\Functional;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Jul6Art\AclBundle\Command\SeedPermissionsCommand;
use Jul6Art\AclBundle\Contract\PermissionCatalogInterface;
use Jul6Art\AclBundle\Contract\PermissionSetProviderInterface;
use Jul6Art\AclBundle\Contract\PermissionStoreInterface;
use Jul6Art\AclBundle\Doctrine\DoctrinePermissionSetProvider;
use Jul6Art\AclBundle\Doctrine\DoctrinePermissionStore;
use Jul6Art\AclBundle\Doctrine\PermissionSeeder;
use Jul6Art\AclBundle\Tests\Fixtures\Entity\Account;
use Jul6Art\AclBundle\Tests\Fixtures\Entity\RolePermission;
use Jul6Art\AclBundle\Tests\Fixtures\Entity\Tenant;
use Jul6Art\AclBundle\Tests\Fixtures\Entity\TenantRolePermission;
use Jul6Art\AclBundle\Tests\Fixtures\Entity\TenantUserPermissionOverride;
use Jul6Art\AclBundle\Tests\Fixtures\Entity\UserPermissionOverride;
use Jul6Art\AclBundle\Tests\Fixtures\Storage\Catalog;
use Jul6Art\AclBundle\Tests\Fixtures\Storage\QueryCounter;
use PHPUnit\Framework\Attributes\CoversClass;
use Symfony\Component\Console\Command\LazyCommand;
use Symfony\Component\Console\CommandLoader\CommandLoaderInterface;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\Security\Core\Role\RoleHierarchy;

/**
 * The permissions in Doctrine (ADR-0001), on a real database through the real container: one grid for
 * everyone, the grid by tenant (cereezer), the overrides by tenant (cegeta), the seed that never reopens a
 * refusal, the command under its old name, the role hierarchy (devinlive).
 */
#[CoversClass(DoctrinePermissionStore::class)]
#[CoversClass(DoctrinePermissionSetProvider::class)]
#[CoversClass(PermissionSeeder::class)]
#[CoversClass(SeedPermissionsCommand::class)]
final class StorageTest extends AbstractFunctionalTestCase
{
    private EntityManagerInterface $entityManager;

    public function testOffByDefaultTheApplicationKeepsItsOwnStorage(): void
    {
        $container = $this->boot('test', [], withOrm: true, withSecurity: true);

        self::assertFalse($container->has(DoctrinePermissionStore::class));
        self::assertFalse($container->has(PermissionSetProviderInterface::class));
    }

    public function testAStorageWithoutItsClassesIsAConfigurationError(): void
    {
        $this->expectExceptionMessageIsOrContains('acl.storage needs user_class');

        $this->boot('test', ['storage' => ['enabled' => true, 'user_class' => Account::class]], withOrm: true);
    }

    public function testTheEngineReadsTheRolesAndTheAccountsOwnDecisions(): void
    {
        $container = $this->storage();
        $this->seeder($container)->seed();
        $store = $this->store($container);
        $ada = $this->account(['ROLE_USER']);

        self::assertTrue($store->grantToUser($ada, 'user.read'));
        $this->entityManager->flush();
        self::assertFalse($store->grantToUser($ada, 'user.read'), 'Already granted: nothing changed.');
        self::assertTrue($store->denyToUser($ada, 'invoice.read'));
        $this->entityManager->flush();

        $provider = $container->get(PermissionSetProviderInterface::class);
        self::assertInstanceOf(DoctrinePermissionSetProvider::class, $provider);
        self::assertSame(['invoice.read'], $provider->grantedByRolesFor($ada));
        self::assertSame(['user.read' => true, 'invoice.read' => false], $provider->overridesFor($ada));

        self::assertTrue($store->removeUserOverride($ada, 'invoice.read'));
        $this->entityManager->flush();
        self::assertSame(['user.read' => true], $store->overridesFor($ada));
        self::assertSame($store, $container->get(PermissionStoreInterface::class), 'Delegation writes through the same store.');
    }

    public function testTheSeedNeverReopensACellAnAdministratorUnticked(): void
    {
        $container = $this->storage();
        $seeder = $this->seeder($container);
        $store = $this->store($container);

        self::assertSame(4, $seeder->seed());
        self::assertTrue($store->revokeFromRole('ROLE_ADMIN', 'user.update', null));
        $this->entityManager->flush();

        self::assertSame(0, $seeder->seed(), 'Every default is decided: nothing to write.');
        self::assertEqualsCanonicalizing(['user.read', 'invoice.read'], $store->grantedForRoles(['ROLE_ADMIN']));
        self::assertFalse($store->revokeFromRole('ROLE_ADMIN', 'user.update', null), 'Kept at false, never removed.');
        self::assertTrue($store->grantToRole('ROLE_ADMIN', 'user.update', null), 'Granting again is a decision too.');
    }

    public function testTheCommandAnswersToTheNameTheDeploymentHooksCall(): void
    {
        $container = $this->storage();
        $loader = $container->get('console.command_loader');
        self::assertInstanceOf(CommandLoaderInterface::class, $loader);
        $lazy = $loader->get('app:permissions:seed');
        self::assertInstanceOf(LazyCommand::class, $lazy);
        $command = $lazy->getCommand();
        self::assertInstanceOf(SeedPermissionsCommand::class, $command);
        self::assertSame('acl:permissions:seed', $command->getName());

        $tester = new CommandTester($command);
        $tester->execute([]);
        $tester->execute([]);

        self::assertStringContainsString('0 default permission(s) written', $tester->getDisplay());
        self::assertSame(['invoice.read'], $this->store($container)->grantedForRoles(['ROLE_USER']));
    }

    public function testATenantsRowWinsOverThePlatformsBothWays(): void
    {
        $container = $this->storage(['role_permission_class' => TenantRolePermission::class, 'role_scope' => 'tenant']);
        $this->seeder($container)->seed();
        $store = $this->store($container);
        [$acme, $globex] = [$this->tenant('acme'), $this->tenant('globex')];

        // Taken back at Acme what the platform grants: written out, though Acme had no row.
        self::assertTrue($store->revokeFromRole('ROLE_USER', 'invoice.read', $acme));
        self::assertTrue($store->grantToRole('ROLE_USER', 'user.read', $acme));
        $this->entityManager->flush();

        self::assertSame(['user.read'], $store->grantedForRoles(['ROLE_USER'], $acme));
        self::assertSame(['invoice.read'], $store->grantedForRoles(['ROLE_USER'], $globex), 'Another tenant follows the platform.');
        self::assertSame(['invoice.read'], $store->grantedForRoles(['ROLE_USER']), 'The platform does not move.');
        self::assertFalse($store->revokeFromRole('ROLE_USER', 'invoice.read', $acme), 'Already refused at Acme.');

        $provider = $container->get(PermissionSetProviderInterface::class);
        self::assertInstanceOf(PermissionSetProviderInterface::class, $provider);
        self::assertSame(['user.read'], $provider->grantedByRolesFor($this->account(['ROLE_USER'], $acme)), 'The engine reads the account\'s tenant.');
    }

    public function testATenantsGridWritesOnlyWhatDiffersFromThePlatform(): void
    {
        $container = $this->storage(['role_permission_class' => TenantRolePermission::class, 'role_scope' => 'tenant']);
        $this->seeder($container)->seed();
        $store = $this->store($container);
        $acme = $this->tenant('acme');
        $codes = ['user.read', 'user.update', 'invoice.read', 'invoice.cancel'];

        // ROLE_USER at Acme: invoice.read untouched (the default), user.read added.
        $store->saveRoleGrid(['ROLE_USER'], $codes, ['ROLE_USER' => ['invoice.read', 'user.read']], $acme);
        $this->entityManager->flush();
        self::assertSame(1, $this->rowsOf($acme), 'One difference, one row: the rest follows the platform.');
        $matrix = $store->grantedMatrix(['ROLE_USER'], $acme);
        self::assertEqualsCanonicalizing(['invoice.read', 'user.read'], $matrix['ROLE_USER'] ?? null);

        // Back to the default: the tenant's row goes.
        $store->saveRoleGrid(['ROLE_USER'], $codes, ['ROLE_USER' => ['invoice.read']], $acme);
        $this->entityManager->flush();
        self::assertSame(0, $this->rowsOf($acme));

        // On the platform, the grid grants and takes back.
        $store->saveRoleGrid(['ROLE_USER'], $codes, ['ROLE_USER' => []], null);
        $this->entityManager->flush();
        self::assertSame([], $store->grantedForRoles(['ROLE_USER'], $acme), 'Acme follows the platform\'s refusal.');
    }

    public function testAGridIsSavedInOneReadWhateverItsSize(): void
    {
        foreach ([[[], null], [['role_permission_class' => TenantRolePermission::class, 'role_scope' => 'tenant'], 'acme']] as [$storage, $slug]) {
            $container = $this->storage($storage);
            $this->seeder($container)->seed();
            $store = $this->store($container);
            $tenant = null === $slug ? null : $this->tenant($slug);
            $codes = ['user.read', 'user.update', 'invoice.read', 'invoice.cancel'];

            QueryCounter::$queries = 0;
            $store->saveRoleGrid(['ROLE_ADMIN', 'ROLE_USER'], $codes, ['ROLE_ADMIN' => ['user.read', 'invoice.cancel'], 'ROLE_USER' => []], $tenant);
            self::assertSame(1, QueryCounter::$queries, 'One read, then the unit of work: never a query per cell.');
            $this->entityManager->flush();

            self::assertEqualsCanonicalizing(['user.read', 'invoice.cancel'], $store->grantedForRoles(['ROLE_ADMIN'], $tenant));
            self::assertSame([], $store->grantedForRoles(['ROLE_USER'], $tenant));
        }
    }

    public function testAnOverrideBelongsToTheAccountsCurrentTenant(): void
    {
        $container = $this->storage(['user_permission_override_class' => TenantUserPermissionOverride::class, 'override_scope' => 'tenant']);
        $store = $this->store($container);
        [$acme, $globex] = [$this->tenant('acme'), $this->tenant('globex')];
        $ada = $this->account(['ROLE_USER'], $acme);

        self::assertTrue($store->grantToUser($ada, 'user.update'));
        $this->entityManager->flush();
        self::assertSame(['user.update' => true], $store->overridesFor($ada));

        $ada->moveTo($globex);
        self::assertSame([], $store->overridesFor($ada), 'Acme\'s decision does not follow her to Globex.');
        self::assertFalse($store->removeUserOverride($ada, 'user.update'), 'Nor can it be removed from there.');

        $ada->moveTo($acme);
        self::assertSame(['user.update' => true], $store->overridesFor($ada));
    }

    public function testTheRoleHierarchyGrantsWhatAReachedRoleHolds(): void
    {
        $container = $this->storage();
        $this->seeder($container)->seed();
        $store = $this->store($container);
        $store->revokeFromRole('ROLE_ADMIN', 'invoice.read', null);
        $this->entityManager->flush();
        $admin = $this->account(['ROLE_ADMIN']);

        $flat = new DoctrinePermissionSetProvider($store);
        $hierarchy = new DoctrinePermissionSetProvider($store, new RoleHierarchy(['ROLE_ADMIN' => ['ROLE_USER']]));

        self::assertEqualsCanonicalizing(['user.read', 'user.update'], $flat->grantedByRolesFor($admin));
        $reached = $hierarchy->grantedByRolesFor($admin);
        sort($reached);
        self::assertSame(['invoice.read', 'user.read', 'user.update'], $reached, 'Refused to ROLE_ADMIN, held by ROLE_USER it reaches.');
    }

    public function testTheHierarchyIsWiredOnlyWhenAsked(): void
    {
        $flat = $this->storage()->get(PermissionSetProviderInterface::class);
        self::assertInstanceOf(DoctrinePermissionSetProvider::class, $flat);
        self::assertNull(new \ReflectionProperty($flat, 'roleHierarchy')->getValue($flat));

        $withHierarchy = $this->storage(['role_hierarchy' => true])->get(PermissionSetProviderInterface::class);
        self::assertInstanceOf(DoctrinePermissionSetProvider::class, $withHierarchy);
        self::assertNotNull(new \ReflectionProperty($withHierarchy, 'roleHierarchy')->getValue($withHierarchy));
    }

    /**
     * @param array<string, mixed> $storage
     */
    private function storage(array $storage = []): ContainerInterface
    {
        $container = $this->boot('test', ['storage' => $storage + [
            'enabled' => true,
            'user_class' => Account::class,
            'role_permission_class' => RolePermission::class,
            'user_permission_override_class' => UserPermissionOverride::class,
            'catalog' => Catalog::class,
        ]], withOrm: true, withSecurity: true, contracts: [PermissionCatalogInterface::class => Catalog::class]);

        $entityManager = $container->get('doctrine.orm.default_entity_manager');
        self::assertInstanceOf(EntityManagerInterface::class, $entityManager);
        $this->entityManager = $entityManager;
        new SchemaTool($entityManager)->createSchema($entityManager->getMetadataFactory()->getAllMetadata());

        return $container;
    }

    private function store(ContainerInterface $container): DoctrinePermissionStore
    {
        $store = $container->get(DoctrinePermissionStore::class);
        self::assertInstanceOf(DoctrinePermissionStore::class, $store);

        return $store;
    }

    private function seeder(ContainerInterface $container): PermissionSeeder
    {
        $seeder = $container->get(PermissionSeeder::class);
        self::assertInstanceOf(PermissionSeeder::class, $seeder);

        return $seeder;
    }

    /**
     * @param list<string> $roles
     */
    private function account(array $roles, ?Tenant $tenant = null): Account
    {
        $account = new Account($roles, $tenant);
        $this->entityManager->persist($account);
        $this->entityManager->flush();

        return $account;
    }

    private function tenant(string $slug): Tenant
    {
        $tenant = new Tenant($slug);
        $this->entityManager->persist($tenant);
        $this->entityManager->flush();

        return $tenant;
    }

    private function rowsOf(Tenant $tenant): int
    {
        return $this->entityManager->getRepository(TenantRolePermission::class)->count(['organization' => $tenant]);
    }
}
