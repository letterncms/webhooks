# Webhooks

Tells other services what happens on your site. When an entry is saved, published, unpublished or deleted, a file is uploaded, an account changes or the site is published, each address that asked for that event gets a signed JSON `POST`. A delivery that fails is tried again, up to six times over about fifteen hours, and every attempt is in the delivery log, from where it can be sent again. No worker is needed: on PHP-FPM and LiteSpeed deliveries go out right after the response of the request that caused them. On other servers, where a visitor would have to wait, a cron job sends them (see below).

## Install

From Plugins → Store in the admin, or:

```bash
composer require letterncms/webhooks
```

## Settings

Under Settings → Webhooks: one row per webhook. Its page has the delivery log and **Send Test**; its Settings tab has the address, an optional name, the events it listens for, **New Signing Secret** and **Delete**. The signing secret is shown once, right after the webhook is made or the secret renewed. The **Webhooks** widget on the home screen is empty unless deliveries failed or are being tried again.

| Kept in | What |
|---|---|
| `config/webhooks.yaml` | The addresses, their names and events, and whether each is on. |
| `storage/secrets.json` | Each address's signing secret, encrypted with the site's other secrets. |
| `CMS_STORE_ALLOW_PRIVATE=true` in `.env` | The CMS's switch for local development: lets everything it fetches, webhooks included, go to `http://` and to private addresses such as `localhost`. Only works when `APP_ENV` is `local` or `testing`. |

A webhook goes only to an `https://` address on the public internet, on port 443, 80, 8080 or 8443. Deliveries are sent by the CMS's own `SafeFetcher`: the host is resolved when a delivery is sent, every address it resolves to has to be public, the connection is checked against that address, and redirects and proxies are not followed.

### What a receiver gets

```http
POST /hook
Content-Type: application/json
X-Cms-Event: entry.published
X-Cms-Delivery: 01m747ke1wwg8r90d75x3j21s5
X-Cms-Attempt: 1
X-Cms-Signature: t=1794364782,v1=4244dd87…

{"id":"01m747ke1wwg8r90d75x3j21s5","event":"entry.published","createdAt":"2026-11-11T02:39:42+00:00","site":"https://example.com","data":{"entry":{"id":"…","collection":"pages","locale":"en","slug":"opening-hours","title":"Opening hours","status":"published","uri":"/opening-hours","url":"https://example.com/opening-hours","publishedAt":"…","updatedAt":"…"}}}
```

`v1` is the HMAC-SHA256, in hex, of `<t>.<body>` with the address's signing secret. Compute it over the body exactly as received, compare in constant time, and refuse a `t` more than five minutes old:

```php
[$t, $v1] = sscanf($_SERVER['HTTP_X_CMS_SIGNATURE'], 't=%d,v1=%s');
$ok = abs(time() - $t) <= 300 && hash_equals(hash_hmac('sha256', $t.'.'.$body, $secret), $v1);
```

Answer with any `2xx` within eight seconds. Anything else is tried again after 1 minute, 5 minutes, 30 minutes, 2 hours and 12 hours; `410 Gone` ends it at once. A redelivery keeps the payload's `id`, so a receiver can tell it has seen it.

A payload says what changed and where to find it, not the content: `data.entry`, `data.media` or `data.account` (id, email, name), who did it in `data.by`, and for `site.built` when it was built and how many pages.

Events: `entry.saved`, `entry.published`, `entry.unpublished`, `entry.deleted`, `media.uploaded`, `media.updated`, `media.deleted`, `account.created`, `account.verified`, `account.updated`, `account.email-changed`, `account.password-changed`, `account.granted`, `account.revoked`, `account.deleted`, `site.built`. An address can also listen for a group (`entry.*`) or everything (`*`) when it is made through an ability.

On a server that is neither PHP-FPM nor LiteSpeed, or on a site with few visits, a cron job sends deliveries and retries on time: `php cms webhooks:deliver` every minute. An address that fails is not asked again in the same run.

## Abilities

All need the `webhooks.manage` permission, which administrators have.

| Ability | What it does |
|---|---|
| `webhooks/list` | The webhooks, with their events and last delivery. |
| `webhooks/get` | One webhook. |
| `webhooks/create` | Add one (`url`, and `name` and `events` if you like). Answers with its signing secret, this once. |
| `webhooks/update` | Change its address, name, events or whether it is on. |
| `webhooks/renew-key` | Make a new signing secret and answer with it, this once. The old one stops working. |
| `webhooks/delete` | Remove a webhook, its secret and its delivery log. |
| `webhooks/test` | Send a `webhook.test` event to one webhook. |
| `webhook-deliveries/list` | The delivery log, of one webhook (`webhook`) or all, by `status`. |
| `webhook-deliveries/get` | One delivery, with its payload and the start of the answer. |
| `webhook-deliveries/create` | Send a delivery again (`from`: its id). |

Each says what it answers with, and everything that changes something takes `dryRun: true`: nothing is written, and the answer lists what would be made, changed or removed, such as how many deliveries a delete forgets.

Agents read the list of events from `cms://webhooks/events`.

## Hooks

It listens for the hooks named after its events and adds none of its own. To send your own deliveries from another plugin, call `Cms\Webhooks\Dispatcher::queueFor($webhookId, $event, $data)`.

---

This repository is published from [letterncms/plugins-public](https://github.com/letterncms/plugins-public/tree/main/webhooks). Open issues and pull requests there.
