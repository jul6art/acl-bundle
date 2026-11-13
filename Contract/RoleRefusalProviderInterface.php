<?php

declare(strict_types=1);

namespace Jul6Art\AclBundle\Contract;

/**
 * What a role is explicitly refused — read by the engine for the tenant administrator alone (rule 6 of
 * {@see \Jul6Art\AclBundle\Security\PermissionDecisionService}).
 *
 * The tenant administrator passes on what no role grants: a new permission code works before anyone has seeded
 * it. But a grid that shows that role's column, editable, must also be able to take a permission back from it —
 * otherwise an unticked cell says "refused" while the engine lets the administrator through. The refusal is what
 * an administrator DECIDED: a row kept at `false`, a tenant's over the platform's. A permission nobody ever
 * decided is not refused.
 *
 * Optional, next to {@see PermissionSetProviderInterface}: a provider that does not implement it keeps the
 * tenant administrator's former reach — everything no role grants — and nothing else changes.
 *
 * > ⚠️ Called at most once per account, role and request, and only when a decision reaches rule 6:
 * > `AclPermissionReadService` keeps the answer. One full read, never one per permission.
 */
interface RoleRefusalProviderInterface
{
    /**
     * The permissions explicitly refused to this role, in the account's tenant when the roles vary by tenant.
     *
     * @return list<string>
     */
    public function refusedToRoleFor(string $role, AclUserInterface $user): array;
}
