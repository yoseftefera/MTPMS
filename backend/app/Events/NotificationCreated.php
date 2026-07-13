<?php

namespace App\Events;

use App\Models\Notification;
use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * NotificationCreated — broadcast to the recipient's private WebSocket channel
 * whenever a new in-app notification is persisted.
 *
 * Channel: private-tenant.{tenantId}.user.{userId}
 *
 * Requirements: 15.1, 15.3, 15.6, 15.10
 */
class NotificationCreated implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    /**
     * @param  Notification  $notification  The freshly-created notification record.
     */
    public function __construct(
        public readonly Notification $notification,
    ) {}

    /**
     * The private channel scoped to the recipient user within the tenant.
     *
     * @return Channel|array<Channel>
     */
    public function broadcastOn(): Channel|array
    {
        return new PrivateChannel(
            'tenant.' . $this->notification->tenant_id
            . '.user.' . $this->notification->user_id
        );
    }

    /**
     * The event name used on the client side (Laravel Echo).
     */
    public function broadcastAs(): string
    {
        return 'notification.created';
    }

    /**
     * Data sent to the client.
     *
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        return [
            'id'         => $this->notification->id,
            'event_type' => $this->notification->event_type,
            'title'      => $this->notification->title,
            'message'    => $this->notification->message,
            'data'       => $this->notification->data,
            'is_read'    => false,
            'created_at' => $this->notification->created_at?->toIso8601String(),
        ];
    }
}
