<?php

declare(strict_types=1);

namespace Jul6Art\AclBundle\Security;

use Jul6Art\AclBundle\Contract\AclUserInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Vote;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;

/**
 * Makes `isGranted('cms:page:read')` work, anywhere Symfony's authorisation checker is reachable —
 * a controller, a template, an API Platform `security` expression.
 *
 * ```php
 * #[IsGranted('cms:page:update')]
 * public function edit(Page $page): Response { … }
 * ```
 *
 * ## What it claims, and what it leaves alone
 *
 * `supports()` accepts an attribute only if {@see PermissionCodeParser} can parse it. So
 * `ROLE_ADMIN` and `EDIT` fall through to the voters that own them, and this one never abstains on
 * something it should have decided nor decides something it should not. That is why the parser
 * throws instead of returning null.
 *
 * ## The fallback context
 *
 * Normally {@see PermissionContextListener} has already resolved the context. When it has not —
 * an `isGranted()` from a command, from a listener running earlier, or on a controller with no
 * `#[CheckPermission]` — the voter builds one from the request. Without that fallback, an API check
 * would see no context, skip the "an API request must name its tenant" rule, and grant across
 * tenants exactly where it matters most.
 *
 * @extends Voter<string, mixed>
 */
final class PermissionVoter extends Voter
{
    public function __construct(
        private readonly PermissionCodeParser $parser,
        private readonly PermissionDecisionService $decisionService,
        private readonly PermissionContextAccessor $contextAccessor,
        private readonly TenantResolver $tenants,
        private readonly RequestStack $requestStack,
    ) {
    }

    #[\Override]
    protected function supports(string $attribute, mixed $subject): bool
    {
        try {
            $this->parser->parse($attribute);

            return true;
        } catch (\InvalidArgumentException) {
            return false;
        }
    }

    #[\Override]
    protected function voteOnAttribute(string $attribute, mixed $subject, TokenInterface $token, ?Vote $vote = null): bool
    {
        $user = $token->getUser();
        if (!$user instanceof AclUserInterface) {
            return false;
        }

        return $this->decisionService->isGranted(
            user: $user,
            permission: $attribute,
            subject: $subject,
            context: $this->contextAccessor->get() ?? $this->buildFallbackContext($attribute, $user),
        );
    }

    private function buildFallbackContext(string $permission, AclUserInterface $actor): ?PermissionContext
    {
        $request = $this->requestStack->getCurrentRequest();
        if (!$request instanceof Request) {
            return null;
        }

        $parsed = $this->parser->parse($permission);

        return new PermissionContext(
            permission: $permission,
            resource: $parsed['resource'],
            action: $parsed['action'],
            domain: $this->tenants->resolveSlug($request),
            actor: $actor,
            isApi: str_starts_with($request->getPathInfo(), '/api/'),
        );
    }
}
