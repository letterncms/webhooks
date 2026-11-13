<?php

declare(strict_types=1);

namespace Cms\Webhooks;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Cms\Abilities\Ability;
use Cms\Abilities\AbilityContext;
use Cms\Abilities\AbilityException;
use Cms\Packages\Plugin;
use Cms\Support\Schema\Shape;
use Throwable;

/**
 * Outbound webhooks: what happens on the site, POSTed as signed JSON to the addresses that asked
 * for it. See Dispatcher for how they are sent without a worker; where they may go is SafeFetcher's to say.
 */
final class WebhooksPlugin extends Plugin
{
    public function register(): void
    {
        $this->app->singleton(Endpoints::class);
        $this->app->singleton(Dispatcher::class);
        $this->app->bindIf(Sender::class, FetcherSender::class);
    }

    public function boot(): void
    {
        $cms = $this->cms();
        $cms->permissions()->register('webhooks.manage', 'Manage Webhooks', 'Site', roles: ['admin']);

        // No page of its own: the endpoints are edited on the Webhooks screen, and kept where settings are.
        $cms->settingsRegistry()->register(Endpoints::SETTINGS, fields: [
            ['handle' => 'endpoints', 'type' => 'json', 'label' => 'Webhooks', 'default' => []],
            ['handle' => 'secrets', 'type' => 'json', 'label' => 'Signing Secrets', 'secret' => true, 'default' => []],
        ], icon: 'send', permission: 'webhooks.manage', page: false);

        $cms->hooks()->addFilter('ability.input', static function (array $input, $ability) {
            // The registry's own abilities would hand out or overwrite the list and its secrets as one value: they go through webhooks/* instead.
            if (str_starts_with($ability->name, 'settings/') && ($input['namespace'] ?? null) === Endpoints::SETTINGS) {
                throw AbilityException::forbidden();
            }

            // The admin's form has a checkbox per event: those it sends are set on or off, the rest stay as they are.
            if ($ability->name === 'webhooks/update' && is_string($input['id'] ?? null) && array_any(array_keys($input), static fn ($key) => str_starts_with((string) $key, 'event_'))) {
                $current = app(Endpoints::class)->find($input['id'])['events'] ?? [];
                $input['events'] = array_values(array_filter(Events::names(), static fn (string $event) => (bool) ($input[Events::field($event)] ?? Endpoints::matches($current, $event))));
            }

            return $input;
        });

        Events::listen($cms, $this->app->make(Dispatcher::class));
        $this->abilities();

        // What the Audit Log calls each change, once it has happened.
        $cms->hooks()->addFilter('audit.past', static fn (array $past): array => $past + [
            'webhooks/create' => __('Created Webhook'),
            'webhooks/update' => __('Updated Webhook'),
            'webhooks/renew-key' => __('Renewed Webhook Signing Secret'),
            'webhooks/delete' => __('Deleted Webhook'),
            'webhooks/test' => __('Tested Webhook'),
            'webhook-deliveries/create' => __('Redelivered Webhook'),
        ]);
        // A webhook is changed by its id: the log names it as the list does, by its name, else where it sends.
        $cms->hooks()->addFilter('audit.subject', static function (?string $subject, string $ability, array $input): ?string {
            if ($subject !== null || ! str_starts_with($ability, 'webhooks/') || ! is_string($input['id'] ?? null)) {
                return $subject;
            }

            $hook = app(Endpoints::class)->find($input['id']);

            return $hook === null ? null : ($hook['name'] !== '' ? $hook['name'] : $hook['url']);
        });

        $cms->admin()->nav('webhooks', ['path' => '/webhooks', 'icon' => 'send', 'section' => 'system', 'order' => 85, 'pinned' => false, 'permission' => 'webhooks.manage']);
        $cms->admin()->widget('webhooks-deliveries', ['label' => 'Webhooks', 'description' => __('Says when deliveries failed or are being tried again.'), 'empty' => 'No failed deliveries', 'icon' => 'send', 'column' => 'side', 'order' => 85, 'permission' => 'webhooks.manage',
            'action' => ['label' => 'All Webhooks', 'to' => '/webhooks'], 'view' => WebhooksViews::widget(...)]);
        $this->app->make(WebhooksViews::class)->register();

        $cms->context()->register('cms://webhooks/events', 'Webhook Events', 'The events a webhook can listen for (webhooks/create, webhooks/update). A delivery is a POST of {id, event, createdAt, site, data}, signed in X-Cms-Signature as t=<unix time>,v1=<HMAC-SHA256 of "<t>.<body>" with the webhook’s secret>.', static fn () => Events::names());

        if ($this->app->runningInConsole()) {
            $this->commands([Console\DeliverCommand::class]);
        }

        // Send after the response is out: what was queued in this request, and retries that have come due.
        $this->app->terminating(function (): void {
            if ($this->app->runningUnitTests()) {
                return;
            }

            // Where the response cannot be finished first, a visitor would wait for every receiver: nothing is
            // sent in that request. A later one that can, or `webhooks:deliver` from cron, sends it.
            $finish = array_find(['fastcgi_finish_request', 'litespeed_finish_request'], static fn (string $function) => function_exists($function));
            $console = $this->app->runningInConsole();

            if (! $console && $finish === null) {
                return;
            }

            try {
                $dispatcher = $this->app->make(Dispatcher::class);

                if ($dispatcher->hasWork()) {
                    $console || $finish();
                    $dispatcher->process();
                }

                if (random_int(1, 200) === 1) {
                    $dispatcher->prune();
                }
            } catch (Throwable $e) {
                report($e);
            }
        });
    }

