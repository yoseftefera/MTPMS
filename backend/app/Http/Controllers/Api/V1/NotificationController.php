<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\V1\NotificationResource;
use App\Models\Notification;
use App\Services\NotificationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * @OA\Tag(name="Notifications", description="In-app notifications: list, mark as read, unread count.")
 *
 * NotificationController — manages the authenticated user's in-app notifications.
 *
 * All endpoints are scoped to the authenticated user; a user can only read or
 * modify their own notifications.  Tenant isolation is enforced inside
 * NotificationService (queries always include tenant_id + user_id conditions).
 *
 * Endpoints:
 *   GET   /api/v1/notifications                        — paginated list with filters
 *   GET   /api/v1/notifications/unread-count           — count of unread notifications
 *   PATCH /api/v1/notifications/{notification}/read    — mark one notification as read
 *   PATCH /api/v1/notifications/read-all               — mark all unread as read
 *
 * Query parameters accepted by index():
 *   event_type  — filter by exact event_type value
 *   is_read     — 1 / true → read only;  0 / false → unread only
 *   date_from   — created_at >= (Y-m-d)
 *   date_to     — created_at <= (Y-m-d)
 *   per_page    — results per page (default 20, max 100)
 *
 * Requirements: 15.4, 15.7
 */
class NotificationController extends Controller
{
    public function __construct(private readonly NotificationService $service) {}

    // -------------------------------------------------------------------------
    // GET /api/v1/notifications
    // -------------------------------------------------------------------------

    /**
     * @OA\Get(path="/notifications", operationId="listNotifications", tags={"Notifications"}, summary="List notifications",
     *     description="Returns paginated in-app notifications for the authenticated user.",
     *     security={{"bearerAuth":{}}},
     *     @OA\Parameter(ref="#/components/parameters/XTenantID"), @OA\Parameter(ref="#/components/parameters/XRequestID"),
     *     @OA\Parameter(name="event_type", in="query", required=false, @OA\Schema(type="string"), description="Filter by exact event type, e.g. PurchaseRequestSubmitted."),
     *     @OA\Parameter(name="is_read", in="query", required=false, @OA\Schema(type="integer", enum={0,1}), description="1=read only, 0=unread only."),
     *     @OA\Parameter(name="date_from", in="query", required=false, @OA\Schema(type="string", format="date")),
     *     @OA\Parameter(name="date_to", in="query", required=false, @OA\Schema(type="string", format="date")),
     *     @OA\Parameter(name="per_page", in="query", required=false, @OA\Schema(type="integer", default=20)),
     *     @OA\Response(response=200, description="Notifications list.", @OA\JsonContent(@OA\Property(property="success", type="boolean", example=true), @OA\Property(property="data", type="array", @OA\Items(ref="#/components/schemas/NotificationResource")), @OA\Property(property="message", type="string"), @OA\Property(property="errors", nullable=true, example=null), @OA\Property(property="meta", ref="#/components/schemas/PaginationMeta"))),
     *     @OA\Response(response=401, description="Unauthenticated.", @OA\JsonContent(ref="#/components/schemas/ErrorResponse"))
     * )
     *
     * Return a paginated list of the authenticated user's notifications.
     *
     * Requirements: 15.4, 15.7
     */
    public function index(Request $request): JsonResponse
    {
        $user    = Auth::guard('api')->user();
        $perPage = min((int) $request->query('per_page', 20), 100);

        // Build filter map, keeping only non-null / non-empty values.
        $filters = [];

        if ($request->filled('event_type')) {
            $filters['event_type'] = $request->query('event_type');
        }

        // Accept 1/0/true/false for is_read — only apply when explicitly provided.
        if ($request->has('is_read') && $request->query('is_read') !== null && $request->query('is_read') !== '') {
            $filters['is_read'] = filter_var($request->query('is_read'), FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) ?? false;
        }

        if ($request->filled('date_from')) {
            $filters['date_from'] = $request->query('date_from');
        }

        if ($request->filled('date_to')) {
            $filters['date_to'] = $request->query('date_to');
        }

        $paginator = $this->service->paginate($user, $filters, $perPage);

        return $this->paginated(
            paginator: $paginator,
            data:      NotificationResource::collection($paginator->items()),
            message:   'Notifications retrieved successfully.',
        );
    }

    // -------------------------------------------------------------------------
    // GET /api/v1/notifications/unread-count
    // -------------------------------------------------------------------------

