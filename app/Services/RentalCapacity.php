<?php

namespace App\Services;

use App\Models\Product;
use App\Models\RentalReservation;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

class RentalCapacity
{
    public function summary(Product $product, string $start, string $end): array
    {
        $events = $this->events($product, $start, $end);
        $reserved = $blocked = $peakReserved = $peakBlocked = $peak = 0;
        $days = [];
        $last = CarbonImmutable::parse($end, config('app.timezone'));

        for ($day = CarbonImmutable::parse($start, config('app.timezone')); $day->lte($last); $day = $day->addDay()) {
            $date = $day->toDateString();
            $reserved += $events[$date]['reserved'] ?? 0;
            $blocked += $events[$date]['blocked'] ?? 0;
            $peakReserved = max($peakReserved, $reserved);
            $peakBlocked = max($peakBlocked, $blocked);
            $peak = max($peak, $reserved + $blocked);
            $days[] = [
                'date' => $date,
                'reserved_quantity' => $reserved,
                'blocked_quantity' => $blocked,
                'available_quantity' => max(0, $product->stock - $reserved - $blocked),
            ];
        }

        return [
            'reserved_quantity' => $peakReserved,
            'blocked_quantity' => $peakBlocked,
            // Separate peaks may occur on different days: never add them together.
            'unavailable_quantity' => $peak,
            'available_quantity' => max(0, $product->stock - $peak),
            'days' => $days,
        ];
    }

    public function peak(Product $product, string $start, ?string $end = null): int
    {
        $quantity = $peak = 0;
        foreach ($this->events($product, $start, $end) as $delta) {
            $quantity += ($delta['reserved'] ?? 0) + ($delta['blocked'] ?? 0);
            $peak = max($peak, $quantity);
        }

        return $peak;
    }

    private function events(Product $product, string $start, ?string $end): array
    {
        $reservations = DB::table('rental_reservations')
            ->where('product_id', $product->id)
            ->where('status', RentalReservation::STATUS_RESERVED)
            ->where('end_date', '>=', $start)
            ->when($end !== null, fn ($query) => $query->where('start_date', '<=', $end))
            ->selectRaw("start_date, end_date, SUM(quantity) as quantity, 'reserved' as kind")
            ->groupBy('start_date', 'end_date');
        $blocks = DB::table('rental_blocks')
            ->where('product_id', $product->id)
            ->whereNull('cancelled_at')
            ->where('end_date', '>=', $start)
            ->when($end !== null, fn ($query) => $query->where('start_date', '<=', $end))
            ->selectRaw("start_date, end_date, SUM(quantity) as quantity, 'blocked' as kind")
            ->groupBy('start_date', 'end_date');

        $events = [];
        foreach ($reservations->unionAll($blocks)->get() as $allocation) {
            $from = max($start, $allocation->start_date);
            $through = $end === null ? $allocation->end_date : min($end, $allocation->end_date);
            $afterEnd = CarbonImmutable::parse($through, config('app.timezone'))->addDay()->toDateString();
            $kind = $allocation->kind;
            $events[$from][$kind] = ($events[$from][$kind] ?? 0) + (int) $allocation->quantity;
            $events[$afterEnd][$kind] = ($events[$afterEnd][$kind] ?? 0) - (int) $allocation->quantity;
        }
        ksort($events);

        return $events;
    }
}
