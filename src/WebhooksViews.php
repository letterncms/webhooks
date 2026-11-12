<?php

declare(strict_types=1);

namespace Cms\Webhooks;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Cms\Admin\Views;
use Cms\Cms;
use Cms\Users\User;
use Throwable;

/** The places of Webhooks, as data (see Cms\Admin\Views): the list, and one webhook with its deliveries. */
final class WebhooksViews
{
    public function __construct(private readonly Cms $cms, private readonly Endpoints $endpoints) {}

    public function register(): void
    {
        $views = $this->cms->views();
        $views->register('webhooks', '/webhooks', fn () => $this->list(), permission: 'webhooks.manage');
        // Private: right after a webhook is made, or its secret renewed, the page shows that secret once.
        $views->register('webhook', '/webhooks/:id', fn (User $user, array $params) => $this->one((string) ($params['id'] ?? ''), $user), permission: 'webhooks.manage', private: true);
    }

    private function list(): array
    {
        // The one way to make a webhook, once: in the header, also while the list is empty. Its events are chosen on its own page, where there is room for them.
        $new = ['label' => __('New Webhook'), 'name' => 'new-webhook', 'icon' => 'plus', 'variant' => 'primary', 'ability' => 'webhooks/create',
            'input' => ['url' => '{values.url}', 'name' => '{values.name}'], 'then' => 'open', 'to' => '/webhooks/{result.id}?tab=settings',
            'form' => ['title' => __('New Webhook'), 'confirm' => __('Create Webhook'), 'fields' => [
                ['handle' => 'url', 'type' => 'url', 'label' => __('Address'), 'placeholder' => 'https://example.com/hook', 'required' => true, 'autofocus' => true],
                ['handle' => 'name', 'type' => 'text', 'label' => __('Name'), 'placeholder' => __('Optional')],
            ]]];

        return ['type' => 'ui/page', 'props' => ['title' => __('Webhooks'), 'icon' => 'send', 'actions' => [$new]], 'children' => [
            ['type' => 'ui/records', 'props' => [
                'id' => 'webhooks', 'label' => __('Webhooks'), 'key' => 'id', 'search' => false,
                'source' => ['ability' => 'webhooks/list'],
                'columns' => [
                    ['key' => 'title', 'label' => __('Webhook'), 'format' => 'strong'],
                    ['key' => 'enabled', 'label' => __('Send Deliveries'), 'width' => '150px', 'format' => 'status', 'labels' => ['true' => __('On'), 'false' => __('Off')], 'tones' => ['true' => 'success', 'false' => 'neutral']],
                    ['key' => 'last.status', 'label' => __('Last Delivery'), 'width' => '140px', 'format' => 'badge', 'labels' => self::statuses(), 'tones' => self::TONES, 'empty' => __('None Yet')],
                ],
                'open' => ['label' => __('Open'), 'to' => '/webhooks/{row.id}'],
                'empty' => ['icon' => 'send', 'title' => __('No webhooks yet')],
            ]],
        ]];
    }

