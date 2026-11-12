<?php

declare(strict_types=1);

namespace Cms\Webhooks\Tests;

use Illuminate\Support\Carbon;
use Cms\Abilities\AbilityContext;
use Cms\Abilities\AbilityException;
use Cms\Tests\TestCase;
use Cms\Webhooks\Delivery;
use Cms\Webhooks\Dispatcher;
use Cms\Webhooks\Endpoints;
use Cms\Webhooks\Sender;
use Cms\Webhooks\Signature;
use Cms\Support\SafeFetcher;

// Loaded by hand: a site's autoloader knows a plugin's src/, not its tests.
require_once __DIR__.'/FakeSender.php';


final class WebhooksTest extends TestCase
{
    private FakeSender $sender;

    protected function plugins(): array
    {
        return ['letterncms/webhooks'];
    }

    protected function setUp(): void
    {
        parent::setUp();

        SafeFetcher::$allowPrivate = false;
        $this->sender = new FakeSender();
        $this->app->instance(Sender::class, $this->sender);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        SafeFetcher::$allowPrivate = false;
        SafeFetcher::$resolver = null;
        parent::tearDown();
    }

    private function run_(string $ability, array $input = [], ?AbilityContext $context = null): mixed
    {
        return $this->cms()->abilities()->execute($ability, $input, $context ?? AbilityContext::system());
    }

    private function webhook(array $events = ['entry.published'], string $url = 'https://hooks.example.com/in'): array
    {
        return $this->run_('webhooks/create', ['url' => $url, 'events' => $events]);
    }

    private function publish(string $title = 'Hello'): \Cms\Content\Entries\Entry
    {
        return $this->cms()->entryService()->create('posts', ['title' => $title, 'data' => ['excerpt' => 'An excerpt.'], 'status' => 'published'], $this->admin());
    }

    public function test_a_published_entry_is_posted_signed_to_the_endpoints_that_listen_for_it(): void
    {
        $hook = $this->webhook();
        $this->webhook(['media.uploaded'], 'https://other.example.com/in');

        $entry = $this->publish();

        $this->assertCount(0, $this->sender->sent, 'Nothing is sent while the request is still answering.');
        $this->assertSame(1, app(Dispatcher::class)->process());
        $this->assertCount(1, $this->sender->sent);

        $sent = $this->sender->sent[0];
        $payload = json_decode($sent['body'], true);
        $this->assertSame('https://hooks.example.com/in', $sent['url']);
        $this->assertSame('entry.published', $payload['event']);
        $this->assertSame($entry->id, $payload['data']['entry']['id']);
        $this->assertSame('http://localhost/blog/hello', $payload['data']['entry']['url']);
        $this->assertArrayNotHasKey('data', $payload['data']['entry'], 'A payload says what changed, not the content.');
        $this->assertSame('entry.published', $sent['headers']['X-Cms-Event']);
        $this->assertSame($payload['id'], $sent['headers']['X-Cms-Delivery']);
        $this->assertSame('application/json', $sent['headers']['Content-Type']);

        $this->assertStringStartsWith('whsec_', $hook['secret']);
        $this->assertTrue(Signature::verify($hook['secret'], $sent['body'], $sent['headers']['X-Cms-Signature'], time()));
        $this->assertFalse(Signature::verify($hook['secret'], $sent['body'].' ', $sent['headers']['X-Cms-Signature'], time()), 'A changed body does not verify.');
        $this->assertFalse(Signature::verify('whsec_other', $sent['body'], $sent['headers']['X-Cms-Signature'], time()));
        $this->assertFalse(Signature::verify($hook['secret'], $sent['body'], $sent['headers']['X-Cms-Signature'], time() + 3600), 'An old delivery replayed does not verify.');

        $log = $this->run_('webhook-deliveries/list', ['webhook' => $hook['id']]);
        $this->assertSame(1, $log['total']);
        $this->assertSame(['delivered', 1, 200], [$log['items'][0]['status'], $log['items'][0]['attempts'], $log['items'][0]['responseStatus']]);
    }

