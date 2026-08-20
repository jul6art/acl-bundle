<?php

declare(strict_types=1);

namespace Jul6Art\AclBundle\Security;

use Jul6Art\AclBundle\Contract\AclUserInterface;
use Jul6Art\AclBundle\Contract\PermissionSetProviderInterface;

/**
 * Reads a user's permissions once per request and answers every later check from memory.
 *
 * ## Why this class exists at all
 *
 * Without it, each permission check is two reads. A page that checks twenty permissions — a CMS
 * edit form does — issues forty queries to answer twenty booleans, and the cost grows with every
 * `is_granted()` a template gains. With it, the two reads happen on the first check and the other
 * nineteen are array lookups: **two queries per request, whatever N is.**
 *
 * The cache is per instance, so it lives as long as the request on the web and as long as the
 * process on the CLI. That difference matters for a long-running command: a worker that grants a
 * permission and then checks it in the same process reads a stale set unless it calls
 * {@see flush()}.
 *
 * ## Precedence
 *
 * A user override — grant *or* deny — beats what any role says. That is what makes "everyone in
 * this role, except this one person" expressible, and it is why an override of `false` is not the
 * same as an absent override: the first refuses, the second falls through to the roles.
 */
final class AclPermissionReadService
{
    /**
     * @var array<int, array{overrides: array<string, bool>, rolePermissions: array<string, true>}>
     */
    private array $setCache = [];

    public function __construct(
        private readonly PermissionSetProviderInterface $sets,
    ) {
    }

    /**
     * The user's explicit decision for this permission:
     * - `true`  — granted, whatever the roles say
     * - `false` — denied, whatever the roles say
     * - `null`  — no override; the roles decide
     */
    public function resolveUserOverride(AclUserInterface $user, string $permission): ?bool
    {
        return $this->loadSet($user)['overrides'][$permission] ?? null;
    }

    public function isGrantedByRole(AclUserInterface $user, string $permission): bool
    {
        return isset($this->loadSet($user)['rolePermissions'][$permission]);
    }

    /**
     * Drops the cached set for one user, or for all of them.
     *
     * Call it after granting or revoking a permission that must take effect **within the same
     * process**: a console command, a message handler, or a controller that mutates and then
     * re-checks. On the web the mutation and the next check are usually two requests, and this is
     * unnecessary.
     */
    public function flush(?AclUserInterface $user = null): void
    {
        if (!$user instanceof AclUserInterface) {
            $this->setCache = [];

            return;
        }

        $id = $user->getId();
        if (null !== $id) {
            unset($this->setCache[$id]);
        }
    }

    /**
     * @return array{overrides: array<string, bool>, rolePermissions: array<string, true>}
     */
    private function loadSet(AclUserInterface $user): array
    {
        $id = $user->getId();

        // Un utilisateur non persisté n'a pas d'identité stable : le mettre en cache ferait
        // partager son jeu de permissions avec le prochain, ce qui est exactement le défaut qu'on
        // ne veut pas dans un ACL. Il n'a par ailleurs aucune permission stockée.
        if (null === $id) {
            return ['overrides' => [], 'rolePermissions' => []];
        }

        return $this->setCache[$id] ??= [
            'overrides' => $this->sets->overridesFor($user),
            // Retourné en clés : la vérification devient un `isset()` au lieu d'un `in_array()`
            // sur une liste qui peut compter plusieurs centaines de codes, à chaque contrôle.
            'rolePermissions' => array_fill_keys($this->sets->grantedByRolesFor($user), true),
        ];
    }
}
