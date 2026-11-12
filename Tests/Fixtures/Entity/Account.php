<?php

declare(strict_types=1);

namespace Jul6Art\AclBundle\Tests\Fixtures\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Jul6Art\AclBundle\Contract\AclTenantInterface;
use Jul6Art\AclBundle\Contract\AclUserInterface;

/** An application's account, as the storage sees it: the target of an override. */
#[ORM\Entity]
#[ORM\Table(name: 'acl_test_account')]
class Account implements AclUserInterface
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    /**
     * @param list<string> $roles
     */
    public function __construct(
        #[ORM\Column(type: Types::JSON)]
        private array $roles = [],
        #[ORM\ManyToOne(targetEntity: Tenant::class)]
        #[ORM\JoinColumn(nullable: true)]
        private ?Tenant $tenant = null,
    ) {
    }

    #[\Override]
    public function getId(): ?int
    {
        return $this->id;
    }

    /**
     * @return list<string>
     */
    #[\Override]
    public function getRoles(): array
    {
        return $this->roles;
    }

    #[\Override]
    public function isActive(): bool
    {
        return true;
    }

    #[\Override]
    public function isSuperAdmin(): bool
    {
        return false;
    }

    #[\Override]
    public function getTenant(): ?AclTenantInterface
    {
        return $this->tenant;
    }

    public function moveTo(?Tenant $tenant): void
    {
        $this->tenant = $tenant;
    }
}
