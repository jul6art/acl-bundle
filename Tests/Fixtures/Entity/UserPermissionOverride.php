<?php

declare(strict_types=1);

namespace Jul6Art\AclBundle\Tests\Fixtures\Entity;

use Doctrine\ORM\Mapping as ORM;
use Jul6Art\AclBundle\Doctrine\AbstractUserPermissionOverride;

/** The thin override of an application whose overrides apply everywhere. */
#[ORM\Entity]
#[ORM\Table(name: 'acl_test_user_permission_override')]
#[ORM\UniqueConstraint(name: 'uniq_acl_test_user_permission', columns: ['user_id', 'permission'])]
class UserPermissionOverride extends AbstractUserPermissionOverride
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
