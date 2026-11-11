<?php

declare(strict_types=1);

namespace Cms\Webhooks;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

/**
 * One event on its way to one endpoint, and what became of it.
 *
 * @property string $id
 * @property string $endpoint
 * @property string $event
 * @property string $payload
 * @property string $status
 * @property int $attempts
 */
class Delivery extends Model
{
    use HasUlids;

    public const string PENDING = 'pending';

    public const string DELIVERED = 'delivered';

    public const string FAILED = 'failed';

    /** Why a delivery failed, when the reason is ours to word: kept as a code, said in the reader's language. */
    public const string ANSWERED = 'answered';

    public const string OFF = 'off';

    public const string DELETED = 'deleted';

    protected $table = 'webhook_deliveries';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['attempts' => 'integer', 'response_status' => 'integer', 'duration_ms' => 'integer', 'next_attempt_at' => 'datetime', 'delivered_at' => 'datetime'];
    }

    public function toPublic(bool $payload = false): array
    {
        $error = match ($this->error) {
            null => null,
            self::ANSWERED => __('Answered :status.', ['status' => $this->response_status]),
            self::OFF => __('The webhook is off.'),
            self::DELETED => __('The webhook was deleted.'),
            // What the connection said: the address was refused, nothing answered.
            default => $this->error,
        };

        return [
            'id' => $this->id,
            'endpoint' => $this->endpoint,
            'event' => $this->event,
            'eventLabel' => Events::labels()[$this->event] ?? ($this->event === Events::TEST ? __('Test') : $this->event),
            'status' => $this->status,
            'attempts' => $this->attempts,
            'responseStatus' => $this->response_status,
            'error' => $error,
            // One line for a list: how it went, in words.
            'outcome' => $this->status === self::DELIVERED ? 'HTTP '.$this->response_status : ($error ?? ($this->attempts > 0 ? __('Sending') : __('Not sent yet'))),
            'durationMs' => $this->duration_ms,
            'createdAt' => $this->created_at?->toIso8601String(),
            'deliveredAt' => $this->delivered_at?->toIso8601String(),
            'nextAttemptAt' => $this->status === self::PENDING ? $this->next_attempt_at?->toIso8601String() : null,
            ...($payload ? ['payload' => json_decode($this->payload, true), 'response' => $this->response] : []),
        ];
    }
}