    public function test_each_endpoint_gets_only_the_events_it_chose(): void
    {
        $this->webhook(['entry.deleted']);
        $all = $this->webhook(['entry.*'], 'https://all.example.com/in');

        $entry = $this->publish();
        $this->cms()->entryService()->unpublish($entry->id);
        $this->cms()->entryService()->delete($entry->id);
        app(Dispatcher::class)->process();

        $byUrl = [];
        foreach ($this->sender->sent as $sent) {
            $byUrl[$sent['url']][] = $sent['headers']['X-Cms-Event'];
        }

        $this->assertSame(['entry.deleted'], $byUrl['https://hooks.example.com/in']);
        $this->assertEqualsCanonicalizing(['entry.saved', 'entry.published', 'entry.saved', 'entry.unpublished', 'entry.deleted'], $byUrl['https://all.example.com/in']);

        // Off: nothing more is queued for it.
        $this->run_('webhooks/update', ['id' => $all['id'], 'enabled' => false]);
        $this->publish('Second');
        $this->assertSame(0, Delivery::query()->where('endpoint', $all['id'])->where('status', 'pending')->count());
    }

    public function test_a_failed_delivery_is_tried_again_later_with_longer_waits_and_then_given_up(): void
    {
        $hook = $this->webhook();
        $this->sender->answers = [500, 'Connection timed out', 503, 500, 500, 500];
        Carbon::setTestNow(now()->startOfSecond());
        $this->publish();
        $dispatcher = app(Dispatcher::class);

        $this->assertSame(1, $dispatcher->process());
        $delivery = Delivery::query()->firstOrFail();
        $this->assertSame(['pending', 1, 500, 'answered'], [$delivery->status, $delivery->attempts, $delivery->response_status, $delivery->error]);
        $this->assertSame(0, $dispatcher->process(), 'Not before its wait is over.');

        $waits = [];

        foreach (Dispatcher::BACKOFF as $i => $seconds) {
            $waits[] = (int) round(abs(now()->diffInSeconds($delivery->refresh()->next_attempt_at)));
            Carbon::setTestNow(now()->addSeconds($seconds - 1));
            $this->assertSame(0, $dispatcher->process(), "Attempt {$i} waits {$seconds} seconds.");
            Carbon::setTestNow(now()->addSeconds(2));
            $this->assertSame(1, $dispatcher->process());
            $i === 0 && $this->assertSame([null, 'Connection timed out'], [$delivery->refresh()->response_status, $delivery->error], 'No answer at all is a failure too.');
        }

        $this->assertSame(Dispatcher::BACKOFF, $waits);
        $delivery->refresh();
        $this->assertSame(['failed', 6, null], [$delivery->status, $delivery->attempts, $delivery->next_attempt_at]);
        $this->assertCount(6, $this->sender->sent);
        $this->assertSame('2', $this->sender->sent[1]['headers']['X-Cms-Attempt']);

        Carbon::setTestNow(now()->addDays(3));
        $this->assertSame(0, $dispatcher->process(), 'Given up is given up.');
        $this->assertSame('failed', $this->run_('webhooks/get', ['id' => $hook['id']])['last']['status']);
    }

    public function test_a_retry_that_succeeds_ends_there_and_gone_is_not_asked_again(): void
    {
        $this->webhook();
        $this->sender->answers = ['Could not connect', 204];
        $this->publish();
        $dispatcher = app(Dispatcher::class);
        $dispatcher->process();
        $this->assertSame('Could not connect', Delivery::query()->firstOrFail()->error);

        Carbon::setTestNow(now()->addSeconds(61));
        $dispatcher->process();
        $delivery = Delivery::query()->firstOrFail();
        $this->assertSame(['delivered', 2, 204, null], [$delivery->status, $delivery->attempts, $delivery->response_status, $delivery->error]);

        $this->sender->answers = [410];
        $this->publish('Another');
        $dispatcher->process();
        $this->assertSame('failed', Delivery::query()->latest('id')->firstOrFail()->status);
    }

