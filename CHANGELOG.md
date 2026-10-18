# Changelog

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
