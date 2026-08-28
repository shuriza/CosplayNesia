<?php

namespace App\Http\Controllers;

use App\Models\OrderActivity;

abstract class Controller
{
    protected function timelinePayload(OrderActivity $activity, ?int $viewerId, bool $sellerView = false): array
    {
        return [
            'event_type' => $activity->event_type,
            'event_label' => $this->timelineEventLabel($activity->event_type),
            'actor_label' => $this->timelineActorLabel($activity, $viewerId, $sellerView),
            'from_status' => $activity->from_status,
            'to_status' => $activity->to_status,
            'metadata' => $activity->metadata,
            'occurred_at' => $activity->occurred_at,
        ];
    }

    protected function timelineEventLabel(string $eventType): string
    {
        return [
            'checkout.created' => 'Checkout dibuat',
            'fulfillment.received' => 'Pesanan masuk',
            'fulfillment.accepted' => 'Diterima penjual',
            'fulfillment.ready' => 'Siap diserahkan',
            'fulfillment.completed' => 'Selesai',
            'fulfillment.cancelled' => 'Dibatalkan',
            'rental.reserved' => 'Reservasi dibuat',
            'rental.cancelled' => 'Reservasi dibatalkan',
            'rental.completed' => 'Reservasi selesai',
            'order.imported' => 'Riwayat diimpor',
            'fulfillment.imported' => 'Riwayat diimpor',
            'rental.imported' => 'Riwayat diimpor',
        ][$eventType] ?? $eventType;
    }

    protected function timelineActorLabel(OrderActivity $activity, ?int $viewerId, bool $sellerView = false): string
    {
        if ($viewerId !== null && $activity->actor_id === $viewerId) {
            return 'Anda';
        }

        return match ($activity->actor_role) {
            OrderActivity::ROLE_BUYER => $sellerView ? 'Pembeli' : 'Pembeli',
            OrderActivity::ROLE_SELLER => $sellerView ? 'Penjual' : 'Penjual',
            default => 'Sistem',
        };
    }
}
