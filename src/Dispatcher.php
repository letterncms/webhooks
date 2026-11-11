<?php

declare(strict_types=1);

namespace Cms\Webhooks;

use Illuminate\Contracts\Container\Container;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Cms\Cms;
use Throwable;

/**
 * Queues a delivery per endpoint when something happens, and sends what is due. There is no
 * worker: deliveries are rows, sent after the response of the request that queued them, and one
 * that failed waits in its row until a later request finds it due (see WebhooksPlugin).
 */
final class Dispatcher
{
    /** Seconds to wait after the first, second, … failed attempt. One more failure after the last gives up: six attempts over about fifteen hours. */
    public const array BACKOFF = [60, 300, 1800, 7200, 43200];

    /** How long an attempt may hold a delivery before another request may take it. */
    private const int LEASE = 600;

    private bool $queued = false;

    public function __construct(private readonly Endpoints $endpoints, private readonly Cms $cms, private readonly Container $app) {}

    /** @return list<Delivery> one per endpoint that listens for the event */
    public function queue(string $event, array $data): array
    {
        $out = [];

        try {
            foreach ($this->endpoints->listening($event) as $endpoint) {
                $out[] = $this->queueFor($endpoint['id'], $event, $data);
            }
        } catch (Throwable $e) {
            // A webhook that cannot be queued never breaks the save that caused it.
            report($e);
        }

        return $out;
    }

    public function queueFor(string $endpoint, string $event, array $data): Delivery
    {
        $id = strtolower((string) Str::ulid());
        $payload = json_encode(['id' => $id, 'event' => $event, 'createdAt' => now()->toIso8601String(), 'site' => $this->cms->url(), 'data' => (object) $data], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);

        return $this->store($endpoint, $event, $payload, $id);
    }

    /** The same payload again, as a delivery of its own: its `id` stays, so a receiver can tell it has seen it. */
    public function redeliver(Delivery $delivery): Delivery
    {
        return $this->store($delivery->endpoint, $delivery->event, $delivery->payload);
    }

    private function store(string $endpoint, string $event, string $payload, ?string $id = null): Delivery
    {
        $delivery = new Delivery(['endpoint' => $endpoint, 'event' => $event, 'payload' => $payload, 'status' => Delivery::PENDING, 'attempts' => 0, 'next_attempt_at' => now()]);
        $delivery->id = $id ?? strtolower((string) Str::ulid());
        $delivery->save();
        $this->queued = true;

        return $delivery;
    }

    /** Whether to look for work now: something was queued in this request, or it is time to look for retries (every 20 seconds at most). */
    public function hasWork(): bool
    {
        if ($this->queued) {
            return true;
        }

        try {
            return Cache::add('cms:webhooks:looked', 1, 20) && $this->due()->exists();
        } catch (Throwable) {
            return false;
        }
    }

    /** Send what is due, oldest first, until the time is up. Returns how many were attempted. */
    public function process(int $milliseconds = 6000, int $max = 25): int
    {
        $this->queued = false;
        $until = microtime(true) + $milliseconds / 1000;
        $done = 0;

        // An address that just failed is not asked again in this run: its other deliveries wait their turn instead of each timing out.
        $failing = [];

        foreach ($this->due()->orderBy('next_attempt_at')->orderBy('id')->limit($max * 4)->get() as $delivery) {
            if ($done >= $max || ($done > 0 && microtime(true) >= $until)) {
                break;
            }

            if (isset($failing[$delivery->endpoint])) {
                continue;
            }

            // Taken with one UPDATE: two requests finishing at once never send the same attempt.
            $taken = Delivery::query()->whereKey($delivery->id)->where('status', Delivery::PENDING)->where('attempts', $delivery->attempts)
                ->update(['attempts' => $delivery->attempts + 1, 'next_attempt_at' => now()->addSeconds(self::LEASE), 'updated_at' => now()]);

            if ($taken === 1) {
                $delivery->attempts++;
                $this->attempt($delivery) || $failing[$delivery->endpoint] = true;
                $done++;
            }
        }

        return $done;
    }

    private function due(): \Illuminate\Database\Eloquent\Builder
    {
        return Delivery::query()->where('status', Delivery::PENDING)->where('next_attempt_at', '<=', now());
    }

    /** Whether it arrived. */
    private function attempt(Delivery $delivery): bool
    {
        $endpoint = $this->endpoints->find($delivery->endpoint);
        $secret = $endpoint ? $this->endpoints->secret($endpoint['id']) : '';

        if (! $endpoint || ! $endpoint['enabled'] || $secret === '') {
            $delivery->update(['status' => Delivery::FAILED, 'next_attempt_at' => null, 'error' => $endpoint ? Delivery::OFF : Delivery::DELETED]);

            return false;
        }

        $started = microtime(true);
        $status = null;
        $answer = null;
        $error = null;

        try {
            $result = $this->app->make(Sender::class)->send($endpoint['url'], $delivery->payload, [
                'Content-Type: application/json',
                'X-Cms-Event: '.$delivery->event,
                'X-Cms-Delivery: '.(json_decode($delivery->payload, true)['id'] ?? $delivery->id),
                'X-Cms-Attempt: '.$delivery->attempts,
                'X-Cms-Signature: '.Signature::header($secret, $delivery->payload, now()->getTimestamp()),
            ]);
            $status = $result['status'];
            $answer = mb_strimwidth(mb_scrub($result['body']), 0, 2000);
            $error = $status >= 200 && $status < 300 ? null : Delivery::ANSWERED;
        } catch (Throwable $e) {
            $error = mb_strimwidth(mb_scrub($e->getMessage()), 0, 480);
        }

        $ms = (int) round((microtime(true) - $started) * 1000);
        $wait = self::BACKOFF[$delivery->attempts - 1] ?? null;
        // 410 Gone: the receiver says this address is no more. Asking again would not change that.
        $giveUp = $wait === null || $status === 410;

        $delivery->update([
            'status' => $error === null ? Delivery::DELIVERED : ($giveUp ? Delivery::FAILED : Delivery::PENDING),
            'next_attempt_at' => $error === null || $giveUp ? null : now()->addSeconds($wait),
            'delivered_at' => $error === null ? now() : null,
            'response_status' => $status,
            'response' => $answer,
            'error' => $error,
            'duration_ms' => $ms,
        ]);

        return $error === null;
    }

    /** Forget what an endpoint that is gone was sent, and deliveries older than a month. */
    public function prune(?string $endpoint = null): void
    {
        $endpoint !== null
            ? Delivery::query()->where('endpoint', $endpoint)->delete()
            : Delivery::query()->where('created_at', '<', now()->subDays(30))->delete();
    }
}
