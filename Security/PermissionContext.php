<?php

declare(strict_types=1);

namespace Jul6Art\AclBundle\Security;

use Jul6Art\AclBundle\Contract\AclUserInterface;

/**
 * Everything a permission decision is made against, resolved once per request.
 *
 * It exists so the decision does not have to re-derive the same facts at every check: which
 * permission is being asked for, split into resource and action, which tenant the request is
 * scoped to, who is asking, and whether this is an API call.
 *
 * `$domain` is the tenant slug the *request* claims — from a route parameter, a resolved entity or
 * the tenant header — and **not** the actor's own tenant. Keeping the two apart is the whole point:
 * comparing them is how a request for someone else's tenant is refused.
 */
final readonly class PermissionContext
{
    public function __construct(
        public string $permission,
        public string $resource,
        public string $action,
        public ?string $domain,
        public ?AclUserInterface $actor,
        public bool $isApi,
    ) {
    }

    /**
     * A loggable form. The actor is reduced to an id — an audit line must not carry an e-mail
     * address, and a full user object in a log is how personal data ends up in a log aggregator.
     *
     * @return array{permission: string, resource: string, action: string, domain: string|null, actor_id: int|null, is_api: bool}
     */
    public function toArray(): array
    {
        return [
            'permission' => $this->permission,
            'resource' => $this->resource,
            'action' => $this->action,
            'domain' => $this->domain,
            'actor_id' => $this->actor?->getId(),
            'is_api' => $this->isApi,
        ];
    }
}
