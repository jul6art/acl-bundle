<p align="center">
    <a href="https://devinthehood.com"><img src="https://github.com/jul6art/symfony-skeleton-generator/blob/master/public/img/logo.png?raw=true" alt="logo dev in the hood" width="400"></a>
</p>

<p align="center">
    <a href="https://opensource.org/licenses/MIT" target="_blank"><img src="https://img.shields.io/badge/License-MIT-yellow.svg" alt="License"></a>
    <img src="https://img.shields.io/static/v1?label=stable&message=v1&color=orange" alt="Version">
</p>

Symfony ACL bundle
==================

Symfony ACL bundle

Requirements
------------

- PHP ^8.5
- Symfony ^7.4 || ^8.0

Installation
------------

```shell
composer require jul6art/acl-bundle
```

Then register it in `config/bundles.php` (Flex does this for you):

```php
Jul6Art\AclBundle\AclBundle::class => ['all' => true],
```

Configuration
-------------

```yaml
# config/packages/acl.yaml
acl:
    # Leaves the bundle installed and inert when false.
    enabled: true
```

`acl.enabled` is also exposed as a container parameter.

Usage
-----

A permission engine — parsing, tenant resolution, decision, voter, delegation, two attributes. It
carries the **mechanism**, never the catalogue: which permission codes exist and what each role
gets by default is business policy and stays in your application, next to the screens it protects.

### Step 1 — implement the contracts

Two are needed to get a voter working, two more unlock the rest. All four are plain interfaces over
things your application already has.

```php
// Your existing User entity — five accessors, four of which it probably already has.
class User implements UserInterface, AclUserInterface
{
    public function getId(): ?int { return $this->id; }
    public function getRoles(): array { return $this->roles; }
    public function isActive(): bool { return $this->active; }
    public function isSuperAdmin(): bool { return \in_array('ROLE_SUPER_ADMIN', $this->getRoles(), true); }
    public function getTenant(): ?AclTenantInterface { return $this->organization; }
}

// Your existing Organization / Workspace / Account entity.
class Organization implements AclTenantInterface
{
    public function getId(): ?int { return $this->id; }
    public function getSlug(): ?string { return $this->slug; }
}

// Where permissions are stored — the one thing the bundle cannot provide.
final class DoctrinePermissionSetProvider implements PermissionSetProviderInterface
{
    public function overridesFor(AclUserInterface $user): array
    {
        return $this->overrides->findAllForUser($user);          // ['cms:page:publish' => false, …]
    }

    public function grantedByRolesFor(AclUserInterface $user): array
    {
        return $this->rolePermissions->findGrantedForRoles($user->getRoles(), $user->getTenant());
    }
}
```

Register the provider and alias the interface to it — the ordinary Symfony pattern is what the
bundle looks for:

```yaml
services:
    App\Security\DoctrinePermissionSetProvider: ~
    Jul6Art\AclBundle\Contract\PermissionSetProviderInterface: '@App\Security\DoctrinePermissionSetProvider'
```

> ⚠️ **Both provider methods are called once per user per request, not once per check.** That is
> what keeps a page with twenty permission checks at two queries instead of forty. So a full read
> is fine; a lazy per-permission implementation defeats the point.

> ⚠️ **With no provider registered the engine denies.** No override and no role grant is ever
> found, so only a super admin or a tenant administrator gets through. That is deliberate: an ACL
> whose storage is missing must refuse, and one that allowed instead would be a hole invisible in a
> green test suite.

`PermissionStoreInterface` (six write methods) is needed only for delegation, and
`FeatureCheckerInterface` only for `#[RequiresFeature]`. Each missing implementation removes its
service rather than breaking the container.

### Step 2 — configure

```yaml
# config/packages/acl.yaml
acl:
    super_admin_role: ROLE_SUPER_ADMIN
    tenant_admin_role: ROLE_ORGANIZATION_ADMIN
    tenant_header: '%api.tenant_header%'          # the same header your API documents
    tenant_request_attribute: _api_organization   # where your tenant listener puts the entity
    tenant_route_parameters: ['organization', 'organizationSlug', 'domain']
    route_permissions:
        app_security_login: 'auth:login'          # for controllers you cannot annotate
```

Both role names are configuration on purpose: this bundle never assumes `ROLE_SUPER_ADMIN` exists.

### Step 3 — protect a controller

```php
#[CheckPermission('cms:page:read')]
final class CmsPageController extends AbstractController
{
    #[IsGranted('cms:page:read')]
    #[Route('/pages', methods: ['GET'])]
    public function index(): Response { … }

    #[CheckPermission('cms:page:update')]        // wins over the class, no merging
    #[IsGranted('cms:page:update')]
    public function edit(Page $page): Response { … }
}
```

> ⚠️ **`#[CheckPermission]` does not deny anything by itself.** It only resolves the context that
> the voter then decides on. The refusal comes from `#[IsGranted]`, `isGranted()` or
> `denyAccessUnlessGranted()`. A controller carrying `#[CheckPermission]` and nothing else is
> **open** — which is precisely the mistake it looks like it prevents. The reason the attribute
> exists anyway is the API rule below: without a resolved context, an API request never gets
> checked for which tenant it names.

