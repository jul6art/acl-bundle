<?php

declare(strict_types=1);

namespace Jul6Art\AclBundle\Contract;

/**
 * The write side, used only by {@see \Jul6Art\AclBundle\Security\PermissionDelegationService}.
 *
 * Separate from {@see PermissionSetProviderInterface} on purpose: an application that reads
 * permissions but never delegates them implements the reader alone, and the delegation service is
 * simply not registered. Merging the two would force every consumer to write six mutation methods
 * to get a voter working.
 *
 * Each method reports whether it changed anything, so a caller can tell "granted" from "was
 * already granted" without a second read.
 */
interface PermissionStoreInterface
{
    public function grantToUser(AclUserInterface $target, string $permission): bool;

    public function denyToUser(AclUserInterface $target, string $permission): bool;

    public function removeUserOverride(AclUserInterface $target, string $permission): bool;

    /**
     * Whether any of these roles grants the permission, within this tenant.
     *
     * This is the check that stops privilege escalation: an administrator may only hand out what
     * they hold themselves.
     *
     * @param list<string> $roles
     */
    public function isGrantedForRoles(array $roles, string $permission, ?AclTenantInterface $tenant): bool;

    public function grantToRole(string $roleCode, string $permission, ?AclTenantInterface $tenant): bool;

    public function revokeFromRole(string $roleCode, string $permission, ?AclTenantInterface $tenant): bool;
}