    public function test_two_requests_never_send_the_same_attempt(): void
    {
        $this->webhook();
        $this->publish();
        $delivery = Delivery::query()->firstOrFail();

        // Another request took it a moment ago.
        Delivery::query()->whereKey($delivery->id)->update(['attempts' => 1, 'next_attempt_at' => now()->addMinutes(10)]);

        $this->assertSame(0, app(Dispatcher::class)->process());
        $this->assertCount(0, $this->sender->sent);
    }

    public function test_a_delivery_can_be_sent_again_with_the_same_payload(): void
    {
        $hook = $this->webhook();
        $this->sender->answers = [500, 200];
        $this->publish();
        app(Dispatcher::class)->process();
        $first = Delivery::query()->firstOrFail();

        $again = $this->run_('webhook-deliveries/create', ['from' => $first->id]);
        $this->assertNotSame($first->id, $again['id']);
        $this->assertSame('pending', $again['status']);

        app(Dispatcher::class)->process();
        $this->assertSame($this->sender->sent[0]['body'], $this->sender->sent[1]['body']);
        $this->assertSame($this->sender->sent[0]['headers']['X-Cms-Delivery'], $this->sender->sent[1]['headers']['X-Cms-Delivery']);
        $this->assertSame('delivered', $this->run_('webhook-deliveries/get', ['id' => $again['id']])['status']);
        $this->assertSame('entry.published', $this->run_('webhook-deliveries/get', ['id' => $again['id']])['payload']['event']);
        $this->assertSame(2, $this->run_('webhook-deliveries/list', ['webhook' => $hook['id']])['total']);
        $this->assertSame(1, $this->run_('webhook-deliveries/list', ['webhook' => $hook['id'], 'status' => 'delivered'])['total']);

        $this->expectException(AbilityException::class);
        $this->run_('webhook-deliveries/create', ['from' => '01zzzzzzzzzzzzzzzzzzzzzzzz']);
    }

    public function test_a_test_event_goes_to_one_webhook_whatever_it_listens_for(): void
    {
        $hook = $this->webhook(['site.built']);
        $this->run_('webhooks/test', ['id' => $hook['id']]);
        app(Dispatcher::class)->process();

        $this->assertSame('webhook.test', $this->sender->sent[0]['headers']['X-Cms-Event']);

        // The Audit Log names the webhook a change was made to by its id: by where it sends, as it has no name.
        $row = $this->run_('audit/list', ['ability' => 'webhooks/test'], AbilityContext::forUser($this->admin(), 'admin'))['items'][0] ?? null;
        $this->assertSame(['Tested Webhook', 'https://hooks.example.com/in'], [$row['label'] ?? null, $row['subject'] ?? null]);
    }

    public function test_media_accounts_and_builds_say_what_changed(): void
    {
        $this->webhook(['*']);
        $hooks = $this->cms()->hooks();
        $user = $this->userWithRole('editor');

        $hooks->dispatch('account.email-changed', $user, 'old@example.com');
        $hooks->dispatch('account.deleted', 'gone-id');
        $hooks->dispatch('site.built', ['builtAt' => '2026-11-11T10:00:00+00:00', 'pages' => ['/' => [], '/about' => []]]);
        $hooks->dispatch('media.deleted', new class
        {
            public function toPublic(): array
            {
                return ['id' => 'm1', 'url' => '/media/a.jpg', 'name' => 'A', 'mime' => 'image/jpeg', 'size' => 10, 'thumb' => '/x', 'caption' => 'not sent'];
            }
        });
        app(Dispatcher::class)->process();

        $data = array_map(static fn (array $sent) => json_decode($sent['body'], true)['data'], $this->sender->sent);
        $this->assertSame(['account' => ['id' => (string) $user->id, 'email' => $user->email, 'name' => $user->name, 'locale' => $user->locale], 'previousEmail' => 'old@example.com'], $data[0]);
        $this->assertSame(['account' => ['id' => 'gone-id']], $data[1]);
        $this->assertSame(['builtAt' => '2026-11-11T10:00:00+00:00', 'pages' => 2], $data[2]);
        $this->assertSame(['media' => ['id' => 'm1', 'url' => '/media/a.jpg', 'name' => 'A', 'mime' => 'image/jpeg', 'size' => 10]], $data[3]);
        $this->assertStringNotContainsString('password', implode('', array_column($this->sender->sent, 'body')));
    }

