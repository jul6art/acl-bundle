<?php

declare(strict_types=1);

namespace Jul6Art\AclBundle\Doctrine;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\EntityRepository;
use Doctrine\ORM\QueryBuilder;
use Doctrine\Persistence\ManagerRegistry;
use Jul6Art\AclBundle\Contract\AclTenantInterface;
use Jul6Art\AclBundle\Contract\AclUserInterface;
use Jul6Art\AclBundle\Contract\PermissionStoreInterface;
use LogicException;

use function array_fill_keys;
use function array_unique;
use function array_values;
use function in_array;
use function sprintf;

/**
 * The permissions in Doctrine (ADR-0001): the roles' grid, the accounts' overrides, and the seed.
 *
 * ⚠️ No method flushes: the caller decides the transaction. A flush per grant would turn saving a grid of fifty
 * cells into fifty transactions, half of which would survive an error in the middle. Each write says whether it
 * CHANGED something, so a caller tells "granted" from "already granted" without reading again.
 *
 * Two scopes, each a product's need:
 *
 * - **roles** (`acl.storage.role_scope`): `global`, one grid for everyone — the tenant an engine passes is not
 *   read, by design; or `tenant`, where a tenant's row wins over the platform's (the row without tenant), granted
 *   as refused, so a tenant administrator manages the permissions OF THEIR tenant, taking back included;
 * - **overrides** (`acl.storage.override_scope`): `global`, or `tenant`, where an override belongs to the
 *   account's current tenant (`AclUserInterface::getTenant()`) and is read there only.
 *
 * The tenant is the association `acl.storage.tenant_property` of the application's entities.
 */
