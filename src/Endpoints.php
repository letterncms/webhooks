<?php

declare(strict_types=1);

namespace Cms\Webhooks;

use Illuminate\Support\Str;
use Cms\Abilities\AbilityException;
use Cms\Settings\SettingsRegistry;
use Cms\Support\Files;
use Cms\Support\SafeFetcher;
use Cms\Support\Yaml;

/**
 * The addresses webhooks go to. They are settings: the list in config/webhooks.yaml, each one's
 * signing secret encrypted with the other secrets (storage/secrets.json), never in the file.
 */
final class Endpoints
{
    public const string SETTINGS = 'webhooks';

    public function __construct(private readonly SettingsRegistry $settings) {}

    /** @return array<string, array{id: string, name: string, title: string, url: string, events: list<string>, enabled: bool, createdAt: ?string, updatedAt: ?string}> by id */
    public function all(): array
    {
        $out = [];

        foreach ((array) $this->settings->get(self::SETTINGS, 'endpoints', []) as $row) {
            if (is_array($row) && is_string($row['id'] ?? null) && is_string($row['url'] ?? null)) {
                $out[$row['id']] = [
                    'id' => $row['id'],
                    'name' => (string) ($row['name'] ?? ''),
                    // What it is called where it is listed: its name, or where it goes.
                    'title' => trim((string) ($row['name'] ?? '')) ?: (string) preg_replace('#^https?://#', '', $row['url']),
                    'url' => $row['url'],
                    'events' => array_values(array_filter((array) ($row['events'] ?? []), 'is_string')),
                    'enabled' => (bool) ($row['enabled'] ?? true),
                    'createdAt' => $row['created_at'] ?? null,
                    'updatedAt' => $row['updated_at'] ?? null,
                ];
            }
        }

        return $out;
    }

    public function find(string $id): ?array
    {
        return $this->all()[$id] ?? null;
    }

    public function findOrFail(string $id): array
    {
        return $this->find($id) ?? throw new AbilityException(__('Webhook not found.'), 'not_found');
    }

    public function secret(string $id): string
    {
        return (string) (((array) $this->settings->get(self::SETTINGS, 'secrets', []))[$id] ?? '');
    }

    /** @param  array{url: string, events?: list<string>, name?: string, enabled?: bool}  $input */
    public function create(array $input): array
    {
        $id = strtolower((string) Str::ulid());
        $now = now()->toIso8601String();
        $endpoint = ['id' => $id, ...self::draft($input), 'created_at' => $now, 'updated_at' => $now];

        $this->change(static fn (array $rows, array $secrets) => [[...$rows, $endpoint], [...$secrets, $id => self::newSecret()]]);

        return $this->findOrFail($id);
    }

    /** What the input leaves out stays as it is. */
    public function update(string $id, array $input, bool $rotate = false): array
    {
        $changes = [...$this->changes($id, $input), 'updated_at' => now()->toIso8601String()];

        $this->change(static fn (array $rows, array $secrets) => [
            array_map(static fn (array $row) => $row['id'] === $id ? [...$row, ...$changes] : $row, $rows),
            $rotate ? [...$secrets, $id => self::newSecret()] : $secrets,
        ]);

        return $this->findOrFail($id);
    }

    /**
     * A new webhook as it would be stored, refused where making it would be: what create() writes and what a dry run reports.
     *
     * @return array{name: string, url: string, events: list<string>, enabled: bool}
     */
    public static function draft(array $input): array
    {
        return ['name' => self::name($input['name'] ?? ''), 'url' => self::url((string) ($input['url'] ?? '')), 'events' => self::events($input['events'] ?? []), 'enabled' => (bool) ($input['enabled'] ?? true)];
    }

    /**
     * What an update would change, checked as the update checks it: only what the input names and what differs.
     *
     * @return array<string, mixed>
     */
    public function changes(string $id, array $input): array
    {
        $now = $this->findOrFail($id);
        $changes = [
            ...(isset($input['name']) ? ['name' => self::name($input['name'])] : []),
            ...(isset($input['url']) ? ['url' => self::url($input['url'])] : []),
            ...(isset($input['events']) ? ['events' => self::events($input['events'])] : []),
            ...(isset($input['enabled']) ? ['enabled' => (bool) $input['enabled']] : []),
        ];

        return array_filter($changes, static fn ($value, string $key) => $now[$key] !== $value, ARRAY_FILTER_USE_BOTH);
    }

