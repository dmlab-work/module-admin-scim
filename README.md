# DmLab_AdminScim

> SCIM 2.0 provisioning server for Magento 2 admin users.

![License](https://img.shields.io/badge/license-OSL--3.0-green) ![Magento](https://img.shields.io/badge/Magento-2.4-orange) ![PHP](https://img.shields.io/badge/PHP-8.3--8.5-blue) ![Version](https://img.shields.io/badge/version-0.0.1-lightgrey)

`DmLab_AdminScim` turns Magento into a SCIM 2.0 (RFC 7643/7644) provisioning
server, so an identity provider such as Okta or Entra can create, update, and
deactivate Magento admin users automatically — without anyone logging in. It
complements SSO login: where JIT provisioning acts at login time, SCIM adds
background provisioning and, crucially, **deprovisioning**.

Scope is admin users only (Users plus Groups → ACL roles). B2C/B2B customer
provisioning is out of scope.

## Endpoints

The IdP is the SCIM **client**; Magento is the **server**. All endpoints live
under the `admin-scim/v2` route area, derived from the store base URL:

```
https://<your-store>/admin-scim/v2
```

| Method | Path | Purpose |
|---|---|---|
| GET | `/ServiceProviderConfig` | Advertised capabilities |
| GET | `/ResourceTypes` | User + Group resource types |
| GET | `/Schemas` | User + Group schema definitions |
| POST | `/Users` | Create an admin user |
| GET | `/Users/{id}` · `/Users?filter=…` | Read one · list with filter + pagination |
| PUT | `/Users/{id}` | Replace the whole resource |
| PATCH | `/Users/{id}` | Add/replace/remove attrs; `active=false` deprovisions |
| POST | `/Groups` | Create a group |
| GET | `/Groups/{id}` · `/Groups?filter=…` | Read one · list with filter + pagination |
| PATCH | `/Groups/{id}` | Attribute + member add/replace/remove |

Unsupported verbs answer `501 Not Implemented`. Errors use the RFC 7644 error
schema (`urn:ietf:params:scim:api:messages:2.0:Error`) with the correct status.

## Setup

1. Install the module and run
   `bin/magento setup:upgrade`.
2. In the admin panel go to **Stores → Configuration → DMLab → Admin SCIM**.
3. **Enable Admin SCIM** = *Yes*. The endpoint is disabled by default.
4. **Bearer Token** — set a long random value. It is stored encrypted and is the
   credential the IdP presents. Point your IdP's SCIM app at the base URL above and
   configure it to send `Authorization: Bearer <token>`.
5. **Group → Role Map** — one `displayName=role_id` per line, mapping a SCIM group
   to a Magento admin role id (`#` lines ignored). A provisioned member gets the
   first matching role (admins hold a single role).
6. **Default Role Id** — role assigned when a member's groups match no rule. Leave
   empty to deny (no role) unmapped members.

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

The core is strict-RFC. IdP SCIM clients deviate — Entra especially (flat complex
attrs in PATCH, `value` in group-member remove, ADD/REPLACE inconsistency). Those
quirks are **not** in the core; a per-IdP plugin (`admin-scim-okta`,
`admin-scim-azure`) absorbs them via a normalization seam:

- The plugin implements `DmLab\AdminScim\Api\RequestNormalizerInterface` —
  a pure, total `normalize(resourceType, operation, payload)` that rewrites the
  decoded body toward RFC shape and returns unchanged payloads it does not own.
- It `di`-merges the normalizer into `RequestNormalizerChain` (see `etc/di.xml`),
  which runs before every create/replace/patch. With no plugin installed the chain
  is empty and bodies pass through untouched.

Running against a real IdP therefore needs the matching provider plugin.

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

## Requirements

- Magento **2.4.x**
- PHP **8.3 – 8.5**

## License

[OSL-3.0](LICENSE) © DMLab. Commercial licensing and support: <https://dmlab.work>.
