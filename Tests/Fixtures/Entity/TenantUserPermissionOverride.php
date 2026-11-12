<?php

declare(strict_types=1);

namespace Jul6Art\AclBundle\Tests\Fixtures\Entity;

use Doctrine\ORM\Mapping as ORM;
use Jul6Art\AclBundle\Doctrine\AbstractUserPermissionOverride;

/** The thin override of an application whose overrides belong to a tenant (cegeta). */
#[ORM\Entity]
#[ORM\Table(name: 'acl_test_tenant_user_permission_override')]
#[ORM\UniqueConstraint(name: 'uniq_acl_test_tenant_user_permission', columns: ['user_id', 'organization_id', 'permission'])]
class TenantUserPermissionOverride extends AbstractUserPermissionOverride
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Tenant::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'CASCADE')]
    private ?Tenant $organization = null;

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getOrganization(): ?Tenant
    {
        return $this->organization;
    }
}
