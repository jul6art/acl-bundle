<?php

declare(strict_types=1);

namespace Jul6Art\AclBundle\Doctrine;

use Jul6Art\AclBundle\Contract\AclUserInterface;
use Jul6Art\AclBundle\Contract\PermissionSetProviderInterface;
use Jul6Art\AclBundle\Contract\RoleRefusalProviderInterface;
use Symfony\Component\Security\Core\Role\RoleHierarchyInterface;

use function array_values;

/**
 * Where the engine reads from (ADR-0001): an account's overrides, what its roles grant — in its tenant when
 * the roles vary by tenant —, and what a role is explicitly refused there (the tenant administrator's bound).
 *
 * ⚠️ Both methods are called once per account and per request, not once per check: `AclPermissionReadService`
 * keeps the answer. One full read each, never one per permission.
 *
 * With the role hierarchy (`acl.storage.role_hierarchy`), the roles an account reaches count too: an
 * administrator holds what `ROLE_USER` holds. Off by default — turning it on where every role's defaults are
 * written out in full would change decisions (a permission refused to a role, granted to a role it reaches).
 */
final readonly class DoctrinePermissionSetProvider implements PermissionSetProviderInterface, RoleRefusalProviderInterface
{
    public function __construct(
        private DoctrinePermissionStore $store,
        private ?RoleHierarchyInterface $roleHierarchy = null,
    ) {
    }

    /**
     * @return array<string, bool>
     */
    #[\Override]
    public function overridesFor(AclUserInterface $user): array
    {
        return $this->store->overridesFor($user);
    }

    /**
     * @return list<string>
     */
    #[\Override]
    public function grantedByRolesFor(AclUserInterface $user): array
    {
        /** @var list<string> $roles */
        $roles = array_values($user->getRoles());
        if ($this->roleHierarchy instanceof RoleHierarchyInterface) {
            $roles = array_values($this->roleHierarchy->getReachableRoleNames($roles));
        }

        return $this->store->grantedForRoles($roles, $user->getTenant());
    }

    /**
     * The role's own column only: the hierarchy does not apply — a refusal written for `ROLE_USER` does not
     * bound an administrator who reaches it.
     *
     * @return list<string>
     */
    #[\Override]
    public function refusedToRoleFor(string $role, AclUserInterface $user): array
    {
        return $this->store->refusedForRoles([$role], $user->getTenant());
    }
}
