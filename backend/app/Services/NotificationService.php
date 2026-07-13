<?php

namespace App\Services;

use App\Events\NotificationCreated;
use App\Models\Notification;
use App\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;

/**
 * NotificationService — creates and manages in-app notification records.
 *
 * Every notification is scoped to a tenant + user pair.
 * After persistence a NotificationCreated broadcast event is fired on the
 * private channel `private-tenant.{tenantId}.user.{userId}` so the frontend
 * can receive real-time updates via Laravel Echo / Soketi.
 *
 * Supported query filters (search / index):
 *   - event_type  (exact match)
 *   - is_read     (boolean — true/false/1/0)
 *   - date_from   (created_at >= date, Y-m-d)
 *   - date_to     (created_at <= date, Y-m-d)
 *
 * Requirements: 15.1, 15.3, 15.4, 15.6, 15.7, 15.10
 */
class NotificationService
{
    // -------------------------------------------------------------------------
    // Create
    // -------------------------------------------------------------------------

    /**
     * Persist a new Notification record and broadcast it to the recipient.
     *
     * @param  array{
     *     tenant_id:  string,
     *     user_id:    string,
     *     event_type: string,
     *     title:      string,
     *     message:    string,
     *     data?:      array<string, mixed>,
     * }  $payload
     *
     * @throws InvalidArgumentException  when required fields are missing
     */
    public function create(array $payload): Notification
    {
        $this->validatePayload($payload);

        $notification = Notification::withoutGlobalScopes()->create([
            'tenant_id'  => $payload['tenant_id'],
            'user_id'    => $payload['user_id'],
            'event_type' => $payload['event_type'],
            'title'      => $payload['title'],
            'message'    => $payload['message'],
            'data'       => $payload['data'] ?? [],
            'is_read'    => false,
            'read_at'    => null,
            'created_at' => now(),
        ]);

        // Broadcast to the private WebSocket channel for real-time delivery.
        try {
            NotificationCreated::dispatch($notification);
        } catch (\Throwable $e) {
            // Broadcasting failure must never break notification persistence.
            Log::warning('NotificationService: broadcast failed', [
                'notification_id' => $notification->id,
                'error'           => $e->getMessage(),
            ]);
        }

        return $notification;
    }

    // -------------------------------------------------------------------------
    // Mark as read
    // -------------------------------------------------------------------------

    /**
     * Mark a single notification as read.
     *
     * Idempotent — calling this on an already-read notification has no effect.
     *
     * Requirements: 15.7
     */
    public function markAsRead(Notification $notification): Notification
    {
        if ($notification->is_read) {
            return $notification;
        }

        $notification->update([
            'is_read' => true,
            'read_at' => now(),
        ]);

        return $notification->refresh();
    }

    // -------------------------------------------------------------------------
    // Mark all as read
    // -------------------------------------------------------------------------

    /**
     * Mark all unread notifications for a given user (within their tenant) as read.
     *
     * Requirements: 15.7
     *
     * @return int  Number of records updated.
     */
    public function markAllAsRead(User $user): int
    {
        return Notification::withoutGlobalScopes()
            ->where('tenant_id', $user->tenant_id)
            ->where('user_id', $user->id)
            ->where('is_read', false)
            ->update([
                'is_read' => true,
                'read_at' => now(),
            ]);
    }

    // -------------------------------------------------------------------------
    // Unread count
    // -------------------------------------------------------------------------

    /**
     * Return the count of unread notifications for the given user.
     *
     * Requirements: 15.4
     */
    public function getUnreadCount(User $user): int
    {
        return (int) Notification::withoutGlobalScopes()
            ->where('tenant_id', $user->tenant_id)
            ->where('user_id', $user->id)
            ->where('is_read', false)
            ->count();
    }

    // -------------------------------------------------------------------------
    // Paginated list
    // -------------------------------------------------------------------------

    /**
     * Return a paginated list of notifications for the given user, with
     * optional filters.
     *
     * Supported filters:
     *   event_type  — exact match
     *   is_read     — boolean filter (truthy = read, falsy = unread)
     *   date_from   — created_at >= (Y-m-d)
     *   date_to     — created_at <= (Y-m-d)
     *
     * Requirements: 15.4
     *
     * @param  array<string, mixed>  $filters
     */
    public function paginate(User $user, array $filters = [], int $perPage = 20): LengthAwarePaginator
    {
        $query = Notification::withoutGlobalScopes()
            ->where('tenant_id', $user->tenant_id)
            ->where('user_id', $user->id)
            ->orderByDesc('created_at');

        if (! empty($filters['event_type'])) {
            $query->where('event_type', $filters['event_type']);
        }

        if (isset($filters['is_read']) && $filters['is_read'] !== '') {
            $query->where('is_read', (bool) $filters['is_read']);
        }

        if (! empty($filters['date_from'])) {
            $query->whereDate('created_at', '>=', $filters['date_from']);
        }

        if (! empty($filters['date_to'])) {
            $query->whereDate('created_at', '<=', $filters['date_to']);
        }

        return $query->paginate($perPage);
    }

    // -------------------------------------------------------------------------
    // Private helpers
    // -------------------------------------------------------------------------

    /**
     * Validate required fields for create().
     *
     * @param  array<string, mixed>  $payload
     *
     * @throws InvalidArgumentException
     */
    private function validatePayload(array $payload): void
    {
        foreach (['tenant_id', 'user_id', 'event_type', 'title', 'message'] as $field) {
            if (empty($payload[$field])) {
                throw new InvalidArgumentException(
                    "NotificationService::create — required field '{$field}' is missing or empty."
                );
            }
        }
    }
}
