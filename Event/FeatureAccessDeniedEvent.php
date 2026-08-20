<?php

declare(strict_types=1);

namespace Jul6Art\AclBundle\Event;

use Jul6Art\AclBundle\Contract\AclUserInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Dispatched when `#[RequiresFeature]` refuses, so the application decides what the user sees.
 *
 * Without a listener the request gets a 403, which is correct for an API and unhelpful for a
 * browser: a person clicking a menu entry for a module they have not enabled should land on a page
 * that says so, not on an error. But the page, the flash message and its wording are the
 * application's — a bundle that redirected to its own route would be inventing one, and one that
 * added a flash would be inventing a translation key and a domain.
 *
 * ```php
 * #[AsEventListener]
 * public function onFeatureDenied(FeatureAccessDeniedEvent $event): void
 * {
 *     $event->setResponse(new RedirectResponse($this->urls->generate('app_access_denied')));
 * }
 * ```
 *
 * The listener may also leave the response unset to keep the 403 — checking `$event->reason` is
 * enough to redirect in one case and refuse in the other.
 */
final class FeatureAccessDeniedEvent
{
    private ?Response $response = null;

    /**
     * @param list<string> $featureCodes every code the controller declared; access was refused
     *                                   because *none* of them is enabled
     */
    public function __construct(
        public readonly Request $request,
        public readonly AclUserInterface $user,
        public readonly array $featureCodes,
        public readonly FeatureDenialReason $reason,
    ) {
    }

    public function setResponse(Response $response): void
    {
        $this->response = $response;
    }

    public function getResponse(): ?Response
    {
        return $this->response;
    }
}
