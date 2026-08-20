<?php

declare(strict_types=1);

namespace Jul6Art\AclBundle;

use Jul6Art\AclBundle\DependencyInjection\Compiler\OptionalContractPass;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\HttpKernel\Bundle\Bundle;

/**
 * A permission engine that sits above an application's own user, tenant and permission storage.
 *
 * The bundle carries the mechanism — parsing, tenant resolution, context, decision, voter,
 * delegation, the two attributes — and never the catalogue. Which permission codes exist, and what
 * each role gets by default, is business policy: it stays in the application, where it can be read
 * and reviewed alongside the screens it protects.
 */
class AclBundle extends Bundle
{
    #[\Override]
    public function build(ContainerBuilder $container): void
    {
        parent::build($container);

        $container->addCompilerPass(new OptionalContractPass());
    }
}
