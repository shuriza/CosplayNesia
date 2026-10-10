<?php

namespace App\Http\Controllers\Backoffice;

use App\Http\Controllers\Controller;
use Illuminate\Contracts\Pagination\CursorPaginator;
use Illuminate\Support\Str;

/**
 * Shared helpers for the read-only backoffice console.
 *
 * Every list uses cursor pagination so pages stay bounded regardless of table size, matching the
 * rest of the app. PII is masked in list payloads by default; full values only appear where an
 * operator genuinely needs them.
 */
abstract class BackofficeController extends Controller
{
    /**
     * @param  callable(mixed): array<string, mixed>  $map
     * @return array{data: array<int, array<string, mixed>>, pagination: array{next_cursor: ?string, has_more: bool}}
     */
    protected function paged(CursorPaginator $paginator, callable $map): array
    {
        return [
            'data' => collect($paginator->items())->map($map)->values()->all(),
            'pagination' => [
                'next_cursor' => $paginator->nextCursor()?->encode(),
                'has_more' => $paginator->hasMorePages(),
            ],
        ];
    }

    /**
     * Mask an email for list views: keep the first character and the domain only.
     */
    protected function maskEmail(?string $email): ?string
    {
        if ($email === null || ! str_contains($email, '@')) {
            return $email;
        }

        [$local, $domain] = explode('@', $email, 2);

        return Str::substr($local, 0, 1).'***@'.$domain;
    }

    protected function maskPhone(?string $phone): ?string
    {
        if ($phone === null || mb_strlen($phone) < 4) {
            return $phone;
        }

        return mb_substr($phone, 0, 4).str_repeat('*', mb_strlen($phone) - 4);
    }
}
