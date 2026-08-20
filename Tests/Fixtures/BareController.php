<?php

declare(strict_types=1);

namespace Jul6Art\AclBundle\Tests\Fixtures;

use Symfony\Component\HttpFoundation\Response;

/**
 * No attributes at all: the case that must resolve no context and gate nothing.
 */
final class BareController
{
    public function index(): Response
    {
        return new Response('bare');
    }
}
