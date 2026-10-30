# Changelog

## 0.2.4

- Requires Magento 2.4.7+ and PHP 8.2–8.5.
- CI runs unit tests on PHP 8.2–8.5; CI installs are no longer counted as Packagist downloads.
- composer.json: keywords, homepage and support links.
- README restructured.

## 0.2.3

- SCIM deactivation and delete revoke the admin's REST API tokens (core does this only in a `User::save()` plugin, which the SCIM write path skips); requires `Magento_Integration`.

## 0.2.2

- SCIM deactivation (`active=false` via PUT/PATCH) ends the admin's open backend sessions; requires `Magento_Security`.
- A deactivate, delete or role strip that would leave no active admin holding a role is refused with `400 mutability`.
- Group `displayName` is no longer unique (Entra allows duplicates); a Group → Role rule applies to every group with that name.
- `userName` up to 255 chars is stored and returned as sent (`admin_user.dmlab_scim_user_name`, backfilled on upgrade); logins over 40 chars become a prefix plus a hash.
- `attributes` / `excludedAttributes` are honoured; group members are not loaded when excluded.
- Filters support `eq ne co sw ew gt ge lt le pr`, `and`/`or`/`not` and value paths; `externalId` matching is case-exact.
- `POST /Users/.search` and `/Groups/.search`; `/Me`, `/Bulk` and root `/.search` answer `501`, unknown SCIM paths a JSON `404`.
- `201` responses carry `Location`; resources carry `meta.created` / `meta.lastModified` (new `dmlab_scim_group.created_at` / `updated_at`).
- `externalId` / `displayName` over 255 chars are `400 invalidValue`; URN-qualified paths are accepted in Group PATCH and filters.
- New **Request Limit** setting: 1200 requests per minute per token by default, then `429` with `Retry-After`; `0` disables it.
- Bearer tokens are compared as SHA-256 digests.
- Upgrade: run `setup:upgrade` (schema change + data patch).

## 0.2.1

- Fixed: audit log no longer duplicated into `system.log` / `debug.log`.
- `GET /ResourceTypes/{id}` and `/Schemas/{id}` return the single resource (`404` if unknown).
- `401` responses carry `WWW-Authenticate: Bearer`.
- `OPTIONS` and other unsupported verbs answer SCIM `501`; discovery endpoints are GET-only.
- Tested on Magento 2.4.9 and PHP 8.5.

## 0.2.0

### Breaking

- SCIM sees only SCIM-managed admins (`admin_user.dmlab_scim_managed = 1`). Local admins are invisible to GET/list, immutable via PUT/PATCH/DELETE and never touched by group→role sync; a `userName` clash with one is `409`. Existing admins with an `externalId` or a SCIM group membership are flagged on upgrade — including any local admin a 0.1.x IdP pushed into a group, which becomes SCIM-managed (deletable by the IdP); review `SELECT user_id, username FROM admin_user WHERE dmlab_scim_managed = 1` after upgrading and reset the flag for local accounts. Flag any other SCIM-created admin manually (`UPDATE admin_user SET dmlab_scim_managed = 1 WHERE user_id IN (…)`).
- User create/replace/patch re-derive the admin's role from its SCIM groups: with no mapped group and no `default_role`, a manually assigned role is stripped.
- Provider plugins must require `dmlab/module-admin-scim: ^0.2`.
- With several provider plugins installed, no normalizer runs until **Provider** is picked in config.

### Added

- `default_role` applied on user create/update for admins without a group-mapped role.
- `DELETE /Users/{id}` (hard delete, `204`), `PUT /Groups/{id}`, `DELETE /Groups/{id}` (`204`, members' roles re-derived).
- PATCH compatibility with default Entra/Okta payloads: URN-prefixed and filtered paths, unsupported core attributes ignored, stringified booleans, `id` in group replace ignored.
- **Provider** config field: only the active IdP's request normalizer runs.
- Failed-auth throttling: 10 failures per IP, each within 15 min of the last → `429` with `Retry-After`.
- Audit log of SCIM writes, role changes and auth failures in `var/log/dmlab_admin_scim_audit.log`.
- Bearer token marked sensitive (excluded from `app:config:dump`).
- Packaging: full composer requirements, module sequence, OSL-3.0 headers, i18n phrases.

## 0.1.1

- Rebrand to the `dmlab` vendor and `DmLab` namespace.

## 0.1.0

- Initial release: SCIM 2.0 `/Users` and `/Groups` endpoints, bearer-token auth, group→role mapping.
