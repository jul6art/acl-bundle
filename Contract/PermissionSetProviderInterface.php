<?php

declare(strict_types=1);

namespace Jul6Art\AclBundle\Contract;

/**
 * Where a user's permissions are read from — the one thing this bundle cannot provide.
 *
 * Storage is the application's: two Doctrine tables here, an LDAP tree there, a static map in a
 * small project. So the engine asks for two sets and does not care how they were obtained:
 *
 * ```php
 * final class DoctrinePermissionSetProvider implements PermissionSetProviderInterface
 * {
 *     public function __construct(
 *         private readonly RolePermissionRepository $rolePermissions,
 *         private readonly UserPermissionOverrideRepository $overrides,
 *     ) {}
 *
 *     public function overridesFor(AclUserInterface $user): array
 *     {
 *         return $this->overrides->findAllForUser($user);
 *     }
 *
 *     public function grantedByRolesFor(AclUserInterface $user): array
 *     {
 *         return $this->rolePermissions->findGrantedPermissionsForRoles($user->getRoles(), $user->getTenant());
 *     }
 * }
 * ```
 *
 * > ⚠️ **Both methods are called once per user per request, not once per check.**
 * > `AclPermissionReadService` caches them in memory, which is what keeps a page with twenty
 * > permission checks at two queries instead of forty. An implementation may therefore be as
 * > expensive as a single full read, and must not be lazy per permission.
 *
 * > ⚠️ **Wire this, or the engine denies.** With no provider registered, no override and no
 * > role-granted permission is ever found, and only a super admin gets through. That is the
 * > intended failure mode — an ACL that defaults to "allow" when its storage is missing is a
 * > security hole, and one that is invisible in a passing test suite.
 */
interface PermissionSetProviderInterface
{
    /**
     * Per-user decisions that beat everything a role says: true grants, false denies.
     *
     * @return array<string, bool> permission code → decision
     */
    public function overridesFor(AclUserInterface $user): array;

    /**
     * Every permission the user's roles grant, in this user's tenant.
     *
     * @return list<string>
     */
    public function grantedByRolesFor(AclUserInterface $user): array;
}
