<?php

declare(strict_types=1);

namespace Jul6Art\AclBundle\Tests\Fixtures\Entity;

use Doctrine\ORM\Mapping as ORM;
use Jul6Art\AclBundle\Contract\AclTenantInterface;

/** An organization, as an application maps one: the tenant of the storage's scopes. */
#[ORM\Entity]
#[ORM\Table(name: 'acl_test_tenant')]
class Tenant implements AclTenantInterface
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    public function __construct(
        #[ORM\Column(length: 64)]
        private string $slug,
    ) {
    }

    #[\Override]
    public function getId(): ?int
    {
        return $this->id;
    }

    #[\Override]
    public function getSlug(): string
    {
        return $this->slug;
    }
}
