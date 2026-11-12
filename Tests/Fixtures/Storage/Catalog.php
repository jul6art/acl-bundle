<?php

declare(strict_types=1);

namespace Jul6Art\AclBundle\Tests\Fixtures\Storage;

use Jul6Art\AclBundle\Contract\PermissionCatalogInterface;

/** An application's catalog: two resources, two roles' defaults, one code for the super admin alone. */
final class Catalog implements PermissionCatalogInterface
{
    public const array DEFAULTS = [
        'ROLE_ADMIN' => ['user.read', 'user.update', 'invoice.read'],
        'ROLE_USER' => ['invoice.read'],
    ];

    #[\Override]
    public function all(): array
    {
        return ['user.read', 'user.update', 'invoice.read', 'invoice.cancel'];
    }

    #[\Override]
    public function grouped(): array
    {
        return ['user' => ['user.read', 'user.update'], 'invoice' => ['invoice.read', 'invoice.cancel']];
    }

    #[\Override]
    public function defaults(): array
    {
        return self::DEFAULTS;
    }

    #[\Override]
    public function superAdminOnly(): array
    {
        return ['invoice.cancel'];
    }
}