    public function test_secrets_are_encrypted_and_never_in_the_config_file_or_a_listing(): void
    {
        $hook = $this->webhook();
        $secret = $hook['secret'];

        $this->assertStringNotContainsString($secret, (string) file_get_contents($this->site.'/config/webhooks.yaml'));
        $this->assertStringContainsString('https://hooks.example.com/in', (string) file_get_contents($this->site.'/config/webhooks.yaml'));
        $this->assertStringNotContainsString($secret, (string) file_get_contents(storage_path('secrets.json')), 'Encrypted where it is kept.');
        $this->assertSame($secret, app(Endpoints::class)->secret($hook['id']));

        $this->assertStringNotContainsString($secret, json_encode([$this->run_('webhooks/list'), $this->run_('webhooks/get', ['id' => $hook['id']]), $this->run_('webhooks/update', ['id' => $hook['id'], 'events' => ['entry.saved']])]));

        $rotated = $this->run_('webhooks/renew-key', ['id' => $hook['id']]);
        $this->assertNotSame($secret, $rotated['secret']);
        $this->assertSame(['entry.saved'], $rotated['events'], 'What is left out stays.');

        // The settings abilities would hand the list and its secrets out as one value.
        foreach ([['settings/get', ['namespace' => 'webhooks']], ['settings/update', ['namespace' => 'webhooks', 'values' => ['endpoints' => []]]]] as [$ability, $input]) {
            try {
                $this->run_($ability, $input);
                $this->fail("{$ability} answered for webhooks.");
            } catch (AbilityException $e) {
                $this->assertSame('forbidden', $e->type);
            }
        }

        $this->run_('webhooks/delete', ['id' => $hook['id']]);
        $this->assertSame([], $this->run_('webhooks/list'));
        $this->assertSame('', app(Endpoints::class)->secret($hook['id']));
    }

    public function test_only_those_who_manage_webhooks_may_use_them(): void
    {
        $editor = AbilityContext::forUser($this->userWithRole('editor'));

        foreach (['webhooks/list' => [], 'webhooks/create' => ['url' => 'https://hooks.example.com/in', 'events' => ['*']], 'webhook-deliveries/list' => []] as $ability => $input) {
            try {
                $this->run_($ability, $input, $editor);
                $this->fail("An editor ran {$ability}.");
            } catch (AbilityException) {
                $this->addToAssertionCount(1);
            }
        }

        $this->assertSame([], $this->run_('webhooks/list', [], AbilityContext::forUser($this->admin())));
    }

    public function test_an_address_inside_the_network_is_refused_when_saved(): void
    {
        foreach ([
            'http://hooks.example.com/in' => 'plain http',
            'https://localhost/in' => 'localhost',
            'https://127.0.0.1/in' => 'loopback',
            'https://10.0.0.5/in' => 'private',
            'https://192.168.1.1/in' => 'private',
            'https://169.254.169.254/latest/meta-data/' => 'cloud metadata',
            'https://[::1]/in' => 'IPv6 loopback',
            'https://[::ffff:127.0.0.1]/in' => 'IPv4 in IPv6',
            'https://[fd00::1]/in' => 'IPv6 private',
            'https://0.0.0.0/in' => 'unspecified',
            'https://intranet/in' => 'a bare name',
            'https://db.internal/in' => 'an internal name',
            'https://user:pass@hooks.example.com/in' => 'credentials',
            'ftp://hooks.example.com/in' => 'another scheme',
            'not a url' => 'no address',
        ] as $url => $why) {
            try {
                $this->webhook(['*'], $url);
                $this->fail("Saved {$url} ({$why}).");
            } catch (AbilityException) {
                $this->addToAssertionCount(1);
            }
        }

        $this->assertSame([], $this->run_('webhooks/list'));

        $this->expectException(AbilityException::class);
        $this->run_('webhooks/create', ['url' => 'https://hooks.example.com/in', 'events' => ['entry.exploded']]);
    }

