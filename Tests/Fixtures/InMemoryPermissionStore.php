<?php

declare(strict_types=1);

namespace Jul6Art\AclBundle\Tests\Fixtures;

use Jul6Art\AclBundle\Contract\AclTenantInterface;
use Jul6Art\AclBundle\Contract\AclUserInterface;
use Jul6Art\AclBundle\Contract\PermissionStoreInterface;

/**
 * Records every write, so a delegation test can assert that a refused call wrote **nothing**.
 *
 * That is the assertion that matters: a guard which throws after having already written is not a
 * guard, and only a store that remembers can tell the difference.
 */
final class InMemoryPermissionStore implements PermissionStoreInterface
{
    /**
     * @var list<array{action: string, permission: string, target: string}>
     */
    public array $writes = [];

    /**
     * @param list<string> $actorPermissions permissions the acting user's roles grant
     */
    public function __construct(
        private readonly array $actorPermissions = [],
    ) {
    }

    #[\Override]
    public function grantToUser(AclUserInterface $target, string $permission): bool
    {
        return $this->record('grantToUser', $permission, (string) $target->getId());
    }

    #[\Override]
    public function denyToUser(AclUserInterface $target, string $permission): bool
    {
        return $this->record('denyToUser', $permission, (string) $target->getId());
    }

    #[\Override]
    public function removeUserOverride(AclUserInterface $target, string $permission): bool
    {
        return $this->record('removeUserOverride', $permission, (string) $target->getId());
    }

    /**
     * @param list<string> $roles
     */
    #[\Override]
    public function isGrantedForRoles(array $roles, string $permission, ?AclTenantInterface $tenant): bool
    {
        return \in_array($permission, $this->actorPermissions, true);
    }

    #[\Override]
    public function grantToRole(string $roleCode, string $permission, ?AclTenantInterface $tenant): bool
    {
        return $this->record('grantToRole', $permission, $roleCode);
    }

    #[\Override]
    public function revokeFromRole(string $roleCode, string $permission, ?AclTenantInterface $tenant): bool
    {
        return $this->record('revokeFromRole', $permission, $roleCode);
    }

    private function record(string $action, string $permission, string $target): bool
    {
        $this->writes[] = ['action' => $action, 'permission' => $permission, 'target' => $target];

        return true;
    }
}
