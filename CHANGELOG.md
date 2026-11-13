# Changelog

## Unreleased

## 0.4.0 (2026-11-13)

### Changed

- The Dashboard widget says "No failed deliveries" instead of showing an empty panel.

## 0.3.0 (2026-11-12)

### Added

- The Audit Log names what Webhooks changed in the past tense, such as **Created Webhook** and **Redelivered Webhook**.

### Changed

- Needs CMS 0.26: the Audit Log names the webhook a change was made to through the core's `audit.subject` filter, which an older CMS does not have.
- The Audit Log names the webhook a change was made to (its name, else its address): **Tested Webhook** said nothing else.
- The button of the **New Webhook** dialog says **Create Webhook**, as every dialog that makes something names what it makes.

## 0.2.0 (2026-11-11)

### Added

- Webhooks: tell other services when an entry is saved, published, unpublished or deleted, a file is uploaded, changed or deleted, an account changes, or the site is published. Each webhook has a name and chooses its events under **Settings → Webhooks**. Needs CMS 0.22.
- Every delivery is signed with the address's own secret (`X-Cms-Signature`, HMAC-SHA256 over the time and the body), so a receiver knows it is yours and fresh.
- A delivery that fails is tried again after 1 minute, 5 minutes, 30 minutes, 2 hours and 12 hours, without a worker: right after the response on PHP-FPM and LiteSpeed, from `php cms webhooks:deliver` in cron elsewhere. An address that just failed is not asked again in the same run. The delivery log shows every attempt and the receiver's answer, and **Send Again** redelivers one.
- **Send Test** sends a test event to one address, and the **Webhooks** widget on the home screen says when deliveries failed or are being tried again.
- Agents, the API and the CLI manage them with `webhooks/list`, `get`, `create`, `update`, `renew-key`, `delete` and `test`, and the log with `webhook-deliveries/list`, `get` and `create` (send again). Each says what it answers with, and a dry run lists what would be made, changed or removed.

### Security

- A webhook goes only to an `https://` address on the public internet. Deliveries are sent by the CMS's own fetcher: every address the host resolves to has to be public, IPv6 forms that carry an IPv4 address included, the connection is checked against it, and redirects and proxies are not followed.
- Signing secrets are encrypted with the site's other secrets, never in `config/`. A secret is shown once, to whoever made or renewed it, and no listing or page shows it again.