    public function test_where_a_delivery_really_goes_is_checked_when_it_is_sent(): void
    {
        // The real sender, with DNS answered by the test: the kernel's SafeFetcher refuses before it connects.
        $this->app->bind(Sender::class, \Cms\Webhooks\FetcherSender::class);
        $this->webhook();
        $this->webhook(['entry.published'], 'https://hooks.example.com:6379/in');
        SafeFetcher::$resolver = static fn (string $host): array => ['93.184.216.34', '10.0.0.7'];

        $this->publish();
        app(Dispatcher::class)->process();

        $errors = Delivery::query()->orderBy('id')->pluck('error')->all();
        $this->assertSame(['That address is not allowed.', 'That port is not allowed.'], $errors);
        $this->assertSame(['pending', 'pending'], Delivery::query()->pluck('status')->all(), 'Tried again later: DNS may have been wrong for a moment.');
    }

    public function test_private_targets_are_only_for_local_development(): void
    {
        // The kernel's switch (CMS_STORE_ALLOW_PRIVATE, local and testing only), never a setting.
        SafeFetcher::$allowPrivate = true;
        $this->assertSame('http://localhost:9000/in', $this->webhook(['*'], 'http://localhost:9000/in')['url']);
    }

    public function test_an_endpoint_that_just_failed_is_not_asked_again_in_the_same_run(): void
    {
        $down = $this->webhook(['entry.*'], 'https://down.example.com/in');
        $up = $this->webhook(['entry.published'], 'https://up.example.com/in');
        $this->publish();
        $this->publish('Second');

        // Whatever goes to down.example.com fails; the other answers.
        $this->app->instance(Sender::class, $sender = new class implements Sender
        {
            public array $asked = [];

            public function send(string $url, string $body, array $headers): array
            {
                $this->asked[] = $url;

                return ['status' => str_contains($url, 'down') ? 500 : 200, 'body' => ''];
            }
        });

        app(Dispatcher::class)->process();

        $this->assertSame(1, count(array_filter($sender->asked, static fn (string $url) => str_contains($url, 'down'))), 'One timeout, not four.');
        $this->assertSame(2, Delivery::query()->where('endpoint', $up['id'])->where('status', 'delivered')->count());
        $this->assertSame(3, Delivery::query()->where('endpoint', $down['id'])->where('attempts', 0)->count(), 'The rest wait, untouched.');
    }

    public function test_a_failure_reads_in_the_readers_language_and_an_event_by_its_name(): void
    {
        $hook = $this->webhook();
        $this->sender->answers = [500];
        $this->publish();
        app(Dispatcher::class)->process();

        $this->assertSame('answered', Delivery::query()->firstOrFail()->error, 'Kept as a code.');
        $row = $this->run_('webhook-deliveries/list', ['webhook' => $hook['id']])['items'][0];
        $this->assertSame(['Answered 500.', 'Answered 500.', 'Entry Published'], [$row['error'], $row['outcome'], $row['eventLabel']]);

        app(\Cms\Admin\Translations::class)->apply('nl');
        $this->assertSame('Antwoordde 500.', $this->run_('webhook-deliveries/list', ['webhook' => $hook['id']])['items'][0]['error']);
    }

    public function test_two_saves_at_once_do_not_write_over_each_other(): void
    {
        $first = $this->webhook();

        // Another request added a webhook after this one read the list.
        $file = $this->site.'/config/webhooks.yaml';
        $stored = \Cms\Support\Yaml::read($file);
        $stored['endpoints'][] = ['id' => '01aaaaaaaaaaaaaaaaaaaaaaaa', 'url' => 'https://other.example.com/in', 'events' => ['*'], 'enabled' => true];
        \Cms\Support\Yaml::write($file, $stored);

        $this->run_('webhooks/update', ['id' => $first['id'], 'name' => 'Warehouse']);

        $urls = array_column(\Cms\Support\Yaml::read($file)['endpoints'], 'url');
        $this->assertSame(['https://hooks.example.com/in', 'https://other.example.com/in'], $urls);
        $this->assertFileExists(storage_path('app/webhooks.lock'));
    }