    /** Kept for five minutes, encrypted, for whoever made it: their next look at the webhook shows it, once. */
    public static function reveal(string $id, string $secret, AbilityContext $context): void
    {
        if ($context->transport === 'admin' && $context->user !== null) {
            Cache::put('cms:webhooks:reveal:'.$id, Crypt::encryptString($context->user->id.'|'.$secret), 300);
        }
    }

    private function abilities(): void
    {
        $a = $this->cms()->abilities();
        $endpoints = fn (): Endpoints => $this->app->make(Endpoints::class);
        $dispatcher = fn (): Dispatcher => $this->app->make(Dispatcher::class);
        $id = ['type' => 'string', 'pattern' => '^[0-9a-z]{26}$'];
        $one = ['type' => 'object', 'properties' => ['id' => $id], 'required' => ['id']];
        $events = ['type' => 'array', 'items' => ['type' => 'string', 'maxLength' => 64], 'maxItems' => 40, 'description' => 'Event names (cms://webhooks/events), a group like "entry.*", or "*" for all.'];
        $name = ['type' => 'string', 'maxLength' => 120, 'description' => 'What it is for, in a few words. Shown in its place of the address.'];
        $last = static fn (string $endpoint) => Delivery::query()->where('endpoint', $endpoint)->latest()->orderByDesc('id')->first()?->toPublic();

        // What they answer with.
        $delivery = [
            'id' => Shape::STRING, 'endpoint' => Shape::STRING, 'event' => Shape::STRING, 'eventLabel' => Shape::STRING, 'status' => Shape::enum(Delivery::PENDING, Delivery::DELIVERED, Delivery::FAILED),
            'attempts' => Shape::INTEGER, 'responseStatus' => Shape::INTEGER_OR_NULL, 'error' => Shape::STRING_OR_NULL, 'outcome' => Shape::STRING_OR_NULL, 'durationMs' => Shape::INTEGER_OR_NULL,
            'createdAt' => Shape::STRING_OR_NULL, 'deliveredAt' => Shape::STRING_OR_NULL, 'nextAttemptAt' => Shape::STRING_OR_NULL,
        ];
        $webhook = ['id' => Shape::STRING, 'name' => Shape::STRING, 'title' => Shape::STRING, 'url' => Shape::STRING, 'events' => [Shape::STRING], 'enabled' => Shape::BOOLEAN, 'createdAt' => Shape::STRING_OR_NULL, 'updatedAt' => Shape::STRING_OR_NULL];
        $listed = [...$webhook, 'last' => Shape::nullable($delivery)];
        $withSecret = [...$webhook, 'secret' => Shape::STRING];
        $boxes = array_fill_keys(array_map(Events::field(...), Events::names()), Shape::BOOLEAN);
        // What a dry run says of a webhook: enough to know which one.
        $named = static fn (array $e) => ['type' => 'webhook', 'id' => $e['id'], 'title' => $e['title'], 'url' => $e['url']];

        $a->register('webhooks/list', [
            'label' => 'List Webhooks',
            'description' => 'The addresses that are told what happens on the site, each with its events, whether it is on, and its last delivery. Secrets are never listed.',
            'permission' => 'webhooks.manage',
            'annotations' => ['readOnly' => true],
            'output' => Shape::of([$listed]),
            'handler' => static fn () => array_values(array_map(static fn (array $e) => [...$e, 'last' => $last($e['id'])], $endpoints()->all())),
        ]);

        $a->register('webhooks/get', [
            'label' => 'Get Webhook',
            'description' => 'One webhook: its address, events, whether it is on, and its last delivery. Not its secret.',
            'permission' => 'webhooks.manage',
            'annotations' => ['readOnly' => true],
            'input' => $one,
            'output' => Shape::of($listed),
            'handler' => static fn (array $in) => [...$endpoints()->findOrFail($in['id']), 'last' => $last($in['id'])],
        ]);

        $a->register('webhooks/editable', [
            'label' => 'Webhook as Its Form Edits It',
            'description' => 'A webhook as the admin’s form edits it: a yes or no per event.',
            'permission' => 'webhooks.manage',
            'annotations' => ['readOnly' => true],
            'expose' => Ability::ADMIN_ONLY,
            'input' => $one,
            'output' => Shape::of(['name' => Shape::STRING, 'url' => Shape::STRING, 'enabled' => Shape::BOOLEAN, ...$boxes]),
            'handler' => static function (array $in) use ($endpoints) {
                $endpoint = $endpoints()->findOrFail($in['id']);
                $events = [];

                foreach (Events::names() as $event) {
                    $events[Events::field($event)] = Endpoints::matches($endpoint['events'], $event);
                }

                return ['name' => $endpoint['name'], 'url' => $endpoint['url'], 'enabled' => $endpoint['enabled'], ...$events];
            },
        ]);

        $a->register('webhooks/create', [
            'label' => 'Create Webhook',
            'description' => 'Send events to an https address on the public internet. Returns the webhook with its signing secret, which is shown this once: the receiver verifies X-Cms-Signature with it. Without events it only gets tests until some are chosen.',
            'permission' => 'webhooks.manage',
            'input' => ['type' => 'object', 'properties' => ['url' => ['type' => 'string', 'maxLength' => 2000], 'name' => $name, 'events' => $events, 'enabled' => ['type' => 'boolean', 'default' => true]], 'required' => ['url']],
            'output' => Shape::of($withSecret),
            'dryRun' => static fn (array $in): array => [['action' => 'create', 'type' => 'webhook', ...Endpoints::draft($in)]],
            'handler' => static function (array $in, AbilityContext $context) use ($endpoints) {
                $endpoint = $endpoints()->create($in);
                self::reveal($endpoint['id'], $secret = $endpoints()->secret($endpoint['id']), $context);

                return [...$endpoint, 'secret' => $secret];
            },
        ]);

        $a->register('webhooks/update', [
            'label' => 'Update Webhook',
            'description' => 'Change a webhook’s address, name, events or whether it is on; what is left out stays. Its signing secret stays too: webhooks/renew-key makes a new one.',
            'permission' => 'webhooks.manage',
            'input' => ['type' => 'object', 'properties' => [
                'id' => $id, 'url' => ['type' => 'string', 'maxLength' => 2000], 'name' => $name, 'events' => $events, 'enabled' => ['type' => 'boolean'],
                // The admin's form: a yes or no per event, each set on its own.
                ...array_fill_keys(array_keys($boxes), ['type' => 'boolean']),
            ], 'required' => ['id']],
            'output' => Shape::of($webhook),
            // Nothing to change is no change: the list is empty.
            'dryRun' => static fn (array $in): array => ($changes = $endpoints()->changes($in['id'], $in)) === [] ? [] : [['action' => 'update', ...$named($endpoints()->findOrFail($in['id'])), 'changes' => $changes]],
            'handler' => static fn (array $in) => $endpoints()->update($in['id'], $in),
        ]);

        $a->register('webhooks/renew-key', [
            'label' => 'Renew Webhook Signing Secret',
            'description' => 'Make a new signing secret for a webhook and return it, this once. The old one is gone at once: deliveries are signed with the new one from the next attempt, so the receiver has to be given it.',
            'permission' => 'webhooks.manage',
            'annotations' => ['confirm' => true],
            'input' => $one,
            'output' => Shape::of($withSecret),
            'dryRun' => static fn (array $in): array => [['action' => 'update', ...$named($endpoints()->findOrFail($in['id'])), 'removes' => ['signingSecret' => true], 'waiting' => Delivery::query()->where('endpoint', $in['id'])->where('status', Delivery::PENDING)->count()]],
            'handler' => static function (array $in, AbilityContext $context) use ($endpoints) {
                $endpoint = $endpoints()->update($in['id'], [], rotate: true);
                self::reveal($endpoint['id'], $secret = $endpoints()->secret($endpoint['id']), $context);

                return [...$endpoint, 'secret' => $secret];
            },
        ]);

        $a->register('webhooks/delete', [
            'label' => 'Delete Webhook',
            'description' => 'Stop sending to an address and forget its signing secret and its delivery log.',
            'permission' => 'webhooks.manage',
            'annotations' => ['destructive' => true],
            'input' => $one,
            'output' => Shape::of(['deleted' => Shape::STRING]),
            'dryRun' => static fn (array $in): array => [['action' => 'delete', ...$named($endpoints()->findOrFail($in['id'])), 'removes' => ['signingSecret' => true, 'deliveries' => Delivery::query()->where('endpoint', $in['id'])->count()]]],
            'handler' => static function (array $in) use ($endpoints, $dispatcher) {
                $endpoints()->delete($in['id']);
                $dispatcher()->prune($in['id']);

                return ['deleted' => $in['id']];
            },
        ]);

        $a->register('webhooks/test', [
            'label' => 'Test Webhook',
            'description' => 'Send a webhook.test event to one webhook, on or off the list of events it listens for. Its outcome is in webhook-deliveries/list.',
            'permission' => 'webhooks.manage',
            'input' => $one,
            'output' => Shape::of($delivery),
            'dryRun' => static fn (array $in): array => [['action' => 'create', 'type' => 'webhook-delivery', 'webhook' => $endpoints()->findOrFail($in['id'])['id'], 'event' => Events::TEST]],
            'handler' => static function (array $in) use ($endpoints, $dispatcher) {
                $endpoint = $endpoints()->findOrFail($in['id']);

                return $dispatcher()->queueFor($endpoint['id'], Events::TEST, ['message' => 'A test from '.cms_brand().'.'])->toPublic();
            },
        ]);

        $a->register('webhook-deliveries/list', [
            'label' => 'List Webhook Deliveries',
            'description' => 'The delivery log, newest first: event, status (pending, delivered or failed), attempts, the receiver’s answer and when the next attempt is due. Of one webhook, or all.',
            'permission' => 'webhooks.manage',
            'annotations' => ['readOnly' => true],
            'input' => ['type' => 'object', 'properties' => [
                'webhook' => $id,
                'status' => ['type' => 'string', 'enum' => ['all', 'pending', 'delivered', 'failed'], 'default' => 'all'],
                'page' => ['type' => 'integer', 'minimum' => 1, 'default' => 1],
                'perPage' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 100, 'default' => 50],
            ]],
            'output' => Shape::page($delivery),
            'handler' => static function (array $in) {
                $q = Delivery::query()->latest()->orderByDesc('id');
                isset($in['webhook']) && $q->where('endpoint', $in['webhook']);
                $in['status'] !== 'all' && $q->where('status', $in['status']);
                $page = $q->paginate($in['perPage'], ['*'], 'page', $in['page']);

                return ['items' => array_map(static fn (Delivery $d) => $d->toPublic(), $page->items()), 'total' => $page->total(), 'page' => $page->currentPage(), 'pages' => $page->lastPage()];
            },
        ]);

