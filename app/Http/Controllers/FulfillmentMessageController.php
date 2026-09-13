<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreFulfillmentMessageRequest;
use App\Models\FulfillmentMessage;
use App\Models\OrderFulfillment;
use App\Services\MessageThread;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class FulfillmentMessageController extends Controller
{
    public function index(Request $request, OrderFulfillment $fulfillment, MessageThread $thread): JsonResponse
    {
        [$fulfillment, $role] = $thread->participate($request->user(), $fulfillment);
        $filters = $request->validate([
            'per_page' => ['nullable', 'integer', 'min:1', 'max:20'],
        ]);

        $messages = FulfillmentMessage::query()
            ->forThread($fulfillment)
            ->with('sender:id,name')
            ->cursorPaginate((int) ($filters['per_page'] ?? 10));

        return response()->json([
            'fulfillment_id' => $fulfillment->id,
            'order_id' => $fulfillment->order_id,
            'viewer_role' => $role,
            'status' => $fulfillment->status,
            'can_send' => $fulfillment->status !== OrderFulfillment::STATUS_CANCELLED,
            'unread_count' => $fulfillment->unreadCountFor($role),
            'data' => collect($messages->items())
                ->map(fn (FulfillmentMessage $message): array => $this->payload($message, $role))
                ->values(),
            'pagination' => [
                'next_cursor' => $messages->nextCursor()?->encode(),
                'has_more' => $messages->hasMorePages(),
                'per_page' => $messages->perPage(),
            ],
        ]);
    }

    public function store(
        StoreFulfillmentMessageRequest $request,
        OrderFulfillment $fulfillment,
        MessageThread $thread,
    ): JsonResponse {
        $message = $thread->send($request->user(), $fulfillment, $request->validated('body'));
        [$fresh, $role] = $thread->participate($request->user(), $fulfillment->fresh());

        return response()->json([
            'message' => 'Pesan terkirim.',
            'unread_count' => $fresh->unreadCountFor($role),
            'data' => $this->payload($message->fresh(['sender:id,name']), $role),
        ], 201);
    }

    public function markRead(Request $request, OrderFulfillment $fulfillment, MessageThread $thread): JsonResponse
    {
        $updated = $thread->markRead($request->user(), $fulfillment);
        [, $role] = $thread->participate($request->user(), $updated);

        return response()->json([
            'message' => 'Percakapan ditandai dibaca.',
            'unread_count' => $updated->unreadCountFor($role),
        ]);
    }

    /**
     * The counterpart is labelled by role, not by account name, so neither side learns more about
     * the other than the order already reveals.
     */
    private function payload(FulfillmentMessage $message, string $viewerRole): array
    {
        return [
            'id' => $message->id,
            'body' => $message->body,
            'sender_role' => $message->sender_role,
            'is_mine' => $message->sender_role === $viewerRole,
            'sender_label' => $message->sender_role === FulfillmentMessage::ROLE_BUYER ? 'Pembeli' : 'Penjual',
            'created_at' => $message->created_at,
        ];
    }
}
