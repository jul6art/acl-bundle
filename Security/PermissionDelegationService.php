<?php

declare(strict_types=1);

namespace Jul6Art\AclBundle\Security;

use Jul6Art\AclBundle\Contract\AclTenantInterface;
use Jul6Art\AclBundle\Contract\AclUserInterface;
use Jul6Art\AclBundle\Contract\PermissionStoreInterface;

/**
 * Lets an administrator hand a permission to someone else — and only a permission they hold.
 *
 * ```php
 * $delegation->grantToUser($actor, $target, 'cms:page:publish');
 * $delegation->denyToUser($actor, $target, 'erp:invoice:validate');   // an explicit refusal
 * $delegation->removeUserOverride($actor, $target, 'cms:page:publish');
 * ```
 *
 * Every method throws `\DomainException` when the actor may not do it, and the message says which
 * rule stopped them. Throwing rather than returning false is the point: "the grant did not happen"
 * and "the grant was refused" must not be the same value at a call site, or a UI ends up reporting
 * success for a privilege escalation it just blocked.
 *
 * ## The five rules, and what each prevents
 *
 * 1. **A super admin may do anything.** Checked first, so support is never locked out of a broken
 *    tenant.
 * 2. **Nobody delegates to themselves.** Otherwise the whole model collapses: an administrator
 *    would simply grant themselves the permission they lack.
 * 3. **Only a tenant administrator delegates at all.**
 * 4. **Only inside their own tenant.** Compared on tenant id, and two nulls are *not* a match — a
 *    user without a tenant is not "in the same tenant" as another user without one.
 * 5. **Only a permission the actor holds**, checked against their roles in their own tenant. This
 *    is the escalation guard, and it is why the store exposes `isGrantedForRoles()`.
 *
 * ## Role templates are stricter
 *
 * Granting a permission to a *role* changes it for everyone holding that role. With a role model
 * that is not itself tenant-scoped, that is a cross-tenant write — so a tenant administrator must
 * name a tenant, may never touch the super-admin role, and still only delegates what they hold.
 */
final readonly class PermissionDelegationService
{
    public function __construct(
        private PermissionStoreInterface $store,
        private string $tenantAdminRole,
        private string $superAdminRole,
    ) {
    }

    /**
     * @throws \DomainException when the actor may not delegate this permission
     */
    public function grantToUser(AclUserInterface $actor, AclUserInterface $target, string $permission): bool
    {
        $this->assertCanDelegate($actor, $target, $permission);

        return $this->store->grantToUser($target, $permission);
    }

    /**
     * An explicit denial, which outranks anything the target's roles grant.
     *
     * @throws \DomainException when the actor may not delegate this permission
     */
    public function denyToUser(AclUserInterface $actor, AclUserInterface $target, string $permission): bool
    {
        $this->assertCanDelegate($actor, $target, $permission);

        return $this->store->denyToUser($target, $permission);
    }

    /**
     * Removes an override, letting the target's roles decide again.
     *
     * @throws \DomainException when the actor may not delegate this permission
     */
    public function removeUserOverride(AclUserInterface $actor, AclUserInterface $target, string $permission): bool
    {
        $this->assertCanDelegate($actor, $target, $permission, 'Cannot revoke a permission you do not have');

        return $this->store->removeUserOverride($target, $permission);
    }

    /**
     * @throws \DomainException when the actor may not change this role
     */
    public function grantToRole(AclUserInterface $actor, string $roleCode, string $permission, ?AclTenantInterface $tenant = null): bool
    {
        $this->assertCanDelegateToRole($actor, $roleCode, $permission, $tenant);

        return $this->store->grantToRole($roleCode, $permission, $tenant);
    }

    /**
     * @throws \DomainException when the actor may not change this role
     */
    public function revokeFromRole(AclUserInterface $actor, string $roleCode, string $permission, ?AclTenantInterface $tenant = null): bool
    {
        $this->assertCanDelegateToRole($actor, $roleCode, $permission, $tenant);

        return $this->store->revokeFromRole($roleCode, $permission, $tenant);
    }

    private function assertCanDelegate(
        AclUserInterface $actor,
        AclUserInterface $target,
        string $permission,
        string $missingPermissionMessage = 'Cannot delegate a permission you do not have',
    ): void {
        if ($actor->isSuperAdmin()) {
            return;
        }

        if (null !== $actor->getId() && $actor->getId() === $target->getId()) {
            throw new \DomainException('Cannot delegate permissions to yourself');
        }

        if (!\in_array($this->tenantAdminRole, $actor->getRoles(), true)) {
            throw new \DomainException('Only tenant administrators can delegate permissions');
        }

        if (!$this->isSameTenant($actor, $target)) {
            throw new \DomainException('Cannot delegate a permission outside your own tenant');
        }

        if (!$this->store->isGrantedForRoles($actor->getRoles(), $permission, $actor->getTenant())) {
            throw new \DomainException($missingPermissionMessage);
        }
    }

    private function assertCanDelegateToRole(
        AclUserInterface $actor,
        string $roleCode,
        string $permission,
        ?AclTenantInterface $tenant,
    ): void {
        if ($actor->isSuperAdmin()) {
            return;
        }

        if (!\in_array($this->tenantAdminRole, $actor->getRoles(), true)) {
            throw new \DomainException('Role permission delegation is restricted to administrators');
        }

        if ($roleCode === $this->superAdminRole) {
            throw new \DomainException('Cannot modify super admin role permissions');
        }

        // Sans tenant nommé, l'écriture porterait sur le gabarit de rôle global — donc sur tous les
        // tenants à la fois. C'est précisément l'escalade que cette classe existe pour empêcher.
        if (!$tenant instanceof AclTenantInterface) {
            throw new \DomainException('A tenant is required for role permission delegation');
        }

        if ('' !== $permission && !$this->store->isGrantedForRoles($actor->getRoles(), $permission, $actor->getTenant())) {
            throw new \DomainException('Cannot delegate a permission you do not have');
        }
    }

    /**
     * Compared on identity then on id. Two users without a tenant are **not** in the same tenant:
     * treating null as a match would let two unassigned accounts delegate to each other.
     */
    private function isSameTenant(AclUserInterface $actor, AclUserInterface $target): bool
    {
        $actorTenant = $actor->getTenant();
        $targetTenant = $target->getTenant();

        if (!$actorTenant instanceof AclTenantInterface || !$targetTenant instanceof AclTenantInterface) {
            return false;
        }

        if ($actorTenant === $targetTenant) {
            return true;
        }

        return null !== $actorTenant->getId() && $actorTenant->getId() === $targetTenant->getId();
    }
}
