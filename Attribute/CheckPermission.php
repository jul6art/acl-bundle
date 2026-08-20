<?php

declare(strict_types=1);

namespace Jul6Art\AclBundle\Attribute;

/**
 * Declares the permission a controller — or one of its actions — requires.
 *
 * ```php
 * #[CheckPermission('organization:read')]
 * final class OrganizationController extends AbstractController
 * {
 *     #[CheckPermission('organization:update')]      // wins over the class
 *     public function edit(): Response { … }
 * }
 * ```
 *
 * ## What executes it
 *
 * `PermissionContextSubscriber` reads it on `kernel.controller` and stores a
 * {@see \Jul6Art\AclBundle\Security\PermissionContext} on the request. That context is what
 * `PermissionVoter` then decides on.
 *
 * > ⚠️ **This attribute does not deny anything on its own.** It only resolves the context; the
 * > refusal comes from an `isGranted()` / `#[IsGranted]` check reaching the voter. A controller
 * > carrying only this attribute and no authorisation check is **open**, which is exactly the
 * > mistake it looks like it prevents. Keep `#[IsGranted]` — or a `denyAccessUnlessGranted()` — on
 * > the action.
 *
 * A method-level declaration wins over the class-level one; there is no merging, and the closest
 * declaration is the one that applies.
 */
#[\Attribute(\Attribute::TARGET_CLASS | \Attribute::TARGET_METHOD)]
final readonly class CheckPermission
{
    public function __construct(
        public string $permission,
    ) {
    }
}
