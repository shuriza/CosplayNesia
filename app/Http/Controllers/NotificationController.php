<?php

namespace App\Http\Controllers;

use App\Models\UserNotification;
use App\Services\NotificationRecorder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class NotificationController extends Controller
{
    public function index(Request $request, NotificationRecorder $recorder): JsonResponse
    {
        $filters = $request->validate([
            'per_page' => ['nullable', 'integer', 'min:1', 'max:20'],
            'unread' => ['nullable', 'boolean'],
        ]);

        $notifications = UserNotification::query()
            ->forRecipient($request->user())
            ->when($filters['unread'] ?? false, fn ($query) => $query->unread())
            ->cursorPaginate((int) ($filters['per_page'] ?? 8));

        return response()->json([
            'unread_count' => $recorder->unreadCount($request->user()),
            'data' => collect($notifications->items())
                ->map(fn (UserNotification $notification): array => $this->payload($notification))
                ->values(),
            'pagination' => [
                'next_cursor' => $notifications->nextCursor()?->encode(),
                'has_more' => $notifications->hasMorePages(),
                'per_page' => $notifications->perPage(),
            ],
        ]);
    }

    public function markRead(Request $request, int $notification, NotificationRecorder $recorder): JsonResponse
    {
        $updated = $recorder->markRead($request->user(), $notification);

        return response()->json([
            'message' => 'Notifikasi ditandai dibaca.',
            'unread_count' => $recorder->unreadCount($request->user()),
            'notification' => $this->payload($updated),
        ]);
    }

    public function markAllRead(Request $request, NotificationRecorder $recorder): JsonResponse
    {
        $marked = $recorder->markAllRead($request->user());

        return response()->json([
            'message' => $marked === 0 ? 'Tidak ada notifikasi baru.' : 'Semua notifikasi ditandai dibaca.',
            'marked' => $marked,
            'unread_count' => 0,
        ]);
    }

    private function payload(UserNotification $notification): array
    {
        return [
            'id' => $notification->id,
            'type' => $notification->type,
            'title' => $this->title($notification),
            'order_id' => $notification->order_id,
            'fulfillment_id' => $notification->fulfillment_id,
            'payload' => $notification->payload,
            'is_unread' => $notification->isUnread(),
            'read_at' => $notification->read_at,
            'created_at' => $notification->created_at,
        ];
    }

    private function title(UserNotification $notification): string
    {
        return [
            UserNotification::TYPE_ORDER_PLACED => 'Pesanan baru masuk',
            UserNotification::TYPE_FULFILLMENT_ACCEPTED => 'Pesanan diterima penjual',
            UserNotification::TYPE_FULFILLMENT_READY => 'Pesanan siap diserahkan',
            UserNotification::TYPE_FULFILLMENT_COMPLETED => 'Pesanan selesai',
            UserNotification::TYPE_FULFILLMENT_CANCELLED => 'Pesanan dibatalkan',
            UserNotification::TYPE_RENTAL_CANCELLED => 'Reservasi sewa dibatalkan',
            UserNotification::TYPE_REVIEW_RECEIVED => 'Ulasan baru untuk produkmu',
            UserNotification::TYPE_REVIEW_REPLIED => 'Penjual membalas ulasanmu',
            UserNotification::TYPE_MESSAGE_RECEIVED => 'Pesan baru pada pesanan',
        ][$notification->type] ?? $notification->type;
    }
}
