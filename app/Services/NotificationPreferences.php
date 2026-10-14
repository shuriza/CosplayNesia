<?php

namespace App\Services;

use App\Models\NotificationPreference;
use App\Models\User;
use App\Notifications\NotificationCatalog;
use Illuminate\Support\Facades\DB;

/**
 * Resolves whether a user accepts a channel for a category, and applies opt-outs.
 *
 * Mandatory categories (account security) are never looked up: no preference row can disable them,
 * so a compromised or careless account cannot silence the warning that it is being taken over. The
 * resolver returns true before it touches the table, which also means a future bug in the write
 * path cannot reopen the hole.
 */
class NotificationPreferences
{
    public function allows(User $user, string $channel, string $category): bool
    {
        if (NotificationCatalog::isMandatoryCategory($category)) {
            return true;
        }

        $preference = NotificationPreference::query()
            ->where('user_id', $user->id)
            ->where('channel', $channel)
            ->where('category', $category)
            ->first();

        // No row means "default enabled": a category added later must not arrive switched off.
        return $preference === null || $preference->enabled;
    }

    /**
     * Current state for every mailable category on a channel, defaults included, so the UI can
     * render a complete switch list without knowing the catalog's rules.
     *
     * @return array<int, array{category:string, channel:string, enabled:bool, mandatory:bool}>
     */
    public function forChannel(User $user, string $channel): array
    {
        $overrides = NotificationPreference::query()
            ->where('user_id', $user->id)
            ->where('channel', $channel)
            ->pluck('enabled', 'category');

        $categories = [
            ...NotificationCatalog::mailableCategories(),
            ...NotificationCatalog::mandatoryCategories(),
        ];

        return array_map(fn (string $category): array => [
            'category' => $category,
            'channel' => $channel,
            'enabled' => NotificationCatalog::isMandatoryCategory($category)
                ? true
                : (bool) ($overrides[$category] ?? true),
            'mandatory' => NotificationCatalog::isMandatoryCategory($category),
        ], $categories);
    }

    public function set(User $user, string $channel, string $category, bool $enabled): void
    {
        abort_if(
            NotificationCatalog::isMandatoryCategory($category),
            422,
            'Kategori keamanan akun tidak dapat dinonaktifkan.',
        );
        abort_unless(
            in_array($category, NotificationCatalog::mailableCategories(), true),
            422,
            'Kategori notifikasi tidak dikenal.',
        );

        DB::transaction(function () use ($user, $channel, $category, $enabled): void {
            NotificationPreference::query()->updateOrCreate(
                ['user_id' => $user->id, 'channel' => $channel, 'category' => $category],
                ['enabled' => $enabled],
            );
        }, 3);
    }
}
