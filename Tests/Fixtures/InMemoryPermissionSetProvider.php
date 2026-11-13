<?php

declare(strict_types=1);

namespace Jul6Art\AclBundle\Tests\Fixtures;

use Jul6Art\AclBundle\Contract\AclUserInterface;
use Jul6Art\AclBundle\Contract\PermissionSetProviderInterface;
use Jul6Art\AclBundle\Contract\RoleRefusalProviderInterface;

/**
 * Storage in an array, plus a call counter — the counter is the point.
 *
 * `AclPermissionReadService` exists to turn N permission checks into one read. Asserting on the
 * answers proves nothing about that; asserting on how many times the provider was asked does.
 */
final class InMemoryPermissionSetProvider implements PermissionSetProviderInterface, RoleRefusalProviderInterface
{
    public int $overrideCalls = 0;

    public int $roleCalls = 0;

    public int $refusalCalls = 0;

    /**
     * @param array<string, bool>         $overrides
     * @param list<string>                $rolePermissions
     * @param array<string, list<string>> $refusals        role → the codes explicitly refused to it
     */
    public function __construct(
        private array $overrides = [],
        private array $rolePermissions = [],
        private array $refusals = [],
    ) {
    }

    /**
     * @return array<string, bool>
     */
    #[\Override]
    public function overridesFor(AclUserInterface $user): array
    {
        ++$this->overrideCalls;

        return $this->overrides;
    }

    /**
     * @return list<string>
     */
    #[\Override]
    public function grantedByRolesFor(AclUserInterface $user): array
    {
        ++$this->roleCalls;

        return $this->rolePermissions;
    }

    /**
     * @return list<string>
     */
    #[\Override]
    public function refusedToRoleFor(string $role, AclUserInterface $user): array
    {
        ++$this->refusalCalls;

        return $this->refusals[$role] ?? [];
    }

    public function setOverride(string $permission, bool $decision): void
    {
        $this->overrides[$permission] = $decision;
    }

    /**
     * Pour les scénarios où le fournisseur est instancié par le conteneur : ses jeux se posent
     * après coup, sans que le test contourne le câblage qu'il est censé exercer.
     *
     * @param list<string> $rolePermissions
     */
    public function setRolePermissions(array $rolePermissions): void
    {
        $this->rolePermissions = $rolePermissions;
    }
}
