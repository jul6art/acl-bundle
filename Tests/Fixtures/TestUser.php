<?php

declare(strict_types=1);

namespace Jul6Art\AclBundle\Tests\Fixtures;

use Jul6Art\AclBundle\Contract\AclTenantInterface;
use Jul6Art\AclBundle\Contract\AclUserInterface;
use Symfony\Component\Security\Core\User\UserInterface;

/**
 * What an application's `User` entity looks like from the engine's side: five accessors.
 *
 * It also implements Symfony's `UserInterface`, because the voter reads the actor off a token and a
 * token holds a `UserInterface`. That double implementation is exactly what a real project does,
 * and the reason the bundle's contract is separate: a project already has a user, and should not
 * have to adopt one.
 */
final readonly class TestUser implements AclUserInterface, UserInterface
{
    /**
     * @param list<string> $roles
     */
    public function __construct(
        private ?int $id = 1,
        private array $roles = [],
        private ?AclTenantInterface $tenant = null,
        private bool $active = true,
        private bool $superAdmin = false,
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
        return $this->active;
    }

    #[\Override]
    public function isSuperAdmin(): bool
    {
        return $this->superAdmin;
    }

    #[\Override]
    public function getTenant(): ?AclTenantInterface
    {
        return $this->tenant;
    }

    #[\Override]
    public function eraseCredentials(): void
    {
    }

    #[\Override]
    public function getUserIdentifier(): string
    {
        return \sprintf('user-%s', $this->id ?? 'new');
    }
}
