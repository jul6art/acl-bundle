<?php

declare(strict_types=1);

namespace Jul6Art\AclBundle\Tests\Fixtures;

use Jul6Art\AclBundle\Attribute\CheckPermission;
use Jul6Art\AclBundle\Attribute\RequiresFeature;
use Symfony\Component\HttpFoundation\Response;

/**
 * A controller shaped like a real one, so the attribute resolution is exercised through reflection
 * exactly as it is at runtime — a hand-built array of attributes would prove nothing about
 * inheritance or precedence.
 */
#[CheckPermission('cms:page:read')]
#[RequiresFeature('cms')]
final class AnnotatedController
{
    public function inherited(): Response
    {
        return new Response('inherited');
    }

    #[CheckPermission('cms:page:update')]
    public function overridden(): Response
    {
        return new Response('overridden');
    }

    #[RequiresFeature('crm.manage', 'erp.manage', 'sirh.manage')]
    public function anyOfThree(): Response
    {
        return new Response('any');
    }
}
