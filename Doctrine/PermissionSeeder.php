<?php

declare(strict_types=1);

namespace Jul6Art\AclBundle\Doctrine;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;
use Jul6Art\AclBundle\Contract\PermissionCatalogInterface;
use LogicException;

use function sprintf;

/**
 * The roles' defaults, written where nobody has decided yet (ADR-0001) — at the first deployment, at every one
 * that adds a code, and on every fixtures load (which purges the table: without it, a freshly loaded
 * administrator holds NOTHING, and every guarded screen answers 403 with nothing to say why).
 *
 * Never `grantToRole()`: a cell an administrator has unticked keeps its row at `false`, and that refusal is a
 * decision, not a drift. Replaying the seed does not reopen it.
 */
final readonly class PermissionSeeder
{
    /**
     * @param class-string<AbstractRolePermission> $rolePermissionClass
     */
    public function __construct(
        private DoctrinePermissionStore $store,
        private PermissionCatalogInterface $catalog,
        private ManagerRegistry $registry,
        private string $rolePermissionClass,
    ) {
    }

    /**
     * Writes and flushes.
     *
     * @return int the defaults written, those nobody had decided yet
     */
    public function seed(): int
    {
        $written = 0;
        foreach ($this->catalog->defaults() as $role => $permissions) {
            foreach ($permissions as $permission) {
                if ($this->store->seedRole($role, $permission)) {
                    ++$written;
                }
            }
        }

        $manager = $this->registry->getManagerForClass($this->rolePermissionClass);
        if (!$manager instanceof EntityManagerInterface) {
            throw new LogicException(sprintf('No Doctrine entity manager handles "%s".', $this->rolePermissionClass));
        }
        $manager->flush();

        return $written;
    }
}
