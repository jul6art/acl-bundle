<?php

declare(strict_types=1);

namespace Jul6Art\AclBundle\Event;

/**
 * Why a `#[RequiresFeature]` check refused. The two cases need different words in front of a user:
 * one is "your account is not attached to anything yet", the other is "this module is not enabled".
 */
enum FeatureDenialReason: string
{
    /**
     * The actor has no tenant, so no feature can be enabled for them.
     */
    case NoTenant = 'no_tenant';

    /**
     * The actor has a tenant, and none of the declared features is enabled for it.
     */
    case Disabled = 'disabled';
}
