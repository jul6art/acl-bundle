<?php

declare(strict_types=1);

namespace Jul6Art\AclBundle\Tests\DependencyInjection;

use Jul6Art\AclBundle\DependencyInjection\Configuration;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Config\Definition\Exception\InvalidConfigurationException;
use Symfony\Component\Config\Definition\Exception\InvalidTypeException;
use Symfony\Component\Config\Definition\Processor;

/**
 * The configuration tree is public API: an application writes against it and a rename breaks
 * someone's deployment. Assert the **whole** processed shape rather than one key at a time — that
 * is what makes an accidental addition or a changed default visible in a diff.
 */
#[CoversClass(Configuration::class)]
final class ConfigurationTest extends TestCase
{
    public function testItsRootNodeIsTheBundleAlias(): void
    {
        self::assertSame('acl', new Configuration()->getConfigTreeBuilder()->buildTree()->getName());
    }

    public function testItAppliesItsDefaults(): void
    {
        self::assertSame([
            'enabled' => true,
            'super_admin_role' => 'ROLE_SUPER_ADMIN',
            'tenant_admin_role' => 'ROLE_ORGANIZATION_ADMIN',
            'tenant_header' => 'X-TENANT',
            'tenant_request_attribute' => '_tenant',
            'tenant_route_parameters' => ['organization', 'organizationSlug', 'domain'],
            'route_permissions' => [],
            'context_listener_priority' => -10,
        ], $this->process([]));
    }

    public function testLaterConfigsOverrideEarlierOnes(): void
    {
        self::assertTrue($this->process([['enabled' => false], ['enabled' => true]])['enabled']);
    }

    /**
     * Les deux noms de rôles sont du contrat : un projet dont le rôle suprême s'appelle autrement
     * doit pouvoir le dire, et le bundle ne suppose jamais `ROLE_SUPER_ADMIN`.
     */
    public function testTheRoleNamesCanBeReplaced(): void
    {
        $config = $this->process([['super_admin_role' => 'ROLE_ROOT', 'tenant_admin_role' => 'ROLE_WORKSPACE_OWNER']]);

        self::assertSame('ROLE_ROOT', $config['super_admin_role']);
        self::assertSame('ROLE_WORKSPACE_OWNER', $config['tenant_admin_role']);
    }

    /**
     * ⚠️ Un nom de rôle vide serait accordé à tout le monde : `in_array('', $roles)` est faux, mais
     * une chaîne vide dans une configuration signale une variable d'environnement non résolue, et
     * le bundle doit refuser de démarrer plutôt que de tourner avec un rôle qui n'existe pas.
     */
    public function testAnEmptyRoleNameIsRefused(): void
    {
        $this->expectException(InvalidConfigurationException::class);

        $this->process([['super_admin_role' => '']]);
    }

    /**
     * A `booleanNode` refuses anything but a boolean, which is what you want — and the reason an
     * env var cannot gate service registration.
     */
    #[DataProvider('nonBooleanValues')]
    public function testItRejectsNonBooleanValues(mixed $value): void
    {
        $this->expectException(InvalidTypeException::class);

        $this->process([['enabled' => $value]]);
    }

    /**
     * @return iterable<string, array{mixed}>
     */
    public static function nonBooleanValues(): iterable
    {
        yield 'string' => ['yes'];
        yield 'int' => [0];
        yield 'array' => [[]];
    }

    /**
     * @param array<int, array<string, mixed>> $configs
     *
     * @return array<array-key, mixed>
     */
    private function process(array $configs): array
    {
        return new Processor()->processConfiguration(new Configuration(), $configs);
    }
}