    /**
     * @OA\Get(path="/notifications/unread-count", operationId="notificationUnreadCount", tags={"Notifications"}, summary="Get unread notification count",
     *     security={{"bearerAuth":{}}},
     *     @OA\Parameter(ref="#/components/parameters/XTenantID"), @OA\Parameter(ref="#/components/parameters/XRequestID"),
     *     @OA\Response(response=200, description="Unread count.", @OA\JsonContent(@OA\Property(property="success", type="boolean", example=true), @OA\Property(property="data", type="object", @OA\Property(property="unread_count", type="integer", example=5)), @OA\Property(property="message", type="string"), @OA\Property(property="errors", nullable=true, example=null), @OA\Property(property="meta", nullable=true, example=null))),
     *     @OA\Response(response=401, description="Unauthenticated.", @OA\JsonContent(ref="#/components/schemas/ErrorResponse"))
     * )
     *
     * Return the count of unread notifications for the authenticated user.
     *
     * Requirements: 15.4
     */
    public function unreadCount(): JsonResponse
    {
        $user  = Auth::guard('api')->user();
        $count = $this->service->getUnreadCount($user);

        return $this->success(
            data:    ['unread_count' => $count],
            message: 'Unread count retrieved successfully.',
        );
    }

    // -------------------------------------------------------------------------
    // PATCH /api/v1/notifications/{notification}/read
    // -------------------------------------------------------------------------

    /**
     * @OA\Patch(path="/notifications/{notification}/read", operationId="markNotificationRead", tags={"Notifications"}, summary="Mark notification as read",
     *     security={{"bearerAuth":{}}},
     *     @OA\Parameter(ref="#/components/parameters/XTenantID"), @OA\Parameter(ref="#/components/parameters/XRequestID"),
     *     @OA\Parameter(name="notification", in="path", required=true, @OA\Schema(type="string", format="uuid")),
     *     @OA\Response(response=200, description="Notification marked as read.", @OA\JsonContent(@OA\Property(property="success", type="boolean", example=true), @OA\Property(property="data", ref="#/components/schemas/NotificationResource"), @OA\Property(property="message", type="string"), @OA\Property(property="errors", nullable=true, example=null), @OA\Property(property="meta", nullable=true, example=null))),
     *     @OA\Response(response=403, description="Not your notification.", @OA\JsonContent(ref="#/components/schemas/ErrorResponse")),
     *     @OA\Response(response=404, description="Not found.", @OA\JsonContent(ref="#/components/schemas/ErrorResponse"))
     * )
     *
     * Mark a single notification as read.
     *
     * The notification is resolved via route model binding.  HTTP 403 is returned
     * when the authenticated user does not own the notification.
     *
     * Requirements: 15.7
     */
    public function markAsRead(Notification $notification): JsonResponse
    {
        $user = Auth::guard('api')->user();

        // Ownership check: a user may only read their own notifications.
        if ((string) $notification->user_id !== (string) $user->id
            || (string) $notification->tenant_id !== (string) $user->tenant_id
        ) {
            return $this->error('You are not authorised to modify this notification.', 403);
        }

        $notification = $this->service->markAsRead($notification);

        return $this->success(
            data:    new NotificationResource($notification),
            message: 'Notification marked as read.',
        );
    }

    // -------------------------------------------------------------------------
    // PATCH /api/v1/notifications/read-all
    // -------------------------------------------------------------------------

    /**
     * @OA\Patch(path="/notifications/read-all", operationId="markAllNotificationsRead", tags={"Notifications"}, summary="Mark all notifications as read",
     *     security={{"bearerAuth":{}}},
     *     @OA\Parameter(ref="#/components/parameters/XTenantID"), @OA\Parameter(ref="#/components/parameters/XRequestID"),
     *     @OA\Response(response=200, description="All notifications marked as read.", @OA\JsonContent(@OA\Property(property="success", type="boolean", example=true), @OA\Property(property="data", type="object", @OA\Property(property="updated_count", type="integer", example=12)), @OA\Property(property="message", type="string"), @OA\Property(property="errors", nullable=true, example=null), @OA\Property(property="meta", nullable=true, example=null))),
     *     @OA\Response(response=401, description="Unauthenticated.", @OA\JsonContent(ref="#/components/schemas/ErrorResponse"))
     * )
     *
     * Mark all of the authenticated user's unread notifications as read.
     *
     * Returns the number of notifications that were updated.
     *
     * Requirements: 15.7
     */
    public function markAllAsRead(): JsonResponse
    {
        $user    = Auth::guard('api')->user();
        $updated = $this->service->markAllAsRead($user);

        return $this->success(
            data:    ['updated_count' => $updated],
            message: 'All notifications marked as read.',
        );
    }
}