final readonly class DoctrinePermissionStore implements PermissionStoreInterface
{
    public const string SCOPE_GLOBAL = 'global';
    public const string SCOPE_TENANT = 'tenant';

    /**
     * @param class-string<AbstractRolePermission>         $rolePermissionClass
     * @param class-string<AbstractUserPermissionOverride> $overrideClass
     * @param class-string<AclUserInterface>               $userClass
     */
    public function __construct(
        private ManagerRegistry $registry,
        private string $rolePermissionClass,
        private string $overrideClass,
        private string $userClass,
        private string $roleScope = self::SCOPE_GLOBAL,
        private string $overrideScope = self::SCOPE_GLOBAL,
        private string $tenantProperty = 'organization',
    ) {
    }

    #[\Override]
    public function grantToUser(AclUserInterface $target, string $permission): bool
    {
        return $this->setOverride($target, $permission, true);
    }

    #[\Override]
    public function denyToUser(AclUserInterface $target, string $permission): bool
    {
        return $this->setOverride($target, $permission, false);
    }

    #[\Override]
    public function removeUserOverride(AclUserInterface $target, string $permission): bool
    {
        $existing = $this->findOverride($target, $permission);
        if (!$existing instanceof AbstractUserPermissionOverride) {
            return false;
        }

        $this->manager($this->overrideClass)->remove($existing);

        return true;
    }

    /**
     * An account's own decisions, code → granted, in its current tenant when overrides belong to one.
     *
     * @return array<string, bool>
     */
    public function overridesFor(AclUserInterface $user): array
    {
        if (!$user instanceof $this->userClass) {
            return [];
        }

        $builder = $this->repository($this->overrideClass)->createQueryBuilder('o')
            ->select('o.permission AS permission', 'o.granted AS granted')
            ->andWhere('o.user = :user')
            ->setParameter('user', $user);
        if (self::SCOPE_TENANT === $this->overrideScope) {
            $this->inTenant($builder, 'o', $user->getTenant(), withPlatform: false);
        }

        $overrides = [];
        /** @var list<array{permission: string, granted: bool}> $rows */
        $rows = $builder->getQuery()->getArrayResult();
        foreach ($rows as $row) {
            $overrides[$row['permission']] = (bool) $row['granted'];
        }

        return $overrides;
    }

    /**
     * @param list<string> $roles
     */
    #[\Override]
    public function isGrantedForRoles(array $roles, string $permission, ?AclTenantInterface $tenant): bool
    {
        return in_array($permission, $this->grantedForRoles($roles, $tenant), true);
    }

    /**
     * The permissions a set of roles holds, deduplicated — in one query, not one per role: the engine calls this
     * once per account and per request, and keeps the answer.
     *
     * @param list<string> $roles
     *
     * @return list<string>
     */
    public function grantedForRoles(array $roles, ?AclTenantInterface $tenant = null): array
    {
        $granted = [];
        foreach ($this->decisions($roles, $tenant) as $decision) {
            if ($decision['granted']) {
                $granted[] = $decision['permission'];
            }
        }

        return array_values(array_unique($granted));
    }

    /**
     * The permissions explicitly refused to a set of roles: a row decided at `false`, a tenant's over the
     * platform's. A permission without a row is not refused — nobody decided it. One query, like
     * {@see grantedForRoles()}.
     *
     * @param list<string> $roles
     *
     * @return list<string>
     */
    public function refusedForRoles(array $roles, ?AclTenantInterface $tenant = null): array
    {
        $refused = [];
        foreach ($this->decisions($roles, $tenant) as $decision) {
            if (!$decision['granted']) {
                $refused[] = $decision['permission'];
            }
        }

        return array_values(array_unique($refused));
    }

    /**
     * Role → the permissions it holds, for the grid.
     *
     * @param list<string> $roles
     *
     * @return array<string, list<string>>
     */
    public function grantedMatrix(array $roles, ?AclTenantInterface $tenant = null): array
    {
        /** @var array<string, list<string>> $matrix */
        $matrix = array_fill_keys($roles, []);
        foreach ($this->decisions($roles, $tenant) as $decision) {
            if ($decision['granted']) {
                $matrix[$decision['roleCode']][] = $decision['permission'];
            }
        }

        return $matrix;
    }

    #[\Override]
    public function grantToRole(string $roleCode, string $permission, ?AclTenantInterface $tenant): bool
    {
        $tenant = $this->roleTenant($tenant);
        $existing = $this->findRole($roleCode, $permission, $tenant);
        if ($existing instanceof AbstractRolePermission) {
            if ($existing->isGranted()) {
                return false;
            }
            $existing->setGranted(true);

            return true;
        }

        $this->persistRole($roleCode, $permission, $tenant, true);

        return true;
    }

    #[\Override]
    public function revokeFromRole(string $roleCode, string $permission, ?AclTenantInterface $tenant): bool
    {
        $tenant = $this->roleTenant($tenant);
        $existing = $this->findRole($roleCode, $permission, $tenant);
        if (!$existing instanceof AbstractRolePermission) {
            // ⚠️ A tenant's refusal is written out, even without a row to change: otherwise taking back in a
            // tenant what the platform grants would do nothing — the default would keep applying, and the cell
            // would tick itself again on reload. On the platform, no row already means refused.
            if (!$tenant instanceof AclTenantInterface) {
                return false;
            }
            $this->persistRole($roleCode, $permission, $tenant, false);

            return true;
        }
        if (!$existing->isGranted()) {
            return false;
        }

        // Kept at false rather than removed: "never decided" and "decided, and refused" stay apart.
        $existing->setGranted(false);

        return true;
    }

    /**
     * Writes a role's default — on the platform — if nobody has ever decided it, and only then. That is what makes
     * the seed safe to replay at every deployment: `grantToRole()` would turn back to `true` a row an
     * administrator set to `false`.
     */
    public function seedRole(string $roleCode, string $permission): bool
    {
        if ($this->findRole($roleCode, $permission, null) instanceof AbstractRolePermission) {
            return false;
        }

        $this->persistRole($roleCode, $permission, null, true);

        return true;
    }

    /**
     * The grid of one scope, as submitted: role → the codes ticked. On the platform (or with one grid for
     * everyone), each cell is granted or taken back. In a tenant, only what differs from the platform's default
     * is written, and a tenant row that now equals the default goes: the tenant follows the platform again.
     *
     * One read for the whole grid, whatever its size: the rows of the roles in scope are loaded once, and each
     * cell is decided against them — a grid of six roles and a hundred codes is not six hundred queries.
     *
     * @param list<string>                $roles
     * @param list<string>                $codes
     * @param array<string, list<string>> $checked
     */
    public function saveRoleGrid(array $roles, array $codes, array $checked, ?AclTenantInterface $tenant): void
    {
        $tenant = $this->roleTenant($tenant);
        $platform = [];
        $own = [];
        foreach ($this->rowsInScope($roles, $tenant) as $row) {
            $key = $row->getRoleCode().'|'.$row->getPermission();
            if ($tenant instanceof AclTenantInterface && $this->tenantOf($row) instanceof AclTenantInterface) {
                $own[$key] = $row;
            } else {
                $platform[$key] = $row;
            }
        }

        foreach ($roles as $role) {
            foreach ($codes as $code) {
                $key = $role.'|'.$code;
                $wanted = in_array($code, $checked[$role] ?? [], true);
                $default = $platform[$key] ?? null;

                if (!$tenant instanceof AclTenantInterface) {
                    // The row decides, both ways; without one, a refusal is already what applies.
                    if ($default instanceof AbstractRolePermission) {
                        if ($default->isGranted() !== $wanted) {
                            $default->setGranted($wanted);
                        }
                    } elseif ($wanted) {
                        $this->persistRole($role, $code, null, true);
                    }

                    continue;
                }

                $row = $own[$key] ?? null;
                if ($wanted === ($default?->isGranted() ?? false)) {
                    if ($row instanceof AbstractRolePermission) {
                        $this->manager($this->rolePermissionClass)->remove($row);
                    }

                    continue;
                }
                if ($row instanceof AbstractRolePermission) {
                    $row->setGranted($wanted);

                    continue;
                }
                $this->persistRole($role, $code, $tenant, $wanted);
            }
        }
    }

    /**
     * The decisions that apply to roles in a scope: a tenant's row over the platform's, the platform's otherwise.
     *
     * @param list<string> $roles
     *
     * @return array<string, array{roleCode: string, permission: string, granted: bool}>
     */
    private function decisions(array $roles, ?AclTenantInterface $tenant): array
    {
        if ([] === $roles) {
            return [];
        }

        $tenant = $this->roleTenant($tenant);
        $builder = $this->repository($this->rolePermissionClass)->createQueryBuilder('rp')
            ->select('rp.roleCode AS roleCode', 'rp.permission AS permission', 'rp.granted AS granted')
            ->andWhere('rp.roleCode IN (:roles)')
            ->setParameter('roles', $roles);
        if (self::SCOPE_TENANT === $this->roleScope) {
            $builder->addSelect(sprintf('IDENTITY(rp.%s) AS tenantId', $this->tenantProperty));
            $this->inTenant($builder, 'rp', $tenant, withPlatform: true);
        }

        $decisions = [];
        /** @var list<array{roleCode: string, permission: string, granted: bool, tenantId?: int|string|null}> $rows */
        $rows = $builder->getQuery()->getArrayResult();
        foreach ($rows as $row) {
            $key = $row['roleCode'].'|'.$row['permission'];
            $ownRow = null !== ($row['tenantId'] ?? null);
            if ($ownRow || !isset($decisions[$key])) {
                $decisions[$key] = ['roleCode' => $row['roleCode'], 'permission' => $row['permission'], 'granted' => (bool) $row['granted']];
            }
        }

        return $decisions;
    }

    /**
     * The rows of the roles in a scope: the platform's, and the tenant's when there is one.
     *
     * @param list<string> $roles
     *
     * @return list<AbstractRolePermission>
     */
    private function rowsInScope(array $roles, ?AclTenantInterface $tenant): array
    {
        if ([] === $roles) {
            return [];
        }

        $builder = $this->repository($this->rolePermissionClass)->createQueryBuilder('rp')
            ->andWhere('rp.roleCode IN (:roles)')
            ->setParameter('roles', $roles);
        if (self::SCOPE_TENANT === $this->roleScope) {
            $this->inTenant($builder, 'rp', $tenant, withPlatform: true);
        }

        /** @var list<AbstractRolePermission> $rows */
        $rows = $builder->getQuery()->getResult();

        return $rows;
    }

    private function inTenant(QueryBuilder $builder, string $alias, ?AclTenantInterface $tenant, bool $withPlatform): void
    {
        $column = $alias.'.'.$this->tenantProperty;
        if (!$tenant instanceof AclTenantInterface) {
            $builder->andWhere($column.' IS NULL');

            return;
        }

        $builder->andWhere($withPlatform ? sprintf('%1$s IS NULL OR %1$s = :tenant', $column) : $column.' = :tenant')
            ->setParameter('tenant', $tenant);
    }

    private function roleTenant(?AclTenantInterface $tenant): ?AclTenantInterface
    {
        return self::SCOPE_TENANT === $this->roleScope ? $tenant : null;
    }

    private function findRole(string $roleCode, string $permission, ?AclTenantInterface $tenant): ?AbstractRolePermission
    {
        $criteria = ['roleCode' => $roleCode, 'permission' => $permission];
        if (self::SCOPE_TENANT === $this->roleScope) {
            $criteria[$this->tenantProperty] = $tenant;
        }

        $row = $this->repository($this->rolePermissionClass)->findOneBy($criteria);

        return $row instanceof AbstractRolePermission ? $row : null;
    }

    private function persistRole(string $roleCode, string $permission, ?AclTenantInterface $tenant, bool $granted): void
    {
        $row = new ($this->rolePermissionClass)();
        $row->setRoleCode($roleCode)->setPermission($permission)->setGranted($granted);
        if (self::SCOPE_TENANT === $this->roleScope) {
            $this->setTenant($this->rolePermissionClass, $row, $tenant);
        }

        $this->manager($this->rolePermissionClass)->persist($row);
    }

    private function findOverride(AclUserInterface $user, string $permission): ?AbstractUserPermissionOverride
    {
        if (!$user instanceof $this->userClass) {
            return null;
        }

        $criteria = ['user' => $user, 'permission' => $permission];
        if (self::SCOPE_TENANT === $this->overrideScope) {
            $criteria[$this->tenantProperty] = $user->getTenant();
        }

        $row = $this->repository($this->overrideClass)->findOneBy($criteria);

        return $row instanceof AbstractUserPermissionOverride ? $row : null;
    }

    private function setOverride(AclUserInterface $user, string $permission, bool $granted): bool
    {
        if (!$user instanceof $this->userClass) {
            return false;
        }

        $existing = $this->findOverride($user, $permission);
        if ($existing instanceof AbstractUserPermissionOverride) {
            if ($existing->isGranted() === $granted) {
                return false;
            }
            $existing->setGranted($granted);

            return true;
        }

        $row = new ($this->overrideClass)();
        $row->setUser($user)->setPermission($permission)->setGranted($granted);
        if (self::SCOPE_TENANT === $this->overrideScope) {
            $this->setTenant($this->overrideClass, $row, $user->getTenant());
        }
        $this->manager($this->overrideClass)->persist($row);

        return true;
    }

    /**
     * The tenant association is the application's: set through its mapping, whatever its setter is called.
     *
     * @param class-string $class
     */
    private function setTenant(string $class, object $row, ?AclTenantInterface $tenant): void
    {
        $this->manager($class)->getClassMetadata($class)->setFieldValue($row, $this->tenantProperty, $tenant);
    }

    private function tenantOf(AbstractRolePermission $row): ?AclTenantInterface
    {
        $tenant = $this->manager($this->rolePermissionClass)->getClassMetadata($this->rolePermissionClass)->getFieldValue($row, $this->tenantProperty);

        return $tenant instanceof AclTenantInterface ? $tenant : null;
    }

    /**
     * @param class-string $class
     */
    private function manager(string $class): EntityManagerInterface
    {
        $manager = $this->registry->getManagerForClass($class);
        if (!$manager instanceof EntityManagerInterface) {
            throw new LogicException(sprintf('No Doctrine entity manager handles "%s": map it, or fix acl.storage.', $class));
        }

        return $manager;
    }

    /**
     * @template T of object
     *
     * @param class-string<T> $class
     *
     * @return EntityRepository<T>
     */
    private function repository(string $class): EntityRepository
    {
        return $this->manager($class)->getRepository($class);
    }
}
