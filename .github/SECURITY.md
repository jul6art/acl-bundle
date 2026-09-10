# Security Policy

## Supported versions

`jul6art/acl-bundle` is installed by other applications through Composer, so a fix here
reaches them the moment they update. Only the current major line gets one.

| Version | Supported |
| --- | --- |
| `1.x` | ✅ |
| any older tag or fork | ❌ |

Support means security fixes on the latest release of that line — upgrade to it before
reporting, in case the problem is already gone.

## What is in scope

This bundle *is* an access-control mechanism, so almost any defect in it is a security
defect. The ones that matter most:

* **A decision that grants what it should refuse** — an unknown or misspelled permission
  reading as allowed, a revoked role or an expired delegation still granting, a negative
  per-user override being ignored by the aggregation.
* **A cache entry crossing identities** — a verdict computed for one user served to another,
  or a stale entry surviving a permission change that should have invalidated it.
* **A feature flag opening more than it declares**, or a flag whose default is permissive
  when the configuration is absent.
* **Delegation that does not end** — a delegated permission outliving its window, its
  delegator's own loss of that permission, or the account being deactivated.
* **A controller-protection path that fails open** — the attribute or helper silently
  granting access when the engine cannot answer.

Out of scope: vulnerabilities in Symfony, Doctrine, API Platform or any other third-party
package — report those to the project that owns the code, and they will reach you through
your own `composer update`. Also out of scope: an application that misconfigures this bundle
in a way the README warns against, though a warning that turns out to be easy to miss is
worth an issue of its own.

## Reporting a vulnerability

**Do not open a public issue for a security problem.**

Use [GitHub's private vulnerability reporting](https://github.com/jul6art/acl-bundle/security/advisories/new)
(the **Security** tab → *Report a vulnerability*). It opens a draft advisory only
you and the maintainers can read, and it is the channel this project prefers —
no email address needs to be published for it to work.

Please include:

* the version of `jul6art/acl-bundle` and of Symfony you are running,
* the relevant part of your bundle configuration,
* the shortest reproduction you have — ideally a failing test against this
  repository, since that is what a fix will be built on,
* what an attacker gains: which check is bypassed, which data is read or
  written, and whether authentication is required.

## What to expect

* An acknowledgement within **7 days**.
* An assessment — accepted, out of scope, or needing more detail — within
  **14 days**.
* For an accepted report: a fix released on the supported line, a
  [security advisory](https://github.com/jul6art/acl-bundle/security/advisories)
  describing the impact and the version to upgrade to, and credit in it unless
  you ask otherwise.

Please give the maintainers a reasonable window to ship a release before disclosing
publicly. This project runs no bug-bounty programme and offers no payment.