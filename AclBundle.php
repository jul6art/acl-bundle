<?php

declare(strict_types=1);

namespace Jul6Art\AclBundle;

use Symfony\Component\HttpKernel\Bundle\Bundle;

/**
 * Symfony ACL bundle.
 *
 * Registering a compiler pass? Override `build()` here — a pass is how you check that a service
 * the application may or may not have actually exists, which an extension cannot do (extensions
 * run before the other bundles have had their say):
 *
 * ```php
 * #[\Override]
 * public function build(ContainerBuilder $container): void
 * {
 *     parent::build($container);
 *
 *     $container->addCompilerPass(new SomethingOptionalPass());
 * }
 * ```
 */
class AclBundle extends Bundle
{
}
