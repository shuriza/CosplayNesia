<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class UserNotification extends Model
{
    use HasFactory;

    public const UPDATED_AT = null;

    public const TYPE_ORDER_PLACED = 'order.placed';

    public const TYPE_FULFILLMENT_ACCEPTED = 'fulfillment.accepted';

    public const TYPE_FULFILLMENT_READY = 'fulfillment.ready';

    public const TYPE_FULFILLMENT_COMPLETED = 'fulfillment.completed';

    public const TYPE_FULFILLMENT_CANCELLED = 'fulfillment.cancelled';

    public const TYPE_RENTAL_CANCELLED = 'rental.cancelled';

    public const TYPE_REVIEW_RECEIVED = 'review.received';

    public const TYPE_REVIEW_REPLIED = 'review.replied';

    public const TYPE_MESSAGE_RECEIVED = 'message.received';

    protected $table = 'user_notifications';

    protected $fillable = [
        'recipient_id', 'actor_id', 'order_id', 'fulfillment_id', 'product_review_id',
        'type', 'payload', 'event_key', 'read_at', 'created_at',
    ];

    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'read_at' => 'datetime',
        ];
    }

    public function recipient(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recipient_id');
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function fulfillment(): BelongsTo
    {
        return $this->belongsTo(OrderFulfillment::class, 'fulfillment_id');
    }

    public function scopeForRecipient(Builder $query, User|int $recipient): Builder
    {
        $recipientId = $recipient instanceof User ? $recipient->id : $recipient;

        return $query->where('recipient_id', $recipientId)
            ->latest('created_at')
            ->latest('id');
    }

    public function scopeUnread(Builder $query): Builder
    {
        return $query->whereNull('read_at');
    }

    public function isUnread(): bool
    {
        return $this->read_at === null;
    }
}
