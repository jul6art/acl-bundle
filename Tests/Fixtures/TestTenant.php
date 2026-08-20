<?php

declare(strict_types=1);

namespace Jul6Art\AclBundle\Tests\Fixtures;

use Jul6Art\AclBundle\Contract\AclTenantInterface;

final readonly class TestTenant implements AclTenantInterface
{
    public function __construct(
        private ?int $id,
        private ?string $slug,
    ) {
    }

    #[\Override]
    public function getId(): ?int
    {
        return $this->id;
    }

    #[\Override]
    public function getSlug(): ?string
    {
        return $this->slug;
    }
}
