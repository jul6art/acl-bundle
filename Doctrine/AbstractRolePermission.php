<?php

declare(strict_types=1);

namespace Jul6Art\AclBundle\Doctrine;

use Doctrine\ORM\Mapping as ORM;

/**
 * A permission granted — or explicitly refused — to a role (ADR-0001).
 *
 * One row per (role, permission[, tenant]). `granted` tells "no row" (never decided, so refused) from "a row at
 * false" (decided, and refused): the decision is the same, but the grid shows the second to whoever has just
 * unticked it, and the seed never reopens it.
 *
 * The application's entity extends this and declares what a base class cannot: its table, its unique
 * constraint, its index, its id, its tenant association when its roles vary by tenant
 * (`acl.storage.role_scope: tenant`), its audit attribute. Its schema does not move.
 */
#[ORM\MappedSuperclass]
abstract class AbstractRolePermission
{
    #[ORM\Column(name: 'role_code', length: 120)]
    protected string $roleCode = '';

    #[ORM\Column(length: 120)]
    protected string $permission = '';

    #[ORM\Column]
    protected bool $granted = true;

    public function getRoleCode(): string
    {
        return $this->roleCode;
    }

    public function setRoleCode(string $roleCode): static
    {
        $this->roleCode = $roleCode;

        return $this;
    }

    public function getPermission(): string
    {
        return $this->permission;
    }

    public function setPermission(string $permission): static
    {
        $this->permission = $permission;

        return $this;
    }

    public function isGranted(): bool
    {
        return $this->granted;
    }

    public function setGranted(bool $granted): static
    {
        $this->granted = $granted;

        return $this;
    }
}
