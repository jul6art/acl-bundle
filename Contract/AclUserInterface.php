<?php

declare(strict_types=1);

namespace Jul6Art\AclBundle\Contract;

/**
 * What the permission engine needs to know about the actor — and deliberately nothing else.
 *
 * The engine never touches a user's e-mail, name or password, so none of that is here. Keeping the
 * contract this narrow is what lets the bundle sit above an application's own `User` entity
 * instead of imposing one: implementing it is usually four methods the entity already has, plus
 * `getTenant()`.
 */
interface AclUserInterface
{
    public function getId(): ?int;

    /**
     * @return list<string>
     */
    public function getRoles(): array;

    /**
     * A deactivated account is refused every permission except those a super admin bypasses.
     */
    public function isActive(): bool;

    /**
     * Kept as a method rather than derived from `getRoles()` because an application may grant it
     * some other way — a flag, a role hierarchy, an impersonation rule — and the engine has no
     * business guessing which.
     */
    public function isSuperAdmin(): bool;

    public function getTenant(): ?AclTenantInterface;
}
