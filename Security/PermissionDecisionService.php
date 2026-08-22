<?php

declare(strict_types=1);

namespace Jul6Art\AclBundle\Security;

use Jul6Art\AclBundle\Contract\AclUserInterface;

/**
 * The decision itself: given an actor, a permission and the request's context, granted or not.
 *
 * The order of the rules is the contract, and each step exists because of a way access control
 * goes wrong:
 *
 * 1. **A super admin passes.** Before the activity check, so a support account can reach a
 *    deactivated tenant to fix it.
 * 2. **A deactivated account is refused**, whatever its roles say. Deactivating an account has to
 *    be enough on its own; hunting down its permissions afterwards is not a security model.
 * 3. **An API request with no resolved tenant is refused.** Not "scoped to the actor's tenant" —
 *    refused. A collection endpoint whose tenant is unknown would otherwise answer across
 *    tenants, and that leak reads exactly like a working endpoint.
 * 4. **A user override decides**, grant or deny, over everything a role grants.
 * 5. **A role grant passes.**
 * 6. **A tenant administrator passes** — but on an API request carrying a tenant, only for their
 *    own. That last clause is the difference between an administrator and a cross-tenant reader.
 * 7. **Otherwise refused.** Absence of a rule is a refusal, never a pass.
 *
 * ## Single-tenant applications
 *
 * Rules 3 and 6 both compare a *resolved* tenant against the actor's. In an application that has
 * no tenants at all, {@see TenantResolver} can only ever answer `null` — there is no request
 * attribute, no route parameter and no header to read — so rule 3 refuses **every** `/api/`
 * permission check for anyone who is not a super admin. The symptom is an empty datatable and a
 * 403 in the console for an administrator who does hold the permission, and nothing in a test
 * suite points at it.
 *
 * `acl.multi_tenant: false` is the answer: rule 3 stops applying and rule 6 grants the tenant
 * administrator without a tenant comparison it cannot make. It defaults to `true`, so a
 * multi-tenant application keeps the strict behaviour byte for byte.
 *
 * ## Not final, on purpose
 *
 * This is the seam a consuming application doubles in its own voter tests: a voter that delegates
 * here is tested by stubbing the decision, not by seeding a permission table. Marking it final
 * turned 74 such tests in the reference consumer into `ClassIsFinalException` at once — for a class
 * whose whole job is to be asked a question. Rector is told to leave it open below.
 */
readonly class PermissionDecisionService
{
    /**
     * @param AclPermissionReadService|null $permissions when absent — no storage wired — only rules
     *                                                   1, 2 and 6 can grant, which is a
     *                                                   deliberately narrow fallback rather than an
     *                                                   open door
     * @param bool                          $multiTenant false in an application that has no
     *                                                   tenants: rule 3 is dropped and rule 6 stops
     *                                                   comparing a tenant that can never be
     *                                                   resolved
     */
    public function __construct(
        private string $tenantAdminRole,
        private ?AclPermissionReadService $permissions = null,
        private bool $multiTenant = true,
    ) {
    }

    public function isGranted(
        AclUserInterface $user,
        string $permission,
        mixed $subject = null,
        ?PermissionContext $context = null,
    ): bool {
        if ($user->isSuperAdmin()) {
            return true;
        }

        if (!$user->isActive()) {
            return false;
        }

        // ⚠️ Une requête d'API sans tenant résolu est refusée, et non rabattue sur le tenant de
        // l'appelant. Un repli « implicite » transformerait un en-tête oublié en collection
        // inter-tenants, avec une réponse 200 indiscernable d'une réponse correcte.
        //
        // Sauf en mono-tenant, où il n'y a rien à résoudre : la règle refuserait alors toute
        // vérification derrière `/api/`, pour tout le monde sauf un super-admin.
        if ($this->multiTenant && $context instanceof PermissionContext && $context->isApi && null === $context->domain) {
            return false;
        }

        if ($this->permissions instanceof AclPermissionReadService) {
            $override = $this->permissions->resolveUserOverride($user, $permission);
            if (null !== $override) {
                return $override;
            }

            if ($this->permissions->isGrantedByRole($user, $permission)) {
                return true;
            }
        }

        if (\in_array($this->tenantAdminRole, $user->getRoles(), true)) {
            if ($this->multiTenant && $context instanceof PermissionContext && $context->isApi && null !== $context->domain) {
                return $context->domain === $user->getTenant()?->getSlug();
            }

            return true;
        }

        return false;
    }
}