        $a->register('webhook-deliveries/get', [
            'label' => 'Get Webhook Delivery',
            'description' => 'One delivery, with the payload that was sent and the start of the receiver’s answer.',
            'permission' => 'webhooks.manage',
            'annotations' => ['readOnly' => true],
            'input' => $one,
            'output' => Shape::of([...$delivery, 'response' => Shape::STRING_OR_NULL, 'payload' => [
                'id' => Shape::STRING, 'event' => Shape::STRING, 'createdAt' => Shape::STRING, 'site' => Shape::STRING,
                'data' => Shape::open('What changed, by the kind of event: `entry`, `media` or `account` with what identifies it, `by` for who did it, and for site.built `builtAt` and `pages`.'),
            ]]),
            'handler' => static fn (array $in) => (Delivery::query()->find($in['id']) ?? throw new AbilityException(__('Delivery not found.'), 'not_found'))->toPublic(payload: true),
        ]);

        // Sending again makes a delivery from one that was made before.
        $resend = static function (array $in) use ($endpoints): Delivery {
            $delivery = Delivery::query()->find($in['from']) ?? throw new AbilityException(__('Delivery not found.'), 'not_found');
            $endpoints()->findOrFail($delivery->endpoint);

            return $delivery;
        };

        $a->register('webhook-deliveries/create', [
            'label' => 'Redeliver Webhook',
            'description' => 'Send a delivery again: a new delivery made from an earlier one (`from`), with the same payload and the same payload id, so the receiver can tell. Sent right after this answer where the server can, by the next `webhooks:deliver` otherwise.',
            'permission' => 'webhooks.manage',
            'input' => ['type' => 'object', 'properties' => ['from' => [...$id, 'description' => 'The id of the delivery to send again.']], 'required' => ['from']],
            'output' => Shape::of($delivery),
            'dryRun' => static fn (array $in): array => [['action' => 'create', 'type' => 'webhook-delivery', 'webhook' => ($from = $resend($in))->endpoint, 'event' => $from->event, 'from' => $from->id]],
            'handler' => static fn (array $in) => $dispatcher()->redeliver($resend($in))->toPublic(),
        ]);
    }
}
