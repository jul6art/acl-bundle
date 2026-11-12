<?php

declare(strict_types=1);

namespace Jul6Art\AclBundle\Doctrine;

use Doctrine\ORM\Mapping as ORM;
use Jul6Art\AclBundle\Contract\AclUserInterface;

/**
 * A decision for one account, which wins over what its roles say (ADR-0001).
 *
 * Both ways: `granted = true` gives someone what their role lacks, `granted = false` takes away what their role
 * has. The second case is why the table exists — without it, taking a permission from one person would mean
 * inventing a role for them.
 *
 * The account targets `AclUserInterface`, which the bundle resolves to `acl.storage.user_class`. The
 * application's entity declares its table, its unique constraint, its id, its tenant association when an
 * override belongs to a tenant (`acl.storage.override_scope: tenant`), its audit attribute.
 */
#[ORM\MappedSuperclass]
abstract class AbstractUserPermissionOverride
{
    #[ORM\ManyToOne(targetEntity: AclUserInterface::class)]
    #[ORM\JoinColumn(name: 'user_id', nullable: false, onDelete: 'CASCADE')]
    protected ?AclUserInterface $user = null;

    #[ORM\Column(length: 120)]
    protected string $permission = '';

    #[ORM\Column]
    protected bool $granted = true;

    public function getUser(): ?AclUserInterface
    {
        return $this->user;
    }

    public function setUser(?AclUserInterface $user): static
    {
        $this->user = $user;

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