    private function one(string $id, User $user): array
    {
        $endpoint = $this->endpoints->find($id);

        if ($endpoint === null) {
            return Views::notFound('send', ['to' => '/webhooks', 'label' => __('Webhooks')]);
        }

        $input = ['id' => $id];
        $groups = [];

        // A row per event, under the kind of thing it is about: each a full-width target, on a phone too.
        foreach (Events::groups() as $group => $section) {
            foreach (Events::in($group) as $event => $label) {
                $groups[] = ['handle' => Events::field($event), 'type' => 'toggle', 'display' => 'checkbox', 'label' => $label, 'section' => $section];
            }
        }

        return ['type' => 'ui/page', 'props' => ['title' => self::literal($endpoint['title']), 'icon' => 'send', 'back' => ['to' => '/webhooks', 'label' => __('Webhooks')], 'actions' => [
            ['label' => __('Send Test'), 'icon' => 'send', 'ability' => 'webhooks/test', 'input' => $input, 'then' => 'refresh', 'success' => __('Test sent')],
        ]], 'children' => [
            ...$this->secretOnce($id, $user),
            ['type' => 'ui/tabs', 'children' => [
                ['type' => 'ui/tab', 'props' => ['id' => 'deliveries', 'label' => __('Deliveries')], 'children' => [
                    ['type' => 'ui/records', 'props' => [
                        'id' => 'webhook-deliveries', 'label' => __('Deliveries'), 'search' => false,
                        'source' => ['ability' => 'webhook-deliveries/list', 'input' => ['webhook' => $id]],
                        'filters' => [['param' => 'status', 'label' => __('Show'), 'all' => __('All'), 'options' => array_map(static fn ($value, $label) => ['value' => $value, 'label' => $label], array_keys(self::statuses()), self::statuses())]],
                        'paged' => ['perPage' => 50],
                        'columns' => [
                            ['key' => 'eventLabel', 'label' => __('Event'), 'format' => 'strong'],
                            ['key' => 'status', 'label' => __('Status'), 'width' => '120px', 'format' => 'badge', 'labels' => self::statuses(), 'tones' => self::TONES],
                            ['key' => 'outcome', 'label' => __('Response'), 'format' => 'muted'],
                            ['key' => 'createdAt', 'label' => __('Sent'), 'width' => '140px', 'align' => 'end', 'format' => 'ago'],
                        ],
                        'actions' => [
                            ['label' => __('Send Again'), 'icon' => 'refresh', 'ability' => 'webhook-deliveries/create', 'input' => ['from' => '{row.id}'], 'then' => 'refresh', 'success' => __('Sent again')],
                        ],
                        'empty' => ['icon' => 'send', 'title' => __('Nothing sent yet')],
                    ]],
                ]],
                ['type' => 'ui/tab', 'props' => ['id' => 'settings', 'label' => __('Settings')], 'children' => [
                    ['type' => 'ui/form', 'props' => ['source' => ['ability' => 'webhooks/editable', 'input' => $input], 'valuesAt' => '', 'save' => ['ability' => 'webhooks/update', 'input' => $input, 'as' => '*'], 'fields' => [
                        ['handle' => 'enabled', 'type' => 'toggle', 'label' => __('Send Deliveries')],
                        ['handle' => 'url', 'type' => 'url', 'label' => __('Address'), 'placeholder' => 'https://example.com/hook', 'required' => true],
                        ['handle' => 'name', 'type' => 'text', 'label' => __('Name'), 'placeholder' => __('Optional')],
                        ...$groups,
                    ]]],
                    ['type' => 'ui/actions', 'props' => ['items' => [
                        ['label' => __('New Signing Secret'), 'icon' => 'key', 'ability' => 'webhooks/renew-key', 'input' => $input, 'then' => 'refresh',
                            'confirm' => ['title' => __('Make a new signing secret?'), 'text' => __('Deliveries signed with the old one stop verifying.'), 'confirm' => __('Make New Secret'), 'danger' => true]],
                        ['label' => __('Delete'), 'icon' => 'trash', 'variant' => 'danger', 'ability' => 'webhooks/delete', 'input' => $input, 'then' => 'open', 'to' => '/webhooks',
                            'confirm' => ['title' => __('Delete this webhook?'), 'text' => __('Its delivery log goes with it.'), 'confirm' => __('Delete'), 'danger' => true]],
                    ]]],
                ]],
            ]],
        ]];
    }

    /**
     * The signing secret, to whoever just made or renewed it, the first time they look and never
     * again: taken out of the cache as it is read. Nothing else ever puts a secret on a page.
     *
     * @return list<array>
     */
    private function secretOnce(string $id, User $user): array
    {
        try {
            $kept = Cache::pull('cms:webhooks:reveal:'.$id);
            [$for, $secret] = is_string($kept) ? explode('|', Crypt::decryptString($kept), 2) + [1 => ''] : ['', ''];
        } catch (Throwable) {
            return [];
        }

        if ($secret === '' || $for !== (string) $user->id) {
            return [];
        }

        return [['type' => 'ui/notice', 'props' => ['tone' => 'info', 'title' => __('Signing secret, shown this once'), 'text' => $secret, 'action' => ['label' => __('Copy'), 'icon' => 'copy', 'copy' => $secret, 'success' => __('Signing secret copied')]]]];
    }

    /** Words from a record, written as they are: a `{` in someone's name is no template. */
    private static function literal(string $text): string
    {
        return (string) preg_replace('/\{([^{}]*)\}/', '{{$1}}', $text);
    }

    /** The home-screen widget: nothing while all is well, a line when deliveries failed or are being tried again. */
    public static function widget(): array
    {
        $week = Delivery::query()->where('created_at', '>=', now()->subDays(7));
        $failed = (clone $week)->where('status', Delivery::FAILED)->count();
        $retrying = Delivery::query()->where('status', Delivery::PENDING)->where('attempts', '>', 0)->count();
        $review = ['label' => __('Review'), 'to' => '/webhooks'];

        return ['type' => 'ui/stack', 'children' => [
            ...($failed > 0 ? [['type' => 'ui/notice', 'props' => ['tone' => 'warning', 'title' => trans_choice(__(':count delivery failed|:count deliveries failed'), $failed, ['count' => $failed]), 'action' => $review]]] : []),
            ...($retrying > 0 ? [['type' => 'ui/notice', 'props' => ['tone' => 'info', 'title' => trans_choice(__(':count delivery is being tried again|:count deliveries are being tried again'), $retrying, ['count' => $retrying]), 'action' => $review]]] : []),
        ]];
    }

    private const array TONES = ['pending' => 'info', 'delivered' => 'success', 'failed' => 'danger'];

    /** @return array<string, string> */
    private static function statuses(): array
    {
        return ['pending' => __('Waiting'), 'delivered' => __('Delivered'), 'failed' => __('Failed')];
    }
}
