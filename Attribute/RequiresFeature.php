<?php

declare(strict_types=1);

namespace Jul6Art\AclBundle\Attribute;

/**
 * Declares the feature flag(s) a controller — or one of its actions — needs switched on.
 *
 * ```php
 * #[RequiresFeature('cms')]
 * final class CmsPageController extends AbstractController { … }
 * ```
 *
 * Several codes mean **OR**: access is granted as soon as one of them is enabled. That is what a
 * capability shared by several modules looks like — custom fields exposed by CRM, ERP *or* HR:
 *
 * ```php
 * #[RequiresFeature('crm.manage', 'erp.manage', 'sirh.manage')]
 * ```
 *
 * ## What executes it
 *
 * `FeatureAccessListener`, on `kernel.controller`, asking the application's
 * {@see \Jul6Art\AclBundle\Contract\FeatureCheckerInterface}. Unlike `#[CheckPermission]`, this one
 * **does** deny by itself — it replaces the controller with a 403 (or a redirect, if one is
 * configured).
 *
 * Super admins bypass it entirely. A method-level declaration wins over the class-level one.
 */
#[\Attribute(\Attribute::TARGET_CLASS | \Attribute::TARGET_METHOD)]
final readonly class RequiresFeature
{
    /**
     * @var list<string>
     */
    public array $featureCodes;

    public function __construct(string ...$featureCodes)
    {
        $this->featureCodes = array_values($featureCodes);
    }
}
