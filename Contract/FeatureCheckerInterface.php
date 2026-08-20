<?php

declare(strict_types=1);

namespace Jul6Art\AclBundle\Contract;

/**
 * Whether a feature is switched on for a user's tenant — the other half of `#[RequiresFeature]`.
 *
 * A feature flag is not a permission: it says what a tenant *bought* or *activated*, not what a
 * person is allowed to do. Both gates are needed, and they fail for different reasons — hence a
 * separate contract rather than a permission code shaped like `cms:enabled`.
 *
 * ```php
 * final class OrganizationFeatureChecker implements FeatureCheckerInterface
 * {
 *     public function isEnabled(AclUserInterface $user, string $featureCode): bool
 *     {
 *         $tenant = $user->getTenant();
 *
 *         return null !== $tenant && $this->features->isEnabled($tenant, $featureCode);
 *     }
 * }
 * ```
 *
 * > ⚠️ **Without an implementation registered, `#[RequiresFeature]` denies everything** for
 * > non-super-admins. Deliberately: a feature gate that opens when its checker is missing turns
 * > every paid module into a free one.
 */
interface FeatureCheckerInterface
{
    public function isEnabled(AclUserInterface $user, string $featureCode): bool;
}
