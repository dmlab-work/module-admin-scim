# DmLab_AdminScim

> SCIM 2.0 provisioning server for Magento 2 admin users.

[![CI](https://github.com/dmlab-work/module-admin-scim/actions/workflows/ci.yml/badge.svg)](https://github.com/dmlab-work/module-admin-scim/actions/workflows/ci.yml) [![codecov](https://codecov.io/gh/dmlab-work/module-admin-scim/graph/badge.svg)](https://codecov.io/gh/dmlab-work/module-admin-scim) [![Packagist Version](https://img.shields.io/packagist/v/dmlab/module-admin-scim)](https://packagist.org/packages/dmlab/module-admin-scim) [![Downloads](https://img.shields.io/packagist/dt/dmlab/module-admin-scim)](https://packagist.org/packages/dmlab/module-admin-scim) [![PHP](https://img.shields.io/packagist/dependency-v/dmlab/module-admin-scim/php)](https://packagist.org/packages/dmlab/module-admin-scim) [![License](https://img.shields.io/packagist/l/dmlab/module-admin-scim)](LICENSE) ![Magento](https://img.shields.io/badge/Magento-2.4-orange)

`DmLab_AdminScim` turns Magento into a SCIM 2.0 (RFC 7643/7644) provisioning
server, so an identity provider such as Okta or Entra can create, update, and
deactivate Magento admin users automatically — without anyone logging in. It
complements SSO login: where JIT provisioning acts at login time, SCIM adds
background provisioning and, crucially, **deprovisioning**.

Scope is admin users only (Users plus Groups → ACL roles). B2C/B2B customer
provisioning is out of scope.

## Scope

SCIM operates only on admins it created (`admin_user.dmlab_scim_managed = 1`;
admins with an `externalId` or a SCIM group membership from earlier releases are
flagged on upgrade — check that no local admin got flagged that way).

- Local admins are never visible to GET/list, never changed by PUT/PATCH/DELETE,
  never accepted as group members and never touched by group→role sync.
- Creating a user whose `userName` belongs to a local admin returns `409` — no
  adoption.
- A deactivate, delete or role strip that would leave no active admin holding a
  role is refused with `400 mutability`.

## Endpoints

The IdP is the SCIM **client**; Magento is the **server**. All endpoints live
under the `admin-scim/v2` route area, derived from the store base URL:

```
https://<your-store>/admin-scim/v2
```

| Method | Path | Purpose |
|---|---|---|
| GET | `/ServiceProviderConfig` | Advertised capabilities |
| GET | `/ResourceTypes` · `/ResourceTypes/{id}` | All resource types · one (`404` if unknown) |
| GET | `/Schemas` · `/Schemas/{id}` | All schema definitions · one by URN (`404` if unknown) |
| POST | `/Users` | Create an admin user |
| GET | `/Users/{id}` · `/Users?filter=…` | Read one · list with filter + pagination |
| PUT | `/Users/{id}` | Replace the whole resource |
| PATCH | `/Users/{id}` | Add/replace/remove attrs; `active=false` deprovisions and ends open admin sessions |
| DELETE | `/Users/{id}` | Hard delete (`204`) |
| POST | `/Groups` | Create a group |
| GET | `/Groups/{id}` · `/Groups?filter=…` | Read one · list with filter + pagination |
| PUT | `/Groups/{id}` | Replace name, externalId and members |
| PATCH | `/Groups/{id}` | Attribute + member add/replace/remove |
| DELETE | `/Groups/{id}` | Delete (`204`); former members' roles re-derived |

Other verbs (e.g. `HEAD`, `OPTIONS`) answer `501 Not Implemented`; `401` carries `WWW-Authenticate: Bearer`. Errors use the RFC 7644 error
schema (`urn:ietf:params:scim:api:messages:2.0:Error`) with the correct status.

## Setup

1. Install the module and run
   `bin/magento setup:upgrade`.
2. In the admin panel go to **Stores → Configuration → DMLab → Admin SCIM**.
3. **Enable Admin SCIM** = *Yes*. The endpoint is disabled by default.
4. **Provider** — the IdP whose request quirks are normalized. Under *None* a
   single installed provider plugin still applies; with several, none runs until
   one is picked.
5. **Bearer Token** — set a long random value. It is stored encrypted and is the
   credential the IdP presents. Point your IdP's SCIM app at the base URL above and
   configure it to send `Authorization: Bearer <token>`.
6. **Group → Role Map** — one `displayName=role_id` per line, mapping a SCIM group
   to a Magento admin role id (`#` lines ignored). A provisioned member gets the
   first matching role (admins hold a single role). `displayName` is not unique: a
   rule applies to every group with that name.
7. **Default Role Id** — role assigned when an admin's groups match no rule,
   including admins created with no groups (applied at user create/update). Leave
   empty to deny (no role).

## Supported features

Advertised honestly in `ServiceProviderConfig`:

| Feature | Supported |
|---|---|
| PATCH | ✅ |
| Filter (`userName eq`, `externalId eq`, `displayName eq`) | ✅ (max 200 results) |
| Pagination (`startIndex`, `count`) | ✅ |
| Bulk | ❌ |
| Sort | ❌ |
| ETag | ❌ |
| Change password | ❌ |
| Auth scheme | OAuth Bearer Token (RFC 6750) |

## Provider quirks

The core accepts common IdP PATCH forms: URN-prefixed paths,
`emails[type eq "work"].value` / `emails[primary eq true].value`, stringified
booleans. Attributes `admin_user` can't store (e.g. `title`, `phoneNumbers`, the
enterprise extension) are ignored, not rejected; removing `userName`, `name.*`,
`emails` or `active` is `400 mutability`. IdP-specific shapes (e.g. Entra's flat
complex attrs) are absorbed by a per-IdP plugin via a normalization seam:

- The plugin implements `DmLab\AdminScim\Api\RequestNormalizerInterface` —
  a pure, total `normalize(resourceType, operation, payload)` that rewrites the
  decoded body toward RFC shape and returns it unchanged when nothing needs rewriting.
- It `di`-merges the normalizer into `RequestNormalizerChain` (see `etc/di.xml`)
  under its provider code (`okta`, `azure`, …). Before every create/replace/patch
  the chain runs **only the active provider's** normalizer (the **Provider** config
  field). With no plugin installed bodies pass through untouched.

