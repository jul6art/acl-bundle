<?php

declare(strict_types=1);

namespace Jul6Art\AclBundle\Contract;

/**
 * The application's permissions, as the bundle's storage needs them (ADR-0001): the codes, the roles' defaults
 * and the codes reserved to a super admin. The codes are the product's — the bundle never invents one —, so the
 * application implements this once, usually over its `PermissionCodes` and `DefaultRolePermissions`.
 *
 * Read by the seed (`acl:permissions:seed`, the fixtures) and by the grid of the roles' permissions.
 */
interface PermissionCatalogInterface
{
    /**
     * Every permission code.
     *
     * @return list<string>
     */
    public function all(): array;

    /**
     * The codes by resource, in display order: a section of the grid each.
     *
     * @return array<string, list<string>>
     */
    public function grouped(): array;

    /**
     * Role → the permissions it holds by default. The seed writes them where nobody has decided yet.
     *
     * ⚠️ The super admin role has no business here: the engine lets it through before reading the storage, so
     * rows would give it nothing — and, made removable on the grid, would let someone lock themselves out.
     *
     * @return array<string, list<string>>
     */
    public function defaults(): array;

    /**
     * The codes only a super admin may grant or take back on the grid — irreversible acts, typically.
     *
     * @return list<string>
     */
    public function superAdminOnly(): array;
}
