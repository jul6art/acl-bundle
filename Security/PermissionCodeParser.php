<?php

declare(strict_types=1);

namespace Jul6Art\AclBundle\Security;

/**
 * Splits a permission code into the resource it names and the action it allows.
 *
 * ```php
 * $parser->parse('cms:page:read');   // ['resource' => 'cms:page', 'action' => 'read']
 * $parser->parse('user:read');       // ['resource' => 'user',     'action' => 'read']
 * ```
 *
 * The **last** segment is the action and everything before it is the resource, so a namespaced
 * code keeps working without the parser knowing how many levels a project uses.
 *
 * ## Why it throws rather than returning null
 *
 * An unparseable code is a bug in the source, not a request to deny. `PermissionVoter` catches the
 * exception to decide it does not *support* an attribute — which is how `ROLE_ADMIN` and
 * `cms:page:read` can go through the same `isGranted()` without the voter claiming attributes that
 * are not permission codes. Returning null instead would make "not a permission code" and "denied"
 * the same value, and a typo in a code would silently become a refusal nobody can trace.
 */
final class PermissionCodeParser
{
    /**
     * @return array{resource: string, action: string}
     *
     * @throws \InvalidArgumentException when the code is empty, has fewer than two segments, has an
     *                                   empty segment, or contains anything outside
     *                                   `[a-z0-9._-]`
     */
    public function parse(string $permission): array
    {
        $normalized = trim($permission);
        if ('' === $normalized) {
            throw new \InvalidArgumentException('Permission code must not be empty.');
        }

        $chunks = explode(':', $normalized);
        if (\count($chunks) < 2) {
            throw new \InvalidArgumentException('Permission code must follow the "resource:action" format.');
        }

        $action = trim(array_pop($chunks));
        $resource = trim(implode(':', $chunks));

        if ('' === $resource || '' === $action) {
            throw new \InvalidArgumentException('Permission code segments must not be empty.');
        }

        foreach (explode(':', $resource) as $segment) {
            if (!$this->isValidSegment($segment)) {
                throw new \InvalidArgumentException('Permission code contains invalid characters.');
            }
        }

        if (!$this->isValidSegment($action)) {
            throw new \InvalidArgumentException('Permission code contains invalid characters.');
        }

        return [
            'resource' => $resource,
            'action' => $action,
        ];
    }

    private function isValidSegment(string $segment): bool
    {
        return '' !== $segment && 1 === preg_match('/^[a-z0-9._-]+$/', $segment);
    }
}