Codes are `resource:action`, with as many namespace segments as you like — `user:read`,
`cms:page:read`, `erp:invoice:line:update`. Lowercase, plus `.`, `_` and `-`. No wildcards: `cms:*`
is refused rather than silently ignored, because nothing in the engine implements one.

### How a decision is made

In order, and the order is the security model:

| # | Rule | Why it is where it is |
|---|---|---|
| 1 | A super admin passes | Before the activity check, so support can reach a broken tenant |
| 2 | A deactivated account is refused | Deactivating must be enough on its own |
| 3 | **An API request with no resolved tenant is refused** | See below |
| 4 | A user override decides, grant or deny | Makes "everyone in this role except this person" expressible |
| 5 | A role grant passes | |
| 6 | A tenant admin passes — on the API, only for their own tenant | The line between an admin and a cross-tenant reader |
| 7 | Otherwise refused | Absence of a rule is never a pass |

> ⚠️ **Rule 3 is the one to understand.** An API request whose tenant could not be resolved is
> *refused*, not scoped to the caller's own tenant. Falling back would turn a forgotten header into
> a cross-tenant collection, answered with a 200 that is indistinguishable from a correct one.

A tenant is resolved from three places, in decreasing order of trust: a validated tenant object on
a request attribute, then a route parameter, then the request header — which is only the client's
claim.

### Feature flags

```php
#[RequiresFeature('cms')]
final class CmsPageController extends AbstractController { … }

#[RequiresFeature('crm.manage', 'erp.manage', 'sirh.manage')]   // OR — one enabled is enough
public function customFields(): Response { … }
```

Unlike `#[CheckPermission]`, this one **does** refuse by itself: a feature flag has nothing to vote
on. Super admins bypass it; anonymous requests pass through, because a feature gate is not
authentication and a 403 here would hide the login redirect the firewall is about to issue.

The default refusal is a 403, right for an API and unhelpful in a browser. Subscribe to decide what
a person sees — the page, the message and its translation are yours:

```php
#[AsEventListener]
public function onFeatureDenied(FeatureAccessDeniedEvent $event): void
{
    if (FeatureDenialReason::NoTenant === $event->reason) {
        $event->setResponse(new RedirectResponse($this->urls->generate('app_no_organization')));

        return;
    }

    $event->setResponse(new RedirectResponse($this->urls->generate('app_access_denied')));
}
```

> ⚠️ **Without a `FeatureCheckerInterface` implementation, `#[RequiresFeature]` is inert.** That is
> the one place absence relaxes a check, and it is the honest reading: the attribute has nothing to
> check against, and refusing every gated page in an application with no feature system would break
> it outright.

### Delegating a permission

```php
$delegation->grantToUser($actor, $target, 'cms:page:publish');
$delegation->denyToUser($actor, $target, 'erp:invoice:validate');   // an explicit refusal
$delegation->removeUserOverride($actor, $target, 'cms:page:publish');
$delegation->grantToRole($actor, 'ROLE_EDITOR', 'cms:page:publish', $tenant);
```

Every method throws `\DomainException` when the actor may not, and the message says which rule
stopped them. Throwing rather than returning false is the point: "did not happen" and "was refused"
must not be the same value, or a UI reports success for an escalation it just blocked.

The five rules: a super admin may do anything; nobody delegates to themselves; only a tenant
administrator delegates; only inside their own tenant (and two users *without* a tenant are not in
the same one); and only a permission the actor holds. Role templates are stricter still — a tenant
must be named, and the super-admin role is untouchable.

### Cache invalidation

`AclPermissionReadService` caches per user for the life of the instance: the request on the web, the
**process** on the CLI. A long-running command that grants a permission and then checks it needs
`$readService->flush($user)`; on the web the mutation and the next check are two requests and this
is unnecessary.

### What stays in your application

The catalogue — the list of permission codes, and each role's defaults. The bundle has no
`PermissionCatalogInterface`, deliberately: nothing inside the engine reads a catalogue, and an
interface with no consumer in the bundle is weight without a contract. Keep those tables where they
can be reviewed next to the features they describe.

Quality assurance
-----------------

```shell
composer qa            # cs-check + rector-check + phpstan (level max) + phpunit
```

Run `composer qa`, not the single tool you have in mind: the CI's "Coding standards" job runs
Rector too, and its `lowest deps` job installs the minimum of every constraint — which is where
this ecosystem has repeatedly found what a local run could not.

`extra.symfony.require` states which Symfony line this bundle targets; the CI enforces it with
`SYMFONY_REQUIRE` on both the highest and the lowest job. A local `composer install` may still
resolve a newer Symfony, which broadens what you exercise rather than narrowing it — but it means
the toolchain can propose something that only makes sense on one branch. `rector.php` skips one
such rule already, with the reason written next to it.

Whatever you do, keep the code free of classes that exist on only one of the declared branches.
A bundle promising `^7.4 || ^8.0` has to hold both.

License
-------

The ACL bundle is open-sourced software licensed under the [MIT license](https://opensource.org/licenses/MIT).

&copy; 2026 [jul6art](https://devinthehood.com)
