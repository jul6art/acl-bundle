<?php

declare(strict_types=1);

namespace Jul6Art\AclBundle\Tests\Fixtures;

use Jul6Art\AclBundle\Contract\AclUserInterface;
use Jul6Art\AclBundle\Contract\FeatureCheckerInterface;

final class StaticFeatureChecker implements FeatureCheckerInterface
{
    /**
     * @var list<string>
     */
    public static array $enabled = [];

    #[\Override]
    public function isEnabled(AclUserInterface $user, string $featureCode): bool
    {
        return \in_array($featureCode, self::$enabled, true);
    }
}
