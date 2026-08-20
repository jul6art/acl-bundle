<?php

declare(strict_types=1);

namespace Jul6Art\AclBundle\Contract;

/**
 * The tenant a permission decision is scoped to.
 *
 * Two accessors, because two things are genuinely needed: an identifier to compare two tenants
 * without loading them, and a slug — the value that appears in a URL or a request header and that
 * a permission context is matched against.
 *
 * The bundle never says "organization", "workspace" or "account": that word belongs to the
 * application. Whatever class plays the part implements this, usually with two one-line methods it
 * already has.
 */
interface AclTenantInterface
{
    public function getId(): ?int;

    /**
     * The public identifier used in URLs and in the tenant request header.
     */
    public function getSlug(): ?string;
}
