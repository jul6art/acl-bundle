<?php

declare(strict_types=1);

namespace Jul6Art\AclBundle\Tests\Fixtures\Entity;

use Doctrine\ORM\Mapping as ORM;
use Jul6Art\AclBundle\Doctrine\AbstractRolePermission;

/** The thin entity of an application whose grid varies by tenant (cereezer): it adds the tenant. */
#[ORM\Entity]
#[ORM\Table(name: 'acl_test_tenant_role_permission')]
#[ORM\UniqueConstraint(name: 'uniq_acl_test_tenant_role_permission', columns: ['role_code', 'permission', 'organization_id'])]
class TenantRolePermission extends AbstractRolePermission
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