    public function delete(string $id): void
    {
        $this->findOrFail($id);
        $this->change(static fn (array $rows, array $secrets) => [array_values(array_filter($rows, static fn (array $row) => $row['id'] !== $id)), array_diff_key($secrets, [$id => true])]);
    }

    /** @return list<array> the endpoints that are on and listen for this event */
    public function listening(string $event): array
    {
        return array_values(array_filter($this->all(), static fn (array $e) => $e['enabled'] && self::matches($e['events'], $event)));
    }

    /** `entry.published` matches itself, `entry.*` and `*`. */
    public static function matches(array $events, string $event): bool
    {
        foreach ($events as $pattern) {
            if ($pattern === '*' || $pattern === $event || (str_ends_with($pattern, '.*') && str_starts_with($event, substr($pattern, 0, -1)))) {
                return true;
            }
        }

        return false;
    }

    /**
     * What an address says, before anything is looked up: https, no credentials, and no address
     * that is plainly inside a network. Where its host really leads is checked when a delivery is
     * sent (SafeFetcher), every time.
     */
    private static function url(string $url): string
    {
        $url = trim($url);
        $parts = parse_url($url);
        $scheme = strtolower((string) ($parts['scheme'] ?? ''));
        $host = strtolower(rtrim(trim((string) ($parts['host'] ?? ''), '[]'), '.'));
        $refuse = static fn (string $message) => throw AbilityException::invalid($message, ['url' => $message]);

        if (! is_array($parts) || $host === '' || isset($parts['user']) || isset($parts['pass']) || isset($parts['fragment']) || preg_match('/[\s\x00-\x1f]/', $url)) {
            $refuse(__('Enter a full address, like https://example.com/hook.'));
        }

        if ($scheme !== 'https' && ! (SafeFetcher::$allowPrivate && $scheme === 'http')) {
            $refuse(__('A webhook goes to an https:// address.'));
        }

        $literal = filter_var($host, FILTER_VALIDATE_IP) !== false;

        if (! SafeFetcher::$allowPrivate && ($literal ? ! SafeFetcher::isPublic($host) : (! str_contains($host, '.') || preg_match('/\.(localhost|local|internal|lan|home|corp)$/', $host)))) {
            $refuse(__('That address is not allowed.'));
        }

        if (strlen($url) > 2000) {
            $refuse(__('That address is too long.'));
        }

        return $url;
    }

    private static function name(mixed $name): string
    {
        return mb_substr(trim((string) preg_replace('/\s+/u', ' ', (string) $name)), 0, 120);
    }

    /** @return list<string> */
    private static function events(array $events): array
    {
        $events = array_values(array_unique(array_map('strval', $events)));
        $known = Events::names();

        foreach ($events as $event) {
            $group = str_ends_with($event, '.*') ? substr($event, 0, -1) : null;

            if ($event !== '*' && ! in_array($event, $known, true) && ! ($group !== null && array_any($known, static fn (string $name) => str_starts_with($name, $group)))) {
                throw AbilityException::invalid(__('Unknown event: :event', ['event' => $event]));
            }
        }

        return $events;
    }

    private static function newSecret(): string
    {
        return 'whsec_'.rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
    }

    /**
     * Read, change and write as one step: two people saving at once, or a save and a rotation,
     * never write over each other. What is changed is what the file holds now, not what this
     * request read earlier.
     *
     * @param  callable(list<array>, array<string, string>): array{0: list<array>, 1: array<string, string>}  $change
     */
    private function change(callable $change): void
    {
        Files::ensureDir(storage_path('app'));
        $lock = fopen(storage_path('app/webhooks.lock'), 'c');

        try {
            $lock && flock($lock, LOCK_EX);
            $rows = array_values(array_filter((array) (Yaml::read(site_path('config/'.self::SETTINGS.'.yaml'))['endpoints'] ?? []), 'is_array'));
            $secrets = array_filter((array) $this->settings->get(self::SETTINGS, 'secrets', []), 'is_string');
            [$rows, $secrets] = $change($rows, $secrets);
            $this->settings->save(self::SETTINGS, ['endpoints' => array_values($rows), 'secrets' => $secrets]);
        } finally {
            if ($lock) {
                flock($lock, LOCK_UN);
                fclose($lock);
            }
        }
    }
}