## Security notes

- The bearer token is a full admin-provisioning credential. It is stored encrypted
  (Magento `Encrypted` backend model) and compared in constant time (`hash_equals`).
- Rotate the token by setting a new value; the old value stops working immediately.
- The endpoint is **disabled by default** and every request must authenticate —
  there is no anonymous read path.
- Auth failures return generic `401` messages that never reveal whether a token is
  configured. Unexpected errors are logged server-side and returned as a `500` with
  no internals leaked.
- Serve over TLS only — the token travels in the `Authorization` header.
- `active=false` deprovisions an admin account, so treat the token with the same
  care as an admin password.
- The token is marked sensitive, so `app:config:dump` never writes it to
  `app/etc/config.php`.
- Failed-auth throttling: 10 failures per client IP, each within 15 min of the last → `429` with
  `Retry-After`. The client IP is `REMOTE_ADDR`; `X-Forwarded-For` is ignored. Behind a
  reverse proxy, list its IPs in the `trustedProxies` argument of
  `DmLab\AdminScim\Model\Auth\ClientIp` in `di.xml`, otherwise every client shares the
  proxy IP.
- Audit log: SCIM writes, role changes and auth failures (with client IP; never the
  token, headers or body) go to `var/log/dmlab_admin_scim_audit.log`.

## Requirements

- Magento **2.4.x** (tested on 2.4.9)
- PHP **8.3 – 8.5**

## License

[OSL-3.0](LICENSE) © DMLab. Commercial licensing and support: <https://dmlab.work>.
