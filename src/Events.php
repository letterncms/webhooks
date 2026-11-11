<?php

declare(strict_types=1);

namespace Cms\Webhooks;

use Cms\Content\Entries\Entry;
use Cms\Cms;

/**
 * The events a webhook can listen for, and what each one says. A payload names what changed and
 * where to find it; a receiver that needs more asks the API.
 */
final class Events
{
    /** @return array<string, string> Hook name => the words for it in the admin. The hook's name is the event's name. */
    public static function labels(): array
    {
        return [
            'entry.saved' => __('Entry Saved'),
            'entry.published' => __('Entry Published'),
            'entry.unpublished' => __('Entry Unpublished'),
            'entry.deleted' => __('Entry Deleted'),
            'media.uploaded' => __('Media Uploaded'),
            'media.updated' => __('Media Updated'),
            'media.deleted' => __('Media Deleted'),
            'account.created' => __('Account Created'),
            'account.verified' => __('Account Verified'),
            'account.updated' => __('Account Updated'),
            'account.email-changed' => __('Account Email Changed'),
            'account.password-changed' => __('Account Password Changed'),
            'account.granted' => __('Account Access Granted'),
            'account.revoked' => __('Account Access Revoked'),
            'account.deleted' => __('Account Deleted'),
            'site.built' => __('Site Published'),
        ];
    }

    public const string TEST = 'webhook.test';

    /** @return array<string, string> The kinds of event, as the admin groups them: prefix => its name. */
    public static function groups(): array
    {
        return ['entry' => __('Entry'), 'media' => __('Media'), 'account' => __('Account'), 'site' => __('Site')];
    }

    /** The handle of an event's checkbox in the admin's form: `entry.published` is `event_entry__published`. */
    public static function field(string $event): string
    {
        return 'event_'.str_replace(['.', '-'], ['__', '_'], $event);
    }

    /** @return array<string, string> the events of one group, name => label */
    public static function in(string $group): array
    {
        return array_filter(self::labels(), static fn (string $name) => str_starts_with($name, $group.'.'), ARRAY_FILTER_USE_KEY);
    }

    /** @return list<string> */
    public static function names(): array
    {
        return ['entry.saved', 'entry.published', 'entry.unpublished', 'entry.deleted', 'media.uploaded', 'media.updated', 'media.deleted', 'account.created', 'account.verified', 'account.updated', 'account.email-changed', 'account.password-changed', 'account.granted', 'account.revoked', 'account.deleted', 'site.built'];
    }

    /** Listen for every event, queueing a delivery for each endpoint that asked for it. */
    public static function listen(Cms $cms, Dispatcher $dispatcher): void
    {
        foreach (self::names() as $event) {
            // After everything else that listens: what is sent is how things ended up.
            $cms->hooks()->on($event, static function (mixed ...$args) use ($event, $dispatcher, $cms): void {
                $dispatcher->queue($event, self::data($event, $args, $cms));
            }, 90);
        }
    }

    /** @param  list<mixed>  $args  what the hook was dispatched with */
    public static function data(string $event, array $args, Cms $cms): array
    {
        $subject = $args[0] ?? null;

        return match (true) {
            str_starts_with($event, 'entry.') && $subject instanceof Entry => [
                'entry' => self::entry($subject, $cms),
                ...(is_string($args[2] ?? null) ? ['action' => $args[2]] : []),
                ...self::by($args[1] ?? null),
            ],
            str_starts_with($event, 'media.') && is_object($subject) => [
                'media' => array_intersect_key(method_exists($subject, 'toPublic') ? $subject->toPublic() : [], array_flip(['id', 'url', 'name', 'filename', 'mime', 'kind', 'size', 'width', 'height', 'alt', 'folder'])),
                ...self::by($args[1] ?? null),
            ],
            $event === 'account.deleted' => ['account' => ['id' => (string) $subject]],
            str_starts_with($event, 'account.') && is_object($subject) => [
                'account' => ['id' => (string) $subject->id, 'email' => (string) $subject->email, 'name' => (string) $subject->name, 'locale' => $subject->locale ?? null],
                ...($event === 'account.email-changed' ? ['previousEmail' => (string) ($args[1] ?? '')] : []),
                ...(in_array($event, ['account.granted', 'account.revoked'], true) ? ['access' => (string) ($args[1] ?? ''), 'source' => (string) ($args[2] ?? '')] : []),
            ],
            $event === 'site.built' => ['builtAt' => is_array($subject) ? ($subject['builtAt'] ?? null) : null, 'pages' => is_array($subject) && is_array($subject['pages'] ?? null) ? count($subject['pages']) : null],
            default => [],
        };
    }

    private static function entry(Entry $entry, Cms $cms): array
    {
        return [
            'id' => $entry->id,
            'collection' => $entry->collection,
            'locale' => $entry->locale,
            'slug' => $entry->slug,
            'title' => $entry->title,
            'status' => $entry->status,
            'uri' => $entry->uri,
            'url' => $entry->uri !== null ? $cms->url($entry->uri) : null,
            'publishedAt' => $entry->publishedAt?->toIso8601String(),
            'updatedAt' => $entry->updatedAt?->toIso8601String(),
        ];
    }

    /** Who did it, when a person did. */
    private static function by(mixed $user): array
    {
        return is_object($user) && isset($user->id) ? ['by' => ['id' => (string) $user->id, 'name' => (string) ($user->name ?? '')]] : [];
    }
}
