<?php

declare(strict_types=1);

namespace Jul6Art\AclBundle\Tests\Fixtures\Entity;

use Doctrine\ORM\Mapping as ORM;
use Jul6Art\AclBundle\Doctrine\AbstractRolePermission;

/** The thin entity of an application with one grid for everyone: its table, its unicity, its id. */
#[ORM\Entity]
#[ORM\Table(name: 'acl_test_role_permission')]
#[ORM\UniqueConstraint(name: 'uniq_acl_test_role_permission', columns: ['role_code', 'permission'])]
class RolePermission extends AbstractRolePermission
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    public function getId(): ?int
    {
        return $this->id;
    }
}