    public function test_its_abilities_follow_the_cores_rules_for_names_answers_and_dry_runs(): void
    {
        $mine = array_filter($this->cms()->abilities()->all(), static fn ($ability) => $ability->package === 'letterncms/webhooks');
        $this->assertSame([
            'webhook-deliveries/create', 'webhook-deliveries/get', 'webhook-deliveries/list',
            'webhooks/create', 'webhooks/delete', 'webhooks/editable', 'webhooks/get', 'webhooks/list', 'webhooks/renew-key', 'webhooks/test', 'webhooks/update',
        ], collect(array_keys($mine))->sort()->values()->all());

        foreach ($mine as $name => $ability) {
            $this->assertNotNull($ability->output(), "{$name} says what it answers with");
            // A read is annotated as one; everything else says in full what a run would do.
            $this->assertSame(in_array(explode('/', $name)[1], ['list', 'get', 'editable'], true), $ability->isReadOnly(), $name);
            $this->assertSame(! $ability->isReadOnly(), $ability->dryRun !== null, "{$name} has a dry run of its own");
        }

        $hook = $this->webhook();
        $this->publish();
        $dry = fn (string $ability, array $input) => $this->run_($ability, [...$input, 'dryRun' => true], AbilityContext::forUser($this->admin(), 'admin'));
        $named = ['type' => 'webhook', 'id' => $hook['id'], 'title' => 'hooks.example.com/in', 'url' => 'https://hooks.example.com/in'];

        $report = $dry('webhooks/delete', ['id' => $hook['id']]);
        $this->assertSame('full', $report['checked']);
        $this->assertSame([['action' => 'delete', ...$named, 'removes' => ['signingSecret' => true, 'deliveries' => 1]]], $report['changes']);

        $report = $dry('webhooks/renew-key', ['id' => $hook['id']]);
        $this->assertSame(['full', [['action' => 'update', ...$named, 'removes' => ['signingSecret' => true], 'waiting' => 1]]], [$report['checked'], $report['changes']]);

        $this->assertSame([['action' => 'update', ...$named, 'changes' => ['name' => 'Warehouse', 'enabled' => false]]], $dry('webhooks/update', ['id' => $hook['id'], 'name' => 'Warehouse', 'enabled' => false, 'url' => 'https://hooks.example.com/in'])['changes']);
        $this->assertSame([], $dry('webhooks/update', ['id' => $hook['id'], 'enabled' => true])['changes']);
        $this->assertSame([['action' => 'create', 'type' => 'webhook', 'name' => '', 'url' => 'https://new.example.com/in', 'events' => ['site.built'], 'enabled' => true]], $dry('webhooks/create', ['url' => 'https://new.example.com/in', 'events' => ['site.built']])['changes']);
        $this->assertSame([['action' => 'create', 'type' => 'webhook-delivery', 'webhook' => $hook['id'], 'event' => 'webhook.test']], $dry('webhooks/test', ['id' => $hook['id']])['changes']);
        $first = Delivery::query()->firstOrFail();
        $this->assertSame([['action' => 'create', 'type' => 'webhook-delivery', 'webhook' => $hook['id'], 'event' => 'entry.published', 'from' => $first->id]], $dry('webhook-deliveries/create', ['from' => $first->id])['changes']);

        // Nothing was written, and the secret is the one it was.
        $this->assertSame($hook['secret'], app(Endpoints::class)->secret($hook['id']));
        $this->assertSame([1, 1], [count($this->run_('webhooks/list')), Delivery::query()->count()]);

        // A dry run refuses what the real run would.
        foreach ([['webhooks/delete', ['id' => '01zzzzzzzzzzzzzzzzzzzzzzzz']], ['webhooks/renew-key', ['id' => '01zzzzzzzzzzzzzzzzzzzzzzzz']], ['webhooks/create', ['url' => 'https://127.0.0.1/in']], ['webhooks/update', ['id' => $hook['id'], 'url' => 'http://hooks.example.com/in']]] as [$ability, $input]) {
            try {
                $dry($ability, $input);
                $this->fail("A dry run of {$ability} let it through.");
            } catch (AbilityException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function test_its_screens_are_views_the_admin_can_draw(): void
    {
        $admin = $this->admin();
        $context = AbilityContext::forUser($admin, 'admin');
        $hook = $this->run_('webhooks/create', ['url' => 'https://hooks.example.com/orders', 'name' => 'Warehouse {1}'], $context);
        $views = $this->cms()->views();

        $list = $views->resolve('webhooks', $admin);
        $this->assertSame('ui/page', $list['root']['type']);
        $this->assertSame('webhooks/create', $list['root']['props']['actions'][0]['ability']);
        $this->assertSame('Warehouse {1}', $this->run_('webhooks/list')[0]['title']);

        // Whoever made it sees the secret on its page once; nobody else, and never again.
        $this->assertStringNotContainsString($hook['secret'], json_encode($views->resolve('webhook', $this->admin(), ['id' => $hook['id']])));
        $this->run_('webhooks/renew-key', ['id' => $hook['id']], $context);
        $secret = app(Endpoints::class)->secret($hook['id']);
        $one = $views->resolve('webhook', $admin, ['id' => $hook['id']]);
        $this->assertSame(['ui/notice', $secret], [$one['root']['children'][0]['type'], $one['root']['children'][0]['props']['text']]);
        $again = $views->resolve('webhook', $admin, ['id' => $hook['id']]);
        $this->assertStringNotContainsString($secret, json_encode($again));
        $this->assertSame('ui/tabs', $again['root']['children'][0]['type']);

        // Made by an agent or the command line, it is in the answer and on no page.
        $other = $this->webhook(['*'], 'https://agent.example.com/in');
        $this->assertStringNotContainsString($other['secret'], json_encode($views->resolve('webhook', $admin, ['id' => $other['id']])));

        $this->assertSame('Warehouse {{1}}', $again['root']['props']['title'], 'A name is words, not a template.');
        $this->assertSame(['webhooks/test'], array_column($again['root']['props']['actions'], 'ability'), 'The header keeps one action.');
        $this->assertSame('hooks.example.com/orders', $this->run_('webhooks/update', ['id' => $hook['id'], 'name' => ''])['title']);
        $this->assertSame('ui/page', $views->resolve('webhook', $admin, ['id' => '01zzzzzzzzzzzzzzzzzzzzzzzz'])['root']['type']);
        $this->assertFalse($views->opens('/webhooks', $this->userWithRole('editor')));

        // The settings form has a checkbox per event: what it sends is set, the rest stays.
        $this->run_('webhooks/update', ['id' => $hook['id'], 'events' => ['entry.*']]);
        $this->run_('webhooks/update', ['id' => $hook['id'], 'event_entry__saved' => false, 'event_account__email_changed' => true]);
        $this->assertSame(['entry.published', 'entry.unpublished', 'entry.deleted', 'account.email-changed'], $this->run_('webhooks/get', ['id' => $hook['id']])['events']);
        $form = $this->run_('webhooks/editable', ['id' => $hook['id']], $context);
        $this->assertSame([false, true, true, false], [$form['event_entry__saved'], $form['event_entry__published'], $form['event_account__email_changed'], $form['event_site__built']]);

        // The home-screen widget says nothing while all is well.
        $widget = static fn () => $views->resolve('widget.webhooks-deliveries', $admin)['root'];
        $this->assertArrayNotHasKey('children', $widget());
        $this->sender->answers = [410];
        $this->publish();
        app(Dispatcher::class)->process();
        $this->assertSame('2 deliveries failed', $widget()['children'][0]['props']['title']);
    }

    public function test_every_change_has_a_past_tense_label_for_the_audit_log(): void
    {
        $past = $this->cms()->hooks()->filter('audit.past', []);
        $changes = array_filter($this->cms()->abilities()->all(), static fn ($ability) => $ability->package === 'letterncms/webhooks' && ! $ability->isReadOnly());

        $this->assertNotSame([], $changes);

        foreach ($changes as $name => $ability) {
            $this->assertIsString($past[$name] ?? null, "{$name} has no past-tense label: add it to the audit.past filter.");
        }
    }
}
